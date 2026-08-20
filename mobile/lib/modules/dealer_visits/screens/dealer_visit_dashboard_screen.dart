import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/dealer_visit_api.dart';
import '../models/dealer_visit_dashboard_data.dart';
import '../widgets/dealer_visit_widgets.dart';

class DealerVisitDashboardScreen extends StatefulWidget {
  const DealerVisitDashboardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<DealerVisitDashboardScreen> createState() =>
      _DealerVisitDashboardScreenState();
}

class _DealerVisitDashboardScreenState
    extends State<DealerVisitDashboardScreen> {
  late Future<DealerVisitDashboardData> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<DealerVisitDashboardData> _load() => DealerVisitApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  ).loadDashboard();

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _openVisit(int visitId) async {
    await context.push('/dealer-visits/$visitId');
    if (!mounted) return;
    await _reload();
  }

  Future<void> _openNewVisit() async {
    await context.push('/dealer-visits/new');
    if (!mounted) return;
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      auth: widget.auth,
      title: 'Dealer Visit',
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openNewVisit,
        icon: const Icon(Icons.add_rounded),
        label: const Text(
          'New Visit',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
      ),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<DealerVisitDashboardData>(
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
                    message: 'Unable to load dealer visits.',
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final data = snapshot.data ?? DealerVisitDashboardData.empty;
            final summary = data.summary;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                DealerVisitSummaryGrid(
                  children: [
                    DealerVisitSummaryCard(
                      label: 'Total Dealer Visits',
                      value: '${summary.totalVisits}',
                      color: AppColors.primary,
                    ),
                    DealerVisitSummaryCard(
                      label: 'This Week Dealer Visits',
                      value: '${summary.weekVisits}',
                      color: AppColors.info,
                    ),
                    DealerVisitSummaryCard(
                      label: "Today's Dealer Visits",
                      value: '${summary.todayVisits}',
                      color: AppColors.secondary,
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.lg),
                const PgSectionHeader(title: 'Recent Dealer Visits'),
                if (data.recentVisits.isEmpty)
                  const PgEmptyState(
                    message: 'No dealer visits submitted yet.',
                    icon: const Icon(Icons.storefront_outlined),
                  )
                else
                  ...data.recentVisits.map(
                    (visit) => RecentDealerVisitTile(
                      visit: visit,
                      onTap: visit.id == 0 ? null : () => _openVisit(visit.id),
                    ),
                  ),
                const SizedBox(height: 80),
              ],
            );
          },
        ),
      ),
    );
  }
}
