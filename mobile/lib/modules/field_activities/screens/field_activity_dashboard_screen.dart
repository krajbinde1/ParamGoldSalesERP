import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/field_activity_api.dart';
import '../models/field_activity_dashboard_data.dart';
import '../widgets/field_activity_widgets.dart';

class FieldActivityDashboardScreen extends StatefulWidget {
  const FieldActivityDashboardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<FieldActivityDashboardScreen> createState() =>
      _FieldActivityDashboardScreenState();
}

class _FieldActivityDashboardScreenState
    extends State<FieldActivityDashboardScreen> {
  late Future<FieldActivityDashboardData> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<FieldActivityDashboardData> _load() => FieldActivityApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  ).loadDashboard();

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _openActivity(int activityId) async {
    await context.push('/field-activities/$activityId');
    if (!mounted) return;
    await _reload();
  }

  Future<void> _openNewActivity() async {
    await context.push('/field-activities/new');
    if (!mounted) return;
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      auth: widget.auth,
      title: 'Activities',
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openNewActivity,
        icon: const Icon(Icons.add_rounded),
        label: const Text('New Activity'),
      ),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<FieldActivityDashboardData>(
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
                    message: 'Unable to load field activities.',
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final data = snapshot.data ?? FieldActivityDashboardData.empty;
            final summary = data.summary;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgCard(
                  onTap: () => context.push('/dealer-visits'),
                  child: Row(
                    children: [
                      Container(
                        width: 48,
                        height: 48,
                        decoration: BoxDecoration(
                          color: AppColors.violetGradient.first
                              .withValues(alpha: 0.12),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Icon(
                          Icons.storefront_rounded,
                          color: AppColors.violetGradient.first,
                        ),
                      ),
                      const SizedBox(width: AppSpacing.md),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Dealer Visits',
                              style: Theme.of(context).textTheme.titleSmall
                                  ?.copyWith(fontWeight: FontWeight.w700),
                            ),
                            Text(
                              'View and record dealer visits',
                              style: Theme.of(context).textTheme.bodySmall
                                  ?.copyWith(color: AppColors.textSecondary),
                            ),
                          ],
                        ),
                      ),
                      const Icon(Icons.chevron_right_rounded),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                LayoutBuilder(
                  builder: (context, constraints) => GridView.count(
                    crossAxisCount: 2,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    childAspectRatio: constraints.maxWidth >= 700 ? 1.8 : 1.35,
                    mainAxisSpacing: AppSpacing.sm,
                    crossAxisSpacing: AppSpacing.sm,
                    children: [
                      FieldActivitySummaryCard(
                        label: 'Total Activities',
                        value: '${summary.totalActivities}',
                        color: AppColors.primary,
                      ),
                      FieldActivitySummaryCard(
                        label: 'This Month',
                        value: '${summary.monthActivities}',
                        color: AppColors.info,
                      ),
                      FieldActivitySummaryCard(
                        label: 'This Week',
                        value: '${summary.weekActivities}',
                        color: AppColors.secondary,
                      ),
                      FieldActivitySummaryCard(
                        label: "Today",
                        value: '${summary.todayActivities}',
                        color: AppColors.accent,
                        icon: Icons.today_rounded,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                const PgSectionHeader(title: 'Recent Field Activities'),
                if (data.recentActivities.isEmpty)
                  const PgEmptyState(
                    message: 'No field activities submitted yet.',
                    icon: Icons.route_outlined,
                  )
                else
                  ...data.recentActivities.map(
                    (activity) => RecentFieldActivityTile(
                      activity: activity,
                      onTap: activity.id == 0
                          ? null
                          : () => _openActivity(activity.id),
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
