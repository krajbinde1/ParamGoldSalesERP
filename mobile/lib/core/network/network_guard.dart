import 'dart:io';

/// Lightweight online check for gating inward/production posting.
class NetworkGuard {
  const NetworkGuard._();

  static Future<bool> isOnline() async {
    try {
      final result = await InternetAddress.lookup('dns.google');
      return result.isNotEmpty && result.first.rawAddress.isNotEmpty;
    } on SocketException {
      return false;
    } catch (_) {
      return false;
    }
  }

  static const offlineMessage =
      'Internet connection is required to create or post inventory transactions.';
}
