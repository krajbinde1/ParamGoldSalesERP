import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/modules/auth/providers/auth_controller.dart';

void main() {
  test('accepts a valid employee mobile number', () {
    expect(LoginValidators.mobile('9145433002'), isNull);
  });

  test('rejects invalid employee mobile numbers', () {
    expect(LoginValidators.mobile('914543300'), isNotNull);
    expect(LoginValidators.mobile('91454330022'), isNotNull);
    expect(LoginValidators.mobile('91454A3002'), isNotNull);
    expect(LoginValidators.mobile('5145433002'), isNotNull);
  });

  test('requires a password', () {
    expect(LoginValidators.password(''), isNotNull);
    expect(LoginValidators.password('3002'), isNull);
  });
}
