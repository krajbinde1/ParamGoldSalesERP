import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/updates/apk_download_check.dart';

void main() {
  const zipHeader = [0x50, 0x4B, 0x03, 0x04];

  test('accepts a complete APK response', () {
    expect(
      ApkDownloadCheck.rejection(
        statusCode: 200,
        contentType: 'application/vnd.android.package-archive',
        contentLength: 96224256,
        exists: true,
        length: 96224256,
        header: zipHeader,
      ),
      isNull,
    );
  });

  test('rejects a short download when Content-Length is known', () {
    expect(
      ApkDownloadCheck.rejection(
        statusCode: 200,
        contentType: 'application/vnd.android.package-archive',
        contentLength: 96224256,
        exists: true,
        length: 1000,
        header: zipHeader,
      ),
      ApkDownloadCheck.incompleteMessage,
    );
  });

  test('rejects an HTML page saved as an apk', () {
    expect(
      ApkDownloadCheck.rejection(
        statusCode: 200,
        contentType: 'text/html; charset=UTF-8',
        contentLength: 1200,
        exists: true,
        length: 1200,
        header: [0x3C, 0x68, 0x74, 0x6D],
      ),
      ApkDownloadCheck.incompleteMessage,
    );
  });

  test('rejects a JSON error body', () {
    expect(
      ApkDownloadCheck.rejection(
        statusCode: 200,
        contentType: 'application/json',
        contentLength: 40,
        exists: true,
        length: 40,
        header: [0x7B, 0x22, 0x6D, 0x65],
      ),
      ApkDownloadCheck.incompleteMessage,
    );
  });

  test('rejects a download whose hash does not match the published apk', () {
    expect(
      ApkDownloadCheck.integrityRejection(
        length: 96224256,
        expectedSize: 96224256,
        actualSha256: 'ee593118da3aeb649413a92b5cb8de6ee58c973b994ee69edcd1c4cd45093b81',
        expectedSha256: '004daad3e9c2ac7941d51fdce75fc0349da32dd88b0a4e293d7b14e565e861a1',
      ),
      ApkDownloadCheck.verificationFailedMessage,
    );
  });

  test('accepts a download whose size and hash match', () {
    expect(
      ApkDownloadCheck.integrityRejection(
        length: 96224256,
        expectedSize: 96224256,
        actualSha256: '004DAAD3E9C2AC7941D51FDCE75FC0349DA32DD88B0A4E293D7B14E565E861A1',
        expectedSha256: '004daad3e9c2ac7941d51fdce75fc0349da32dd88b0a4e293d7b14e565e861a1',
      ),
      isNull,
    );
  });

  test('rejects a non-200 response', () {
    expect(
      ApkDownloadCheck.rejection(
        statusCode: 404,
        contentType: 'application/vnd.android.package-archive',
        contentLength: 4,
        exists: true,
        length: 4,
        header: zipHeader,
      ),
      ApkDownloadCheck.incompleteMessage,
    );
  });
}
