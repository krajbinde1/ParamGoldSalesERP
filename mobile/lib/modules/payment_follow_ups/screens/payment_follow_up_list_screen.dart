import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/payment_follow_up_api.dart';
import '../models/payment_follow_up.dart';

final _date = DateFormat('dd MMM yyyy');

class PaymentFollowUpListScreen extends StatefulWidget {
  const PaymentFollowUpListScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<PaymentFollowUpListScreen> createState() =>
      _PaymentFollowUpListScreenState();
}

class _PaymentFollowUpListScreenState extends State<PaymentFollowUpListScreen> {
  late Future<PaymentFollowUpListData> _future;
  String _query = '';
  String? _statusFilter;

  PaymentFollowUpApi get _api => PaymentFollowUpApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.list();
  }

  Future<void> _reload() async {
    setState(() => _future = _api.list());
    await _future;
  }

  void _toggleStatus(String status) {
    setState(() {
      _statusFilter = _statusFilter == status ? null : status;
    });
  }

  Color _statusColor(String status) {
    return switch (status) {
      'overdue' => AppColors.error,
      'due_today' => AppColors.warning,
      'upcoming' => AppColors.info,
      'closed' => AppColors.success,
      _ => AppColors.textSecondary,
    };
  }

  String _formatDate(String? value) {
    if (value == null || value.isEmpty) return '—';
    final parsed = DateTime.tryParse(value);
    return parsed == null ? value : _date.format(parsed);
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      auth: widget.auth,
      title: 'Payment Follow-up',
      showBack: true,
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<PaymentFollowUpListData>(
          future: _future,
          builder: (context, snapshot) {
            final children = <Widget>[];
            if (snapshot.connectionState == ConnectionState.waiting &&
                !snapshot.hasData) {
              children.add(const PgLoadingState());
            } else if (snapshot.hasError) {
              children.add(
                PgErrorState(
                  message: errorMessage(snapshot.error),
                  onRetry: _reload,
                ),
              );
            } else {
              final data = snapshot.data ??
                  const PaymentFollowUpListData(
                    counts: PaymentFollowUpCounts(
                      overdue: 0,
                      dueToday: 0,
                      upcoming: 0,
                      noFollowUp: 0,
                      closed: 0,
                    ),
                    dealers: [],
                  );
              final dealers = data.dealers.where((dealer) {
                if (_statusFilter != null && dealer.status != _statusFilter) {
                  return false;
                }
                if (_query.trim().isEmpty) return true;
                return dealer.dealerName.toLowerCase().contains(
                      _query.trim().toLowerCase(),
                    );
              }).toList();

              children.addAll([
                Wrap(
                  spacing: AppSpacing.sm,
                  runSpacing: AppSpacing.sm,
                  children: [
                    _CountChip(
                      label: 'Overdue',
                      value: data.counts.overdue,
                      color: AppColors.error,
                      selected: _statusFilter == 'overdue',
                      onTap: () => _toggleStatus('overdue'),
                    ),
                    _CountChip(
                      label: 'Due Today',
                      value: data.counts.dueToday,
                      color: AppColors.warning,
                      selected: _statusFilter == 'due_today',
                      onTap: () => _toggleStatus('due_today'),
                    ),
                    _CountChip(
                      label: 'Upcoming',
                      value: data.counts.upcoming,
                      color: AppColors.info,
                      selected: _statusFilter == 'upcoming',
                      onTap: () => _toggleStatus('upcoming'),
                    ),
                    _CountChip(
                      label: 'No Follow-up',
                      value: data.counts.noFollowUp,
                      color: AppColors.textSecondary,
                      selected: _statusFilter == 'no_follow_up',
                      onTap: () => _toggleStatus('no_follow_up'),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                TextField(
                  key: const ValueKey('payment-follow-up-search'),
                  decoration: const InputDecoration(
                    hintText: 'Search dealer name',
                    prefixIcon: Icon(Icons.search_rounded),
                  ),
                  onChanged: (value) => setState(() => _query = value),
                ),
                const SizedBox(height: AppSpacing.md),
                if (dealers.isEmpty)
                  PgEmptyState(
                    message: _statusFilter == null
                        ? 'No assigned dealers found.'
                        : 'No dealers in this status.',
                    icon: const Icon(Icons.storefront_outlined),
                  )
                else
                  ...dealers.map(
                    (dealer) => Padding(
                      key: ValueKey('follow-up-dealer-${dealer.dealerId}'),
                      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: PgCard(
                        onTap: () async {
                          await context.push(
                            '/payment-follow-ups/${dealer.dealerId}',
                          );
                          if (mounted) await _reload();
                        },
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: Text(
                                    dealer.dealerName,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: Theme.of(context)
                                        .textTheme
                                        .titleMedium,
                                  ),
                                ),
                                const SizedBox(width: 8),
                                Text(
                                  dealer.statusLabel,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: Theme.of(context).textTheme.labelLarge
                                      ?.copyWith(
                                        color: _statusColor(dealer.status),
                                        fontWeight: FontWeight.w700,
                                      ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 4),
                            Text(
                              [
                                if ((dealer.village ?? '').isNotEmpty)
                                  dealer.village,
                                dealer.currentOutstandingLabel,
                              ].join(' · '),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: Theme.of(context).textTheme.bodySmall
                                  ?.copyWith(color: AppColors.textSecondary),
                            ),
                            const SizedBox(height: 6),
                            Text(
                              'Last: ${_formatDate(dealer.lastFollowUpDate)}  ·  Next: ${_formatDate(dealer.nextFollowUpDate)}',
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
              ]);
            }

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: children,
            );
          },
        ),
      ),
    );
  }
}

class _CountChip extends StatelessWidget {
  const _CountChip({
    required this.label,
    required this.value,
    required this.color,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final int value;
  final Color color;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected ? color : color.withValues(alpha: 0.12),
      shape: StadiumBorder(
        side: BorderSide(color: color, width: selected ? 1.5 : 1),
      ),
      child: InkWell(
        onTap: onTap,
        customBorder: const StadiumBorder(),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Text(
            '$label $value',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.labelLarge?.copyWith(
                  color: selected ? Colors.white : color,
                  fontWeight: FontWeight.w800,
                ),
          ),
        ),
      ),
    );
  }
}
