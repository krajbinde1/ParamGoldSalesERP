/// Checks that a finished download is the APK bytes, not an HTML or JSON page.
class ApkDownloadCheck {
  static const incompleteMessage =
      'Update download was incomplete. Please try again.';

  static const verificationFailedMessage =
      'Update download verification failed. Please download the update again.';

  static String? rejection({
    required int? statusCode,
    required String? contentType,
    required int? contentLength,
    required bool exists,
    required int length,
    required List<int> header,
  }) {
    if (statusCode != null && statusCode != 200) {
      return incompleteMessage;
    }

    final type = (contentType ?? '').toLowerCase();
    if (type.contains('text/html') ||
        type.contains('application/json') ||
        type.contains('text/plain')) {
      return incompleteMessage;
    }

    if (!exists || length <= 0) {
      return incompleteMessage;
    }

    if (contentLength != null && length != contentLength) {
      return incompleteMessage;
    }

    if (!_isZipLocalHeader(header)) {
      return incompleteMessage;
    }

    return null;
  }

  static String? integrityRejection({
    required int length,
    int? expectedSize,
    String? actualSha256,
    String? expectedSha256,
  }) {
    if (expectedSize != null && expectedSize > 0 && length != expectedSize) {
      return verificationFailedMessage;
    }

    final expected = expectedSha256?.trim().toLowerCase() ?? '';
    if (expected.isEmpty) {
      return null;
    }

    final actual = actualSha256?.trim().toLowerCase() ?? '';
    if (actual.isEmpty || actual != expected) {
      return verificationFailedMessage;
    }

    return null;
  }

  static bool _isZipLocalHeader(List<int> header) {
    return header.length >= 4 &&
        header[0] == 0x50 &&
        header[1] == 0x4B &&
        header[2] == 0x03 &&
        header[3] == 0x04;
  }
}
