import 'package:flutter/material.dart';

import '../../../core/api/api_errors.dart';
import '../../../core/utils/order_number.dart';
import '../api/production_api.dart';

/// Returns `null` if cancelled, otherwise the optional remark (may be empty).
Future<String?> showMarkAsDispatchedDialog(
  BuildContext context, {
  String? orderNo,
}) async {
  final controller = TextEditingController();

  final confirmed = await showDialog<bool>(
    context: context,
    barrierDismissible: false,
    builder: (context) {
      return AlertDialog(
        title: const Text('Mark as Dispatched'),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if ((orderNo ?? '').trim().isNotEmpty) ...[
                Text(
                  'Order $orderNo',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
                const SizedBox(height: 12),
              ],
              TextField(
                controller: controller,
                maxLines: 3,
                decoration: const InputDecoration(
                  labelText: 'Remark (optional)',
                ),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Confirm Dispatch'),
          ),
        ],
      );
    },
  );

  final remark = controller.text.trim();
  controller.dispose();

  if (confirmed != true) return null;
  return remark;
}

Future<bool> confirmAndDispatchProductionOrder({
  required BuildContext context,
  required ProductionApi api,
  required Map<String, dynamic> order,
  bool showSuccessMessage = true,
}) async {
  final id = int.tryParse('${order['id'] ?? 0}') ?? 0;
  if (id <= 0) return false;

  final remark = await showMarkAsDispatchedDialog(
    context,
    orderNo: productionOrderListNo(order),
  );
  if (remark == null || !context.mounted) return false;

  try {
    await api.dispatchOrder(
      id,
      remark: remark.isEmpty ? null : remark,
    );
    if (showSuccessMessage && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Order marked as dispatched.')),
      );
    }
    return true;
  } catch (error) {
    if (context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    }
    return false;
  }
}
