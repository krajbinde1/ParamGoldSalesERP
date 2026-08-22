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
import '../api/dealer_account_api.dart';
import '../models/dealer_account.dart';

final _inr = NumberFormat.currency(locale: 'en_IN', symbol: '₹', decimalDigits: 0);

class DealerListScreen extends StatefulWidget {
  const DealerListScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<DealerListScreen> createState() => _DealerListScreenState();
}

class _DealerListScreenState extends State<DealerListScreen> {
  late Future<List<DealerAccountListItem>> _future;
  String _query = '';

  DealerAccountApi get _api => DealerAccountApi(
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

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      title: 'Dealer Accounts',
      showBack: true,
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<List<DealerAccountListItem>>(
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

            final dealers = snapshot.data ?? const <DealerAccountListItem>[];
            final filtered = _query.trim().isEmpty
                ? dealers
                : dealers.where((dealer) {
                    final haystack =
                        '${dealer.firmName} ${dealer.dealerCode} ${dealer.ownerName ?? ''} ${dealer.village ?? ''}'
                            .toLowerCase();
                    return haystack.contains(_query.trim().toLowerCase());
                  }).toList();

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                TextField(
                  decoration: const InputDecoration(
                    hintText: 'Search dealer name or code',
                    prefixIcon: Icon(Icons.search_rounded),
                  ),
                  onChanged: (value) => setState(() => _query = value),
                ),
                const SizedBox(height: AppSpacing.md),
                if (filtered.isEmpty)
                  const PgEmptyState(message: 'No dealers found.')
                else
                  ...filtered.map(
                    (dealer) => Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: PgCard(
                        onTap: () => context.push('/dealers/${dealer.id}'),
                        child: Row(
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    dealer.firmName,
                                    style: Theme.of(context).textTheme.titleMedium,
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    [
                                      dealer.dealerCode,
                                      if ((dealer.ownerName ?? '').isNotEmpty)
                                        dealer.ownerName,
                                      if ((dealer.village ?? '').isNotEmpty)
                                        dealer.village,
                                    ].join(' · '),
                                    style: Theme.of(context).textTheme.bodySmall
                                        ?.copyWith(color: AppColors.textSecondary),
                                  ),
                                ],
                              ),
                            ),
                            Text(
                              _inr.format(dealer.currentOutstanding),
                              style: Theme.of(context).textTheme.titleMedium
                                  ?.copyWith(
                                    color: AppColors.error,
                                    fontWeight: FontWeight.w700,
                                  ),
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
