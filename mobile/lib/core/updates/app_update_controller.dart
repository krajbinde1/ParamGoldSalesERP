import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/scheduler.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:path_provider/path_provider.dart';

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

  String get latestVersion => latest?.latestVersion ?? '';
  String get message =>
      latest?.message ??
      'A new version of ParamGold is available. Please update to continue.';
  String get apkUrl => latest?.apkUrl ?? AppVersionInfo.permanentApkUrl;

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

  Future<void> initialize() async {
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

  Future<void> retryCheck() => initialize();

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

    final existing = _downloadedPath;
    if (existing != null && File(existing).existsSync()) {
      try {
        await _installer.installApk(existing);
        downloadState = AppUpdateDownloadState.ready;
        _notify();
      } on ApkInstallException catch (error) {
        downloadState = AppUpdateDownloadState.failed;
        downloadError = error.message;
        _notify();
      }
      return;
    }

    try {
      downloadState = AppUpdateDownloadState.downloading;
      downloadProgress = 0;
      _notify();

      final path = await _downloadApk();
      _downloadedPath = path;
      downloadProgress = 1;
      downloadState = AppUpdateDownloadState.ready;
      _notify();
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
    if (!await _installer.canInstallPackages()) return;
    permissionHint = null;
    try {
      await _installer.installApk(existing);
    } on ApkInstallException catch (error) {
      downloadError = error.message;
      downloadState = AppUpdateDownloadState.failed;
      _notify();
    }
  }

  Future<String> _downloadApk() async {
    final dir = await getTemporaryDirectory();
    final folder = Directory('${dir.path}/updates');
    if (!await folder.exists()) {
      await folder.create(recursive: true);
    }
    final file = File('${folder.path}/paramgold-latest.apk');
    if (await file.exists()) {
      await file.delete();
    }

    await _downloadDio.download(
      apkUrl,
      file.path,
      onReceiveProgress: (received, total) {
        if (total > 0) {
          downloadProgress = (received / total).clamp(0.0, 1.0);
        } else {
          downloadProgress = 0;
        }
        _notify();
      },
    );

    if (!await file.exists() || await file.length() < 1024) {
      throw const ApkInstallException(
        'Update download failed. Please check your internet connection and try again.',
      );
    }
    return file.path;
  }
}
