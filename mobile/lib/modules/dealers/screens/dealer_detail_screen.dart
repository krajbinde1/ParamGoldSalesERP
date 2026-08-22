import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/dealer_account_api.dart';
import '../models/dealer_account.dart';

final _inr = NumberFormat.currency(locale: 'en_IN', symbol: '₹', decimalDigits: 0);

class DealerDetailScreen extends StatefulWidget {
  const DealerDetailScreen({
    super.key,
    required this.dealerId,
    required this.auth,
  });

  final int dealerId;
  final AuthController auth;

  @override
  State<DealerDetailScreen> createState() => _DealerDetailScreenState();
}

class _DealerDetailScreenState extends State<DealerDetailScreen> {
  late Future<DealerAccountDetail> _future;

  DealerAccountApi get _api => DealerAccountApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.show(widget.dealerId);
  }

  Future<void> _reload() async {
    setState(() => _future = _api.show(widget.dealerId));
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      title: 'Dealer Details',
      showBack: true,
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<DealerAccountDetail>(
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

            final detail = snapshot.data!;
            final summary = detail.summary;
            final asOn = summary.openingBalanceDate == null
                ? null
                : DateFormat('d MMM yyyy').format(
                    DateTime.parse(summary.openingBalanceDate!),
                  );

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgDetailHeader(
                  title: detail.firmName,
                  subtitle: detail.dealerCode,
                ),
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if ((detail.ownerName ?? '').isNotEmpty)
                        PgInvoiceRow(label: 'Owner Name', value: detail.ownerName!),
                      if ((detail.mobile ?? '').isNotEmpty)
                        PgInvoiceRow(label: 'Mobile', value: detail.mobile!),
                      if ((detail.village ?? '').isNotEmpty)
                        PgInvoiceRow(label: 'Village', value: detail.village!),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Account Summary',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      PgInvoiceRow(
                        label: 'Opening Balance',
                        value: _inr.format(summary.openingBalance),
                      ),
                      if (asOn != null)
                        PgInvoiceRow(label: 'As On Date', value: asOn),
                      PgInvoiceRow(
                        label: 'Billed Sales',
                        value: _inr.format(summary.billedSales),
                      ),
                      PgInvoiceRow(
                        label: 'Collections Received',
                        value: _inr.format(summary.collectionsReceived),
                      ),
                      PgInvoiceRow(
                        label: 'Unbilled Orders',
                        value: _inr.format(summary.unbilledOrders),
                      ),
                      PgInvoiceRow(
                        label: 'Total Exposure',
                        value: _inr.format(summary.totalExposure),
                      ),
                      const Divider(),
                      Row(
                        children: [
                          const Expanded(child: Text('Current Outstanding')),
                          Text(
                            _inr.format(summary.currentOutstanding),
                            style: Theme.of(context).textTheme.titleMedium
                                ?.copyWith(
                                  color: AppColors.error,
                                  fontWeight: FontWeight.w800,
                                ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                FilledButton.icon(
                  onPressed: () =>
                      context.push('/dealers/${detail.id}/ledger'),
                  icon: const Icon(Icons.menu_book_outlined),
                  label: const Text('Account / Ledger'),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}
