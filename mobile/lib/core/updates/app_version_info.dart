class AppVersionInfo {
  const AppVersionInfo({
    required this.latestVersion,
    required this.latestBuild,
    required this.apkUrl,
    required this.forceUpdate,
    required this.message,
  });

  final String latestVersion;
  final int latestBuild;
  final String apkUrl;
  final bool forceUpdate;
  final String message;

  static const permanentApkUrl = 'https://paramgold.in/apk/paramgold-latest.apk';

  factory AppVersionInfo.fromJson(Map<String, dynamic> json) {
    final nested = json['data'];
    final root = nested is Map
        ? Map<String, dynamic>.from(nested)
        : json;
    final url = root['apk_url']?.toString().trim();
    return AppVersionInfo(
      latestVersion: root['latest_version']?.toString() ?? '',
      latestBuild: int.tryParse('${root['latest_build'] ?? 0}') ?? 0,
      apkUrl: (url == null || url.isEmpty) ? permanentApkUrl : url,
      forceUpdate: root['force_update'] != false,
      message: root['message']?.toString() ??
          'A new version of ParamGold is available. Please update to continue.',
    );
  }

  Map<String, dynamic> toJson() => {
        'latest_version': latestVersion,
        'latest_build': latestBuild,
        'apk_url': apkUrl,
        'force_update': forceUpdate,
        'message': message,
      };
}
