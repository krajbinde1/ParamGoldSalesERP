import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/api/api_errors.dart';

void main() {
  group('isEmployeeInactiveResponse', () {
    test('matches the backend inactive-employee message', () {
      expect(
        isEmployeeInactiveResponse({
          'success': false,
          'message': 'This employee account is inactive.',
        }),
        isTrue,
      );
    });

    test('does not treat other 403 bodies as inactive', () {
      expect(
        isEmployeeInactiveResponse({
          'success': false,
          'message': 'You are not authorized to perform this action.',
        }),
        isFalse,
      );
      expect(isEmployeeInactiveResponse('<html>403 Forbidden</html>'), isFalse);
      expect(isEmployeeInactiveResponse(null), isFalse);
    });
  });
}
