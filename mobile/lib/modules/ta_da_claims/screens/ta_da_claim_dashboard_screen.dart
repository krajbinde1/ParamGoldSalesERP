import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/ta_da_claim_api.dart';
import '../models/ta_da_claim_dashboard_data.dart';
import '../widgets/ta_da_claim_widgets.dart';

class TaDaClaimDashboardScreen extends StatefulWidget {
  const TaDaClaimDashboardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<TaDaClaimDashboardScreen> createState() =>
      _TaDaClaimDashboardScreenState();
}

class _TaDaClaimDashboardScreenState extends State<TaDaClaimDashboardScreen> {
  late Future<TaDaClaimDashboardData> _future;

  TaDaClaimApi get _api => TaDaClaimApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _loadDashboard();
  }

  Future<TaDaClaimDashboardData> _loadDashboard() => _api.loadDashboard();

  Future<void> _reload() async {
    setState(() => _future = _loadDashboard());
    await _future;
  }

  Future<void> _openClaim(int claimId) async {
    await context.push('/ta-da-claims/$claimId');
    if (!mounted) return;
    await _reload();
  }

  Future<void> _openNewClaim() async {
    final result = await context.push<bool>('/ta-da-claims/new');
    if (!mounted) return;
    if (result == true) await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 0,
    );

    return PgPageScaffold(
      auth: widget.auth,
      title: 'TA/DA Claim',
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openNewClaim,
        icon: const Icon(Icons.add_rounded),
        label: const Text('New Claim'),
      ),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<TaDaClaimDashboardData>(
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
                    message: 'Unable to load TA/DA claims.',
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final data = snapshot.data ?? TaDaClaimDashboardData.empty;
            final summary = data.summary;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                LayoutBuilder(
                  builder: (context, constraints) {
                    final wide = constraints.maxWidth >= 700;
                    return GridView.count(
                      crossAxisCount: wide ? 3 : 2,
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      childAspectRatio: wide ? 2.2 : 1.45,
                      mainAxisSpacing: AppSpacing.sm,
                      crossAxisSpacing: AppSpacing.sm,
                      children: [
                        TaDaClaimSummaryCard(
                          label: 'Pending Claims',
                          value: '${summary.pendingClaims}',
                          color: AppColors.pendingFg,
                          icon: const Icon(Icons.hourglass_top_rounded),
                        ),
                        TaDaClaimSummaryCard(
                          label: 'Approved Claims',
                          value: '${summary.approvedClaims}',
                          color: AppColors.approvedFg,
                          icon: const Icon(Icons.verified_rounded),
                        ),
                        TaDaClaimSummaryCard(
                          label: 'Paid Claims',
                          value: '${summary.paidClaims}',
                          color: AppColors.paidFg,
                          icon: const Icon(Icons.payments_rounded),
                        ),
                      ],
                    );
                  },
                ),
                const SizedBox(height: AppSpacing.lg),
                const PgSectionHeader(title: 'Recent Claims'),
                if (data.recentClaims.isEmpty)
                  const PgEmptyState(
                    message: 'No TA/DA claims submitted yet.',
                    icon: Icon(Icons.receipt_long_outlined),
                  )
                else
                  ...data.recentClaims.map(
                    (claim) => RecentTaDaClaimTile(
                      claim: claim,
                      currency: currency,
                      onTap: claim.id == 0 ? null : () => _openClaim(claim.id),
                    ),
                  ),
                const SizedBox(height: 88),
              ],
            );
          },
        ),
      ),
    );
  }
}
