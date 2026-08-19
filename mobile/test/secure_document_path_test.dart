import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/utils/secure_document.dart';

void main() {
  group('resolveSecureDocumentDownloadTarget', () {
    test('prefixes leading slash for legacy slashless view_path', () {
      expect(
        resolveSecureDocumentDownloadTarget(
          viewPath: 'director/payment-requests/7/supporting-documents/3',
        ),
        '/director/payment-requests/7/supporting-documents/3',
      );
    });

    test('keeps leading slash on current view_path', () {
      expect(
        resolveSecureDocumentDownloadTarget(
          viewPath: '/director/payment-requests/7/supporting-documents/3',
        ),
        '/director/payment-requests/7/supporting-documents/3',
      );
    });

    test('strips /api prefix and keeps leading slash', () {
      expect(
        resolveSecureDocumentDownloadTarget(
          viewPath: '/api/director/payment-requests/7/supporting-documents/3',
        ),
        '/director/payment-requests/7/supporting-documents/3',
      );
    });

    test('extracts relative path from absolute view_url', () {
      expect(
        resolveSecureDocumentDownloadTarget(
          viewUrl:
              'https://erp.paramgold.in/api/director/payment-requests/7/supporting-documents/3',
        ),
        '/director/payment-requests/7/supporting-documents/3',
      );
    });

    test('prefers view_path over view_url', () {
      expect(
        resolveSecureDocumentDownloadTarget(
          viewPath: '/director/payment-requests/1/supporting-documents/2',
          viewUrl:
              'https://erp.paramgold.in/api/director/payment-requests/9/supporting-documents/9',
        ),
        '/director/payment-requests/1/supporting-documents/2',
      );
    });
  });
}
