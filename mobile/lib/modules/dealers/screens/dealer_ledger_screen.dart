import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/auth/user_role.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/utils/bill_document.dart';
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
  int? _openingDocumentId;

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

  String? _documentRoute(DealerLedgerEntry entry) {
    final id = entry.documentId;
    if (id == null || id <= 0) return null;

    return switch (entry.sourceType) {
      'order' => switch (widget.auth.userRole) {
        UserRole.manager => '/manager/orders/$id',
        UserRole.director => '/director/orders/$id',
        UserRole.productionSupervisor => '/production/orders/$id',
        UserRole.employee => '/orders/$id',
      },
      'collection' => switch (widget.auth.userRole) {
        UserRole.manager => '/manager/collections/$id',
        UserRole.director => '/director/collections/$id',
        _ => '/collections/$id',
      },
      'credit_note' => switch (widget.auth.userRole) {
        UserRole.manager => '/manager/credit-notes/$id',
        UserRole.productionSupervisor => '/production/credit-notes/$id',
        UserRole.employee => '/credit-notes/$id',
        UserRole.director => null,
      },
      _ => null,
    };
  }

  Future<void> _openEntry(DealerLedgerEntry entry) async {
    if (!entry.isClickable || _openingDocumentId != null) return;

    final url = entry.documentUrl?.trim() ?? '';
    final openInvoicePdf =
        entry.transactionType == 'sales_invoice' || entry.sourceType == 'order';
    if (openInvoicePdf && url.isNotEmpty) {
      await openBillDocument(context, url: url, title: 'Sales Invoice');
      return;
    }

    final route = _documentRoute(entry);
    if (route != null) {
      setState(() => _openingDocumentId = entry.documentId);
      try {
        await context.push(route);
      } catch (error) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Unable to open this document: $error')),
        );
      } finally {
        if (mounted) setState(() => _openingDocumentId = null);
      }
      return;
    }

    if (url.isNotEmpty) {
      await openBillDocument(
        context,
        url: url,
        title: entry.transactionType == 'payment_received'
            ? 'Payment Receipt'
            : 'Credit Note',
      );
      return;
    }

    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          entry.unavailableReason ?? 'This document is no longer available.',
        ),
      ),
    );
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
                const SizedBox(height: AppSpacing.sm),
                _CreditLimitCard(summary: summary, money: _inr),
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
                          if ((entry.reference ?? '').isNotEmpty ||
                              entry.isClickable)
                            _LedgerReference(
                              label: (entry.reference ?? '').isNotEmpty
                                  ? entry.reference!
                                  : 'View document',
                              clickable: entry.isClickable,
                              loading:
                                  entry.isClickable &&
                                  entry.documentId != null &&
                                  _openingDocumentId == entry.documentId,
                              onTap: () => _openEntry(entry),
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

class _LedgerReference extends StatelessWidget {
  const _LedgerReference({
    required this.label,
    required this.clickable,
    required this.loading,
    required this.onTap,
  });

  final String label;
  final bool clickable;
  final bool loading;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final text = Text(
      label,
      style: Theme.of(context).textTheme.bodySmall?.copyWith(
        color: clickable ? AppColors.primary : null,
        fontWeight: clickable ? FontWeight.w700 : null,
        decoration: clickable ? TextDecoration.underline : null,
      ),
    );

    if (!clickable) return text;

    return InkWell(
      onTap: loading ? null : onTap,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Flexible(child: text),
          if (loading) ...[
            const SizedBox(width: 8),
            const SizedBox(
              width: 14,
              height: 14,
              child: CircularProgressIndicator(strokeWidth: 2),
            ),
          ],
        ],
      ),
    );
  }
}

class _CreditLimitCard extends StatelessWidget {
  const _CreditLimitCard({required this.summary, required this.money});

  final DealerAccountSummary summary;
  final NumberFormat money;

  String _amount(double? value) {
    if (!summary.creditLimitSet || value == null) return 'Not Set';
    return money.format(value);
  }

  @override
  Widget build(BuildContext context) {
    return PgCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            summary.creditLimitSet
                ? 'Credit Limit'
                : 'Credit Limit: Not Set',
            style: const TextStyle(fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 8),
          Text('Outstanding ${money.format(summary.currentOutstanding)}'),
          Text('Base ${_amount(summary.creditBaseLimit)}'),
          Text('Extension ${money.format(summary.creditExtensionAmount)}'),
          Text('Effective ${_amount(summary.creditEffectiveLimit)}'),
          Text('Available ${_amount(summary.creditAvailableLimit)}'),
        ],
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
