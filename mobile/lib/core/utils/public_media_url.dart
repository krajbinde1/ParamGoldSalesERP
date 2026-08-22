import 'api_config.dart';

/// Turns a backend media path/URL into a loadable HTTP(S) URL for Flutter.
///
/// Uses the existing API host from [ApiConfig] — never invents a separate domain.
String? resolvePublicMediaUrl(String? raw) {
  final value = raw?.trim() ?? '';
  if (value.isEmpty) return null;

  if (value.startsWith('http://') || value.startsWith('https://')) {
    final uri = Uri.tryParse(value);
    if (uri == null) return value;
    if (!_isLoopback(uri.host)) return value;

    final api = Uri.parse(ApiConfig.baseUrl);
    return uri
        .replace(
          scheme: api.scheme,
          host: api.host,
          port: api.hasPort ? api.port : null,
        )
        .toString();
  }

  if (value.contains(':\\') || value.startsWith('/home/') || value.startsWith('/var/')) {
    return null;
  }

  final api = Uri.parse(ApiConfig.baseUrl);
  final origin = '${api.scheme}://${api.host}${api.hasPort ? ':${api.port}' : ''}';
  final path = value.startsWith('/') ? value : '/$value';
  final storagePath = path.startsWith('/storage/') || path.startsWith('/api/')
      ? path
      : '/storage$path';
  return '$origin$storagePath';
}

bool _isLoopback(String host) {
  final normalized = host.toLowerCase();
  return normalized == 'localhost' ||
      normalized == '127.0.0.1' ||
      normalized == '0.0.0.0' ||
      normalized == '::1';
}
