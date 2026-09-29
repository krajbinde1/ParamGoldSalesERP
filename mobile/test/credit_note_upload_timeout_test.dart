import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;
import 'package:mobile/modules/credit_notes/api/credit_note_api.dart';
import 'package:mobile/modules/credit_notes/api/credit_note_photo.dart';
import 'package:mobile/modules/credit_notes/api/credit_note_submit_policy.dart';

void main() {
  test('credit note post uses 20/90/90 instead of the global 15s send timeout', () async {
    late RequestOptions seen;
    final dio = Dio(
      BaseOptions(
        baseUrl: 'https://erp.example',
        connectTimeout: const Duration(seconds: 10),
        sendTimeout: const Duration(seconds: 15),
        receiveTimeout: const Duration(seconds: 15),
      ),
    );
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          seen = options;
          handler.resolve(
            Response<dynamic>(
              requestOptions: options,
              statusCode: 201,
              data: const {'ok': true},
            ),
          );
        },
      ),
    );

    const clientRequestId = 'cn-same-request';
    final response = await CreditNoteApi.postForm(
      dio,
      path: '/employee/credit-notes',
      formData: FormData.fromMap({'client_request_id': clientRequestId}),
    );

    expect(response.statusCode, 201);
    expect(seen.uri.path, '/employee/credit-notes');
    expect(seen.connectTimeout, const Duration(seconds: 20));
    expect(seen.sendTimeout, const Duration(seconds: 90));
    expect(seen.receiveTimeout, const Duration(seconds: 90));
    expect(seen.sendTimeout, isNot(const Duration(seconds: 15)));
    final form = seen.data as FormData;
    expect(
      form.fields.singleWhere((field) => field.key == 'client_request_id').value,
      clientRequestId,
    );
  });

  test('send timeout retry keeps the same client_request_id', () async {
    const clientRequestId = 'cn-timeout-retry';
    final sentIds = <String>[];

    Future<void> send() async {
      sentIds.add(clientRequestId);
      if (sentIds.length == 1) {
        throw DioException(
          requestOptions: RequestOptions(path: '/employee/credit-notes'),
          type: DioExceptionType.sendTimeout,
          message:
              'The request took longer than 0:00:15.000000 to send data.',
        );
      }
    }

    try {
      await send();
    } on DioException catch (error) {
      expect(shouldReconcileCreditNoteSubmission(error), isTrue);
      await send();
    }

    expect(sentIds, [clientRequestId, clientRequestId]);
    expect(
      creditNoteFailureText(
        DioException(
          requestOptions: RequestOptions(path: '/employee/credit-notes'),
          type: DioExceptionType.sendTimeout,
          message: 'DioException [send timeout]: 0:00:15.000000',
        ),
      ),
      creditNoteSubmitFailureMessage,
    );
    expect(creditNoteSubmitFailureMessage.contains('DioException'), isFalse);
  });

  test('photo is compressed to 1440px before upload', () {
    final source = img.Image(width: 2000, height: 1000);
    img.fill(source, color: img.ColorRgb8(12, 80, 40));
    final original = Uint8List.fromList(img.encodeJpg(source, quality: 95));

    final compressed = compressCreditNotePhotoBytes(original);
    final decoded = img.decodeImage(compressed);

    expect(decoded, isNotNull);
    expect(decoded!.width, lessThanOrEqualTo(creditNotePhotoMaxDimension));
    expect(decoded.height, lessThanOrEqualTo(creditNotePhotoMaxDimension));
    expect(decoded.width, 1440);
    expect(compressed.length, lessThan(original.length));
  });
}
