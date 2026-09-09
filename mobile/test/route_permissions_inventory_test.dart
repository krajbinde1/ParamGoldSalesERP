import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/auth/user_role.dart';
import 'package:mobile/core/routing/route_permissions.dart';

void main() {
  group('Director inventory route access', () {
    test('allows inventory stock read screens', () {
      const allowed = [
        '/production/inventory',
        '/production/stock-report',
        '/production/stock-ledger',
        '/production/ledger',
        '/production/history',
        '/production/batches',
        '/production/batches/12',
        '/production/raw-materials',
        '/production/packaging-materials',
        '/production/semi-finished',
        '/production/finished-goods',
        '/production/bom',
      ];

      for (final path in allowed) {
        expect(
          RoutePermissions.canAccessPath(path, UserRole.director),
          isTrue,
          reason: path,
        );
      }
    });

    test('blocks production write and order action screens', () {
      const blocked = [
        '/production/orders',
        '/production/orders/1',
        '/production/entry',
        '/production/company-transport',
        '/production/inwards',
        '/production/inwards/new',
        '/production/raw-materials/create',
        '/production/raw-materials/3/edit',
        '/production/packaging-inwards',
      ];

      for (final path in blocked) {
        expect(
          RoutePermissions.canAccessPath(path, UserRole.director),
          isFalse,
          reason: path,
        );
      }
    });
  });
}
