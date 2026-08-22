import 'package:flutter/material.dart';
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
import '../api/dealer_account_api.dart';
import '../models/dealer_account.dart';

final _inr = NumberFormat.currency(locale: 'en_IN', symbol: '₹', decimalDigits: 0);

class DealerLedgerScreen extends StatefulWidget {
  const DealerLedgerScreen({
    super.key,
    required this.dealerId,
    required this.auth,
  });

  final int dealerId;
  final AuthController auth;

  @override
  State<DealerLedgerScreen> createState() => _DealerLedgerScreenState();
}

class _DealerLedgerScreenState extends State<DealerLedgerScreen> {
  late Future<DealerLedgerData> _future;

  DealerAccountApi get _api => DealerAccountApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.ledger(widget.dealerId);
  }

  Future<void> _reload() async {
    setState(() => _future = _api.ledger(widget.dealerId));
    await _future;
  }

  String _formatDate(String value) {
    final parsed = DateTime.tryParse(value);
    if (parsed == null) return value;
    return DateFormat('d MMM yyyy').format(parsed);
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      title: 'Dealer Ledger',
      showBack: true,
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<DealerLedgerData>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting &&
                !snapshot.hasData) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: const [PgLoadingState()],
              );
            }

            if (snapshot.hasError) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgErrorState(
                    message: errorMessage(snapshot.error),
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final data = snapshot.data!;
            final summary = data.summary;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                Text(
                  summary.dealerName,
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 4),
                Text(
                  summary.dealerCode,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  'Current Outstanding  ${_inr.format(summary.currentOutstanding)}',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    color: AppColors.error,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                Row(
                  children: [
                    Expanded(
                      child: _SummaryChip(
                        label: 'Opening Balance',
                        value: _inr.format(summary.openingBalance),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: _SummaryChip(
                        label: 'Billed Sales',
                        value: _inr.format(summary.billedSales),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.sm),
                Row(
                  children: [
                    Expanded(
                      child: _SummaryChip(
                        label: 'Collections',
                        value: _inr.format(summary.collectionsReceived),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: _SummaryChip(
                        label: 'Outstanding',
                        value: _inr.format(summary.currentOutstanding),
                        highlighted: true,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.lg),
                ...data.entries.map(
                  (entry) => Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: PgCard(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            _formatDate(entry.date),
                            style: Theme.of(context).textTheme.bodySmall
                                ?.copyWith(color: AppColors.textSecondary),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            entry.particulars,
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                          if ((entry.reference ?? '').isNotEmpty)
                            Text(
                              entry.reference!,
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          const SizedBox(height: AppSpacing.sm),
                          if (entry.debit > 0)
                            Text('Debit ${_inr.format(entry.debit)}'),
                          if (entry.credit > 0)
                            Text('Credit ${_inr.format(entry.credit)}'),
                          Text(
                            'Balance ${_inr.format(entry.balance)}',
                            style: const TextStyle(fontWeight: FontWeight.w700),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _SummaryChip extends StatelessWidget {
  const _SummaryChip({
    required this.label,
    required this.value,
    this.highlighted = false,
  });

  final String label;
  final String value;
  final bool highlighted;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: highlighted ? const Color(0xFFFEF2F2) : AppColors.surface,
        borderRadius: BorderRadius.circular(AppSpacing.radiusSm),
        border: Border.all(
          color: highlighted ? const Color(0xFFFECACA) : AppColors.border,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
              color: highlighted ? AppColors.error : AppColors.textSecondary,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            value,
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
              color: highlighted ? AppColors.error : AppColors.textPrimary,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}
