import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../../credit_limits/models/dealer_credit_status.dart';
import '../api/manager_dealer_credit_api.dart';

class ManagerDealerCreditLimitsScreen extends StatefulWidget {
  const ManagerDealerCreditLimitsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<ManagerDealerCreditLimitsScreen> createState() =>
      _ManagerDealerCreditLimitsScreenState();
}

class _ManagerDealerCreditLimitsScreenState
    extends State<ManagerDealerCreditLimitsScreen> {
  final _searchController = TextEditingController();
  final _money = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 2,
  );

  late Future<List<DealerCreditStatus>> _future;

  ManagerDealerCreditApi get _api => ManagerDealerCreditApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.list();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _reload() async {
    setState(() => _future = _api.list(search: _searchController.text));
    await _future;
  }

  String _moneyOrNotSet(double? amount, {required bool limitSet}) {
    if (!limitSet || amount == null) return 'Not Set';
    return _money.format(amount);
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      title: 'Dealer Credit Limits',
      showBack: true,
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: TextField(
              controller: _searchController,
              decoration: InputDecoration(
                labelText: 'Search dealer, employee, or district',
                prefixIcon: const Icon(Icons.search),
                border: const OutlineInputBorder(),
                suffixIcon: IconButton(
                  onPressed: _reload,
                  icon: const Icon(Icons.arrow_forward),
                ),
              ),
              onSubmitted: (_) => _reload(),
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _reload,
              child: FutureBuilder<List<DealerCreditStatus>>(
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
                  final rows = snapshot.data ?? const <DealerCreditStatus>[];
                  if (rows.isEmpty) {
                    return ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      children: const [
                        PgEmptyState(message: 'No team dealers found.'),
                      ],
                    );
                  }
                  return ListView.separated(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(AppSpacing.screenPadding),
                    itemCount: rows.length,
                    separatorBuilder: (_, _) =>
                        const SizedBox(height: AppSpacing.sm),
                    itemBuilder: (context, index) {
                      final row = rows[index];
                      return PgCard(
                        child: ListTile(
                          title: Text(row.dealerName ?? 'Dealer'),
                          subtitle: Text(
                            [
                              if ((row.employeeName ?? '').isNotEmpty)
                                row.employeeName!,
                              if ((row.district ?? '').isNotEmpty)
                                row.district!,
                              'Outstanding ${_money.format(row.currentOutstanding)}',
                              'Base ${_moneyOrNotSet(row.baseLimit, limitSet: row.limitSet)}',
                              'Available ${_moneyOrNotSet(row.availableLimit, limitSet: row.limitSet)}',
                              row.limitSet ? row.statusLabel : 'No Limit Set',
                            ].join('\n'),
                          ),
                          isThreeLine: true,
                          trailing: const Icon(Icons.chevron_right),
                          onTap: () async {
                            await context.push(
                              '/manager/dealer-credit-limits/${row.dealerId}',
                            );
                            if (!mounted) return;
                            await _reload();
                          },
                        ),
                      );
                    },
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }
}
