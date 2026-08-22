import '../api/api_config.dart';

/// Turns a backend media path/URL into a loadable HTTP(S) URL for Flutter.
///
/// Uses the existing [ApiConfig.baseUrl] host — never invents a separate domain.
String? resolvePublicMediaUrl(String? raw) {
  final value = raw?.trim() ?? '';
  if (value.isEmpty) return null;

  final origin = _apiOrigin();
  if (origin == null) return null;

  if (value.startsWith('http://') || value.startsWith('https://')) {
    final uri = Uri.tryParse(value);
    if (uri == null || uri.host.isEmpty) return value;
    if (!_isLoopback(uri.host)) return value;
    return origin.replace(path: uri.path, query: uri.hasQuery ? uri.query : null).toString();
  }

  if (value.contains(':\\') ||
      value.startsWith('/home/') ||
      value.startsWith('/var/')) {
    return null;
  }

  return origin.replace(path: _publicStoragePath(value)).toString();
}

Uri? _apiOrigin() {
  final api = Uri.tryParse(ApiConfig.baseUrl);
  if (api == null || api.host.isEmpty) return null;
  return Uri(
    scheme: api.scheme.isEmpty ? 'https' : api.scheme,
    host: api.host,
    port: api.hasPort ? api.port : null,
  );
}

/// Laravel public files are served from `/storage/...`, not `/api/storage/...`.
String _publicStoragePath(String raw) {
  var path = raw.trim();
  if (!path.startsWith('/')) {
    path = '/$path';
  }
  if (path.startsWith('/api/')) {
    path = path.substring(4);
  }
  if (path.startsWith('/storage/')) {
    return path;
  }
  return '/storage$path';
}

bool _isLoopback(String host) {
  final normalized = host.toLowerCase();
  return normalized == 'localhost' ||
      normalized == '127.0.0.1' ||
      normalized == '0.0.0.0' ||
      normalized == '::1';
}
