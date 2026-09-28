import 'dart:async';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/scheduler.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:path_provider/path_provider.dart';

import 'apk_download_check.dart';
import 'apk_installer.dart';
import 'app_update_api.dart';
import 'app_update_store.dart';
import 'app_version_info.dart';

enum AppUpdateDownloadState { idle, downloading, failed, ready }

/// Central mandatory APK update gate for every mobile role.
class AppUpdateController extends ChangeNotifier {
  AppUpdateController({
    AppUpdateApi? api,
    AppUpdateStore? store,
    ApkInstaller? installer,
  })  : _api = api ?? AppUpdateApi(),
        _store = store ?? AppUpdateStore(),
        _installer = installer ?? ApkInstaller() {
    unawaited(initialize());
  }

  final AppUpdateApi _api;
  final AppUpdateStore _store;
  final ApkInstaller _installer;
  final Dio _downloadDio = Dio(
    BaseOptions(
      connectTimeout: const Duration(seconds: 20),
      receiveTimeout: const Duration(minutes: 5),
      sendTimeout: const Duration(seconds: 20),
      followRedirects: true,
      maxRedirects: 5,
    ),
  );

  bool checking = true;
  bool required = false;
  String installedVersion = '';
  int installedBuild = 0;
  AppVersionInfo? latest;
  String? permissionHint;
  String? downloadError;
  AppUpdateDownloadState downloadState = AppUpdateDownloadState.idle;
  double downloadProgress = 0;
  String? _downloadedPath;
  Future<void>? _inFlightCheck;

  String get latestVersion => latest?.latestVersion ?? '';
  String get message =>
      latest?.message ??
      'A new version of ParamGold is available. Please update to continue.';
  String get apkUrl => latest?.apkUrl.trim() ?? '';

  void _notify() {
    if (!hasListeners) return;
    final phase = SchedulerBinding.instance.schedulerPhase;
    if (phase == SchedulerPhase.idle ||
        phase == SchedulerPhase.postFrameCallbacks) {
      notifyListeners();
      return;
    }
    SchedulerBinding.instance.addPostFrameCallback((_) {
      if (hasListeners) notifyListeners();
    });
  }

  Future<void> initialize() {
    return _runExclusiveCheck(_initialize);
  }

  Future<void> retryCheck() => initialize();

  /// Recheck `/api/app-version` after a real background → foreground return.
  /// Skips duplicate resume events and never blocks the UI with the splash gate.
  Future<void> checkOnForegroundResume() {
    if (required || downloadState == AppUpdateDownloadState.downloading) {
      return Future.value();
    }
    return _runExclusiveCheck(_checkFromForeground, skipIfBusy: true);
  }

  Future<void> _runExclusiveCheck(
    Future<void> Function() work, {
    bool skipIfBusy = false,
  }) async {
    final inFlight = _inFlightCheck;
    if (inFlight != null) {
      if (skipIfBusy) return;
      await inFlight;
      return;
    }

    final future = work();
    _inFlightCheck = future;
    try {
      await future;
    } finally {
      if (identical(_inFlightCheck, future)) {
        _inFlightCheck = null;
      }
    }
  }

  Future<void> _initialize() async {
    checking = true;
    _notify();
    try {
      if (!Platform.isAndroid) {
        required = false;
        return;
      }

      final info = await PackageInfo.fromPlatform();
      installedVersion = info.version;
      installedBuild = int.tryParse(info.buildNumber) ?? 0;

      final persisted = await _store.readConfirmed();
      if (persisted != null && installedBuild >= persisted.latestBuild) {
        await _store.clear();
      } else if (persisted != null && installedBuild < persisted.latestBuild) {
        latest = persisted;
        required = true;
        checking = false;
        _notify();
      }

      await _refreshFromApi();
    } catch (error) {
      debugPrint('App update initialize failed: $error');
      await _applyPersistedIfStillOutdated();
    } finally {
      checking = false;
      _notify();
    }
  }

  Future<void> _checkFromForeground() async {
    try {
      if (!Platform.isAndroid) return;

      if (installedBuild <= 0 || installedVersion.isEmpty) {
        final info = await PackageInfo.fromPlatform();
        installedVersion = info.version;
        installedBuild = int.tryParse(info.buildNumber) ?? 0;
      }

      final wasRequired = required;
      await _refreshFromApi();
      if (required != wasRequired) {
        _notify();
      }
    } catch (error) {
      debugPrint('App update resume check failed: $error');
    }
  }

  Future<void> _refreshFromApi() async {
    try {
      final remote = await _api.fetch();
      latest = remote;
      if (installedBuild < remote.latestBuild) {
        required = true;
        await _store.saveConfirmed(remote);
      } else {
        required = false;
        await _store.clear();
        downloadState = AppUpdateDownloadState.idle;
        downloadError = null;
        _downloadedPath = null;
      }
    } catch (error) {
      debugPrint('App version API failed: $error');
      await _applyPersistedIfStillOutdated();
    }
  }

  Future<void> _applyPersistedIfStillOutdated() async {
    final persisted = await _store.readConfirmed();
    if (persisted != null && installedBuild < persisted.latestBuild) {
      latest = persisted;
      required = true;
    }
  }

  Future<void> updateNow() async {
    if (!required || downloadState == AppUpdateDownloadState.downloading) {
      return;
    }

    permissionHint = null;
    downloadError = null;

    final canInstall = await _installer.canInstallPackages();
    if (!canInstall) {
      permissionHint =
          'Allow ParamGold to install updates, then tap Update Now again.';
      _notify();
      await _installer.openInstallPermissionSettings();
      return;
    }

    _downloadedPath = null;

    try {
      downloadState = AppUpdateDownloadState.downloading;
      downloadProgress = 0;
      _notify();

      final path = await _downloadApk();
      _downloadedPath = path;
      downloadProgress = 1;
      downloadState = AppUpdateDownloadState.ready;
      _notify();
      debugPrint('APK installer path=$path');
      await _installer.installApk(path);
    } on ApkInstallException catch (error) {
      downloadState = AppUpdateDownloadState.failed;
      downloadError = error.message;
      _notify();
    } catch (_) {
      downloadState = AppUpdateDownloadState.failed;
      downloadError =
          'Update download failed. Please check your internet connection and try again.';
      _notify();
    }
  }

  /// After returning from Install unknown apps settings, continue install if the APK is already downloaded.
  Future<void> resumeAfterSettings() async {
    if (!required || downloadState == AppUpdateDownloadState.downloading) {
      return;
    }
    final existing = _downloadedPath;
    if (existing == null || !File(existing).existsSync()) return;
    final existingFile = File(existing);
    final failure = ApkDownloadCheck.rejection(
      statusCode: 200,
      contentType: 'application/vnd.android.package-archive',
      contentLength: await existingFile.length(),
      exists: true,
      length: await existingFile.length(),
      header: await _readHeader(existingFile),
    );
    if (failure != null) {
      _downloadedPath = null;
      downloadState = AppUpdateDownloadState.failed;
      downloadError = failure;
      _notify();
      return;
    }
    if (!await _installer.canInstallPackages()) return;
    permissionHint = null;
    try {
      debugPrint('APK installer path=$existing');
      await _installer.installApk(existing);
    } on ApkInstallException catch (error) {
      downloadError = error.message;
      downloadState = AppUpdateDownloadState.failed;
      _notify();
    }
  }

  Future<String> _downloadApk() async {
    final downloadUrl = apkUrl;
    if (downloadUrl.isEmpty) {
      throw const ApkInstallException(
        'The update download address is not available. Please try again.',
      );
    }

    final build = latest?.latestBuild ?? installedBuild;
    final dir = await getTemporaryDirectory();
    final folder = Directory('${dir.path}/updates');
    if (!await folder.exists()) {
      await folder.create(recursive: true);
    }
    await _deleteStaleUpdaterFiles(folder);

    final apkFile = File('${folder.path}/paramgold-update-$build.apk');
    final partFile = File('${apkFile.path}.part');
    if (await partFile.exists()) {
      await partFile.delete();
    }

    final Response<dynamic> response;
    try {
      response = await _downloadDio.download(
        downloadUrl,
        partFile.path,
        options: Options(
          followRedirects: true,
          receiveTimeout: const Duration(minutes: 5),
          headers: {HttpHeaders.acceptEncodingHeader: 'identity'},
          validateStatus: (status) => status == 200,
        ),
        onReceiveProgress: (received, total) {
          if (total > 0) {
            downloadProgress = (received / total).clamp(0.0, 1.0);
          } else {
            downloadProgress = 0;
          }
          _notify();
        },
      );
    } on DioException catch (error) {
      await _deleteIfExists(partFile);
      final status = error.response?.statusCode;
      final type = error.response?.headers.value(Headers.contentTypeHeader);
      debugPrint(
        'APK download failed url=$downloadUrl status=$status contentType=$type',
      );
      throw const ApkInstallException(ApkDownloadCheck.incompleteMessage);
    }

    await _flushClosed(partFile);

    final exists = await partFile.exists();
    final length = exists ? await partFile.length() : 0;
    final header = exists ? await _readHeader(partFile) : const <int>[];
    final contentType = response.headers.value(Headers.contentTypeHeader);
    final contentLength = int.tryParse(
      response.headers.value(Headers.contentLengthHeader) ?? '',
    );
    final failure = ApkDownloadCheck.rejection(
      statusCode: response.statusCode,
      contentType: contentType,
      contentLength: contentLength,
      exists: exists,
      length: length,
      header: header,
    );
    if (failure != null) {
      await _deleteIfExists(partFile);
      debugPrint(
        'APK download rejected url=$downloadUrl status=${response.statusCode} '
        'contentType=$contentType contentLength=$contentLength '
        'path=${partFile.path} size=$length',
      );
      throw ApkInstallException(failure);
    }

    final expectedSha = latest?.apkSha256;
    final actualSha = (expectedSha != null && expectedSha.trim().isNotEmpty)
        ? (await sha256.bind(partFile.openRead()).first).toString()
        : null;
    final integrity = ApkDownloadCheck.integrityRejection(
      length: length,
      expectedSize: latest?.apkFileSize,
      actualSha256: actualSha,
      expectedSha256: expectedSha,
    );
    if (integrity != null) {
      await _deleteIfExists(partFile);
      debugPrint(
        'APK verification failed url=$downloadUrl path=${partFile.path} '
        'size=$length expectedSize=${latest?.apkFileSize}',
      );
      throw ApkInstallException(integrity);
    }

    if (await apkFile.exists()) {
      await apkFile.delete();
    }
    await partFile.rename(apkFile.path);
    final savedSize = await apkFile.length();
    debugPrint(
      'APK download complete url=$downloadUrl status=${response.statusCode} '
      'contentType=$contentType contentLength=$contentLength '
      'path=${apkFile.path} size=$savedSize',
    );
    return apkFile.path;
  }

  Future<void> _deleteStaleUpdaterFiles(Directory folder) async {
    await for (final entity in folder.list()) {
      if (entity is! File) continue;
      final name = entity.uri.pathSegments.last;
      final isUpdaterApk = name.startsWith('paramgold-update-') ||
          name.startsWith('paramgold-latest');
      final isApkFile = name.endsWith('.apk') || name.endsWith('.apk.part');
      if (isUpdaterApk && isApkFile) {
        await _deleteIfExists(entity);
      }
    }
  }

  Future<void> _flushClosed(File file) async {
    if (!await file.exists()) return;
    final handle = await file.open(mode: FileMode.append);
    try {
      await handle.flush();
    } finally {
      await handle.close();
    }
  }

  Future<List<int>> _readHeader(File file) async {
    final handle = await file.open();
    try {
      return await handle.read(4);
    } finally {
      await handle.close();
    }
  }

  Future<void> _deleteIfExists(File file) async {
    if (await file.exists()) {
      await file.delete();
    }
  }
}
