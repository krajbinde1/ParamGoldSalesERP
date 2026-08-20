import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/utils/order_number.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../orders/models/order.dart';

class ProductionOrderListCard extends StatelessWidget {
  const ProductionOrderListCard({
    super.key,
    required this.order,
    required this.statusKey,
    required this.currency,
    required this.dateTime,
    required this.onTap,
    this.onDispatch,
    this.showDispatchAction = false,
  });

  final Map<String, dynamic> order;
  final String statusKey;
  final NumberFormat currency;
  final DateFormat dateTime;
  final VoidCallback onTap;
  final VoidCallback? onDispatch;
  final bool showDispatchAction;

  String _formatDateTime(Object? raw) {
    if (raw == null) return '-';
    final parsed = DateTime.tryParse(raw.toString());
    if (parsed == null) return raw.toString();
    return dateTime.format(parsed.toLocal());
  }

  @override
  Widget build(BuildContext context) {
    final amount = double.tryParse('${order['grand_total'] ?? 0}') ?? 0;
    final status = order['status']?.toString() ?? '';
    final freight = double.tryParse(
      '${order['transport_amount'] ?? order['transport_freight'] ?? ''}',
    );

    return PgCard(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      onTap: showDispatchAction ? null : onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          GestureDetector(
            onTap: showDispatchAction ? onTap : null,
            behavior: HitTestBehavior.opaque,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        productionOrderListNo(order),
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                    ),
                    PgStatusBadge(
                      label: OrderStatusRules.badgeLabel(
                        status,
                        statusLabel: order['status_label']?.toString(),
                      ),
                      tone: PgStatusRules.orderTone(status),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                if (statusKey == 'dispatched') ...[
                  Text(
                    'Dealer: ${order['dealer_name'] ?? '-'}',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                  Text(
                    'Village: ${order['dealer_village'] ?? '-'}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  Text(
                    'Sales Person: ${order['employee_name'] ?? '-'}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  Text(
                    'Dispatched by: ${order['dispatched_by_name'] ?? '-'}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  Text(
                    'Dispatch Date: ${_formatDateTime(order['dispatched_at'])}',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AppColors.textSecondary,
                        ),
                  ),
                  if ((order['dispatch_remark']?.toString() ?? '').isNotEmpty)
                    Text(
                      'Remark: ${order['dispatch_remark']}',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AppColors.textSecondary,
                          ),
                    ),
                ] else if (statusKey == 'rejected') ...[
                  Text(
                    'Dealer: ${order['dealer_name'] ?? '-'}',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                  Text(
                    'Sales: ${order['employee_name'] ?? '-'}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  if ((order['rejected_by_name']?.toString() ?? '').isNotEmpty)
                    Text(
                      'Rejected by: ${order['rejected_by_name']}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  Text(
                    'Rejected: ${_formatDateTime(order['rejected_at'])}',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AppColors.textSecondary,
                        ),
                  ),
                  if ((order['rejection_remark']?.toString() ?? '').isNotEmpty)
                    Text(
                      'Reason: ${order['rejection_remark']}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                ] else ...[
                  Text(
                    'Dealer: ${order['dealer_name'] ?? '-'}',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                  Text(
                    'Sales: ${order['employee_name'] ?? '-'}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  Text(
                    'Date: ${_formatDateTime(order['order_date'] ?? order['created_at'])} • ${currency.format(amount)}',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AppColors.textSecondary,
                        ),
                  ),
                  if ((order['payment_type']?.toString() ?? '').isNotEmpty)
                    Text(
                      'Payment: ${order['payment_type']}',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AppColors.textSecondary,
                          ),
                    ),
                  if (statusKey != 'approved') ...[
                    if ((order['vehicle_number']?.toString() ?? '').isNotEmpty)
                      Text(
                        'Vehicle: ${order['vehicle_number']}',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: AppColors.textSecondary,
                            ),
                      ),
                    if (freight != null)
                      Text(
                        'Freight: ${currency.format(freight)}',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: AppColors.textSecondary,
                            ),
                      ),
                  ],
                  if (statusKey == 'sent_for_bill')
                    Text(
                      'Sent: ${_formatDateTime(order['sent_for_bill_at'])}',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AppColors.textSecondary,
                          ),
                    ),
                  if (statusKey == 'billed') ...[
                    if ((order['bill_number']?.toString() ?? '').isNotEmpty)
                      Text(
                        'Bill No: ${order['bill_number']}',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: AppColors.textSecondary,
                            ),
                      ),
                    Text(
                      'Bill Date: ${order['bill_date'] ?? '-'}',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AppColors.textSecondary,
                          ),
                    ),
                    Text(
                      'Billed: ${_formatDateTime(order['billed_at'])}',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AppColors.textSecondary,
                          ),
                    ),
                  ],
                ],
              ],
            ),
          ),
          if (showDispatchAction && onDispatch != null) ...[
            const SizedBox(height: AppSpacing.sm),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: onDispatch,
                icon: const Icon(Icons.local_shipping_outlined, size: 18),
                label: const Text('Mark as Dispatched'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
