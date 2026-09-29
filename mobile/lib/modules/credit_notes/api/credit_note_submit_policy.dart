import 'package:dio/dio.dart';

import '../../../core/api/api_errors.dart';

const String creditNoteSubmitFailureMessage =
    'Credit Note could not be submitted. Please check your network and try again.';

bool shouldReconcileCreditNoteSubmission(DioException error) {
  return error.type == DioExceptionType.sendTimeout ||
      error.type == DioExceptionType.receiveTimeout ||
      error.type == DioExceptionType.connectionTimeout ||
      error.type == DioExceptionType.connectionError ||
      error.type == DioExceptionType.unknown;
}

String creditNoteFailureText(Object error) {
  if (error is DioException &&
      (shouldReconcileCreditNoteSubmission(error) ||
          isConnectionFailure(error))) {
    return creditNoteSubmitFailureMessage;
  }

  final mapped = errorMessage(error);
  final lower = mapped.toLowerCase();
  if (mapped.contains('DioException') ||
      lower.contains('timeout') ||
      lower.contains('socketexception')) {
    return creditNoteSubmitFailureMessage;
  }

  return mapped;
}
