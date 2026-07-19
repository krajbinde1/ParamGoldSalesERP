import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart' show PgEmptyState, PgErrorState, PgLoadingState;
import '../../../core/widgets/design/pg_metric_card.dart';
import '../../../core/widgets/design/pg_progress_bar.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../../core/widgets/design/pg_welcome_card.dart';
import '../../attendance/providers/attendance_provider.dart';
import '../../auth/providers/auth_controller.dart';
import '../../orders/api/order_api.dart';
import '../../orders/models/order_dashboard_data.dart';
import '../../orders/widgets/order_widgets.dart';
import '../api/dashboard_api.dart';
import '../models/dashboard_data.dart';

class DashboardScreen extends ConsumerStatefulWidget {
  const DashboardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  ConsumerState<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends ConsumerState<DashboardScreen> {
  late Future<DashboardData> _dashboardFuture;
  late Future<OrderDashboardData> _ordersFuture;
  bool _isFirstActivate = true;

  @override
  void initState() {
    super.initState();
    _loadAll();
  }

  void _loadAll() {
    final dio = ApiClient(
      SessionStore(),
      onUnauthorized: widget.auth.sessionExpired,
    ).dio;
    _dashboardFuture = DashboardApi(dio).load();
    _ordersFuture = OrderApi(dio).loadDashboard();
  }

  @override
  void activate() {
    super.activate();
    if (_isFirstActivate) {
      _isFirstActivate = false;
      return;
    }
    _reload();
  }

  Future<void> _openAttendance() async {
    await context.push('/attendance');
    if (!mounted) return;
    ref.invalidate(todayAttendanceProvider);
    await _reload();
  }

  Future<void> _reload() async {
    _loadAll();
    setState(() {});
    await Future.wait([_dashboardFuture, _ordersFuture]);
  }

  String _attendanceLabel(String status) {
    final normalized = status.toLowerCase();
    if (normalized.contains('present') || normalized.contains('punched')) {
      return 'Present';
    }
    if (normalized.contains('absent')) return 'Absent';
    return status.isEmpty ? 'Not Marked' : status;
  }

  @override
  Widget build(BuildContext context) {
    final employee = widget.auth.session!.employee;
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 0,
    );
    final dateLabel = DateFormat('EEEE, d MMM yyyy').format(DateTime.now());

    return PgPageScaffold(
      auth: widget.auth,
      title: 'Dashboard',
      body: RefreshIndicator(
        onRefresh: _reload,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.screenPadding),
          children: [
            PgWelcomeCard(
              name: employee.fullName,
              dateLabel: dateLabel,
              photoUrl: employee.profilePhotoUrl,
              role: employee.designation,
            ),
            const SizedBox(height: AppSpacing.lg),
            FutureBuilder<DashboardData>(
              future: _dashboardFuture,
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting &&
                    !snapshot.hasData) {
                  return const PgLoadingState();
                }
                if (snapshot.hasError) {
                  return PgErrorState(
                    message: 'Unable to load dashboard.',
                    onRetry: _reload,
                  );
                }
                final data = snapshot.data!;
                return FutureBuilder<OrderDashboardData>(
                  future: _ordersFuture,
                  builder: (context, orderSnap) {
                    final pendingOrders = orderSnap.data?.pendingOrders ?? 0;
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        LayoutBuilder(
                          builder: (context, constraints) {
                            final aspectRatio =
                                constraints.maxWidth >= 700 ? 1.6 : 1.15;
                            return GridView.count(
                              crossAxisCount: 2,
                              shrinkWrap: true,
                              physics: const NeverScrollableScrollPhysics(),
                              childAspectRatio: aspectRatio,
                              mainAxisSpacing: AppSpacing.sm,
                              crossAxisSpacing: AppSpacing.sm,
                              children: [
                                SizedBox(
                                  height: 120,
                                  child: PgMetricCard(
                                    title: 'Attendance',
                                    value: _attendanceLabel(
                                      data.attendanceStatus,
                                    ),
                                    icon: Icons.fingerprint_rounded,
                                    gradient: AppColors.tealGradient,
                                    onTap: _openAttendance,
                                  ),
                                ),
                                SizedBox(
                                  height: 120,
                                  child: PgMetricCard(
                                    title: 'Weekly Sales',
                                    value: currency.format(
                                      data.weeklySalesAchieved,
                                    ),
                                    icon: Icons.trending_up_rounded,
                                    gradient: AppColors.greenGradient,
                                    subtitle:
                                        'Target ${currency.format(data.weeklySalesTarget)}',
                                    onTap: () => context.push('/orders'),
                                  ),
                                ),
                                SizedBox(
                                  height: 120,
                                  child: PgMetricCard(
                                    title: 'Weekly Collection',
                                    value: currency.format(
                                      data.weeklyCollectionAchieved,
                                    ),
                                    icon: Icons.payments_rounded,
                                    gradient: AppColors.amberGradient,
                                    subtitle:
                                        'Target ${currency.format(data.weeklyCollectionTarget)}',
                                    onTap: () => context.push('/collections'),
                                  ),
                                ),
                                SizedBox(
                                  height: 120,
                                  child: PgMetricCard(
                                    title: 'Pending Orders',
                                    value: '$pendingOrders',
                                    icon: Icons.pending_actions_rounded,
                                    gradient: AppColors.blueGradient,
                                    onTap: () => context.push('/orders'),
                                  ),
                                ),
                              ],
                            );
                          },
                        ),
                        const SizedBox(height: AppSpacing.md),
                        LayoutBuilder(
                          builder: (context, constraints) {
                            final aspectRatio =
                                constraints.maxWidth >= 700 ? 1.8 : 1.35;
                            return GridView.count(
                              crossAxisCount: 2,
                              shrinkWrap: true,
                              physics: const NeverScrollableScrollPhysics(),
                              childAspectRatio: aspectRatio,
                              mainAxisSpacing: AppSpacing.sm,
                              crossAxisSpacing: AppSpacing.sm,
                              children: [
                                SizedBox(
                                  height: 100,
                                  child: PgMetricCard(
                                    title: 'Dealer Visits Today',
                                    value: '${data.todayDealerVisits}',
                                    icon: Icons.storefront_rounded,
                                    gradient: AppColors.violetGradient,
                                    onTap: () =>
                                        context.push('/dealer-visits'),
                                  ),
                                ),
                                SizedBox(
                                  height: 100,
                                  child: PgMetricCard(
                                    title: 'Field Activities Today',
                                    value: '${data.todayFieldActivities}',
                                    icon: Icons.route_rounded,
                                    gradient: AppColors.cyanGradient,
                                    onTap: () =>
                                        context.push('/field-activities'),
                                  ),
                                ),
                                SizedBox(
                                  height: 100,
                                  child: PgMetricCard(
                                    title: 'TA/DA Claims',
                                    value: 'View',
                                    icon: Icons.receipt_long_rounded,
                                    gradient: AppColors.indigoGradient,
                                    onTap: () =>
                                        context.push('/ta-da-claims'),
                                  ),
                                ),
                                SizedBox(
                                  height: 100,
                                  child: PgMetricCard(
                                    title: 'Sales Target %',
                                    value:
                                        '${data.weeklySalesPercentage.toStringAsFixed(0)}%',
                                    icon: Icons.flag_rounded,
                                    gradient: AppColors.roseGradient,
                                  ),
                                ),
                              ],
                            );
                          },
                        ),
                        const SizedBox(height: AppSpacing.lg),
                        PgCard(
                          child: Column(
                            children: [
                              PgProgressBar(
                                label: 'Weekly Sales Target',
                                percentage: data.weeklySalesPercentage,
                                currentLabel: currency.format(
                                  data.weeklySalesAchieved,
                                ),
                                targetLabel: currency.format(
                                  data.weeklySalesTarget,
                                ),
                                color: AppColors.secondary,
                              ),
                              const SizedBox(height: AppSpacing.lg),
                              PgProgressBar(
                                label: 'Weekly Collection Target',
                                percentage: data.weeklyCollectionPercentage,
                                currentLabel: currency.format(
                                  data.weeklyCollectionAchieved,
                                ),
                                targetLabel: currency.format(
                                  data.weeklyCollectionTarget,
                                ),
                                color: AppColors.accent,
                              ),
                            ],
                          ),
                        ),
                      ],
                    );
                  },
                );
              },
            ),
            const SizedBox(height: AppSpacing.lg),
            const PgSectionHeader(title: 'Quick Actions'),
            Wrap(
              spacing: AppSpacing.lg,
              runSpacing: AppSpacing.lg,
              alignment: WrapAlignment.spaceEvenly,
              children: [
                PgQuickAction(
                  icon: Icons.fingerprint_rounded,
                  label: 'Attendance',
                  color: AppColors.primary,
                  onTap: _openAttendance,
                ),
                PgQuickAction(
                  icon: Icons.shopping_cart_outlined,
                  label: 'Order',
                  color: AppColors.secondary,
                  onTap: () async {
                    await context.push('/orders');
                    if (mounted) await _reload();
                  },
                ),
                PgQuickAction(
                  icon: Icons.storefront_outlined,
                  label: 'Dealer Visit',
                  color: AppColors.violetGradient.first,
                  onTap: () => context.push('/dealer-visits'),
                ),
                PgQuickAction(
                  icon: Icons.route_outlined,
                  label: 'Field Activity',
                  color: AppColors.info,
                  onTap: () => context.push('/field-activities'),
                ),
                PgQuickAction(
                  icon: Icons.receipt_long_outlined,
                  label: 'TA/DA',
                  color: AppColors.indigoGradient.first,
                  onTap: () => context.push('/ta-da-claims'),
                ),
                PgQuickAction(
                  icon: Icons.payments_outlined,
                  label: 'Collection',
                  color: AppColors.accent,
                  onTap: () => context.push('/collections'),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.lg),
            PgSectionHeader(
              title: 'Recent Orders',
              actionLabel: 'View all',
              onAction: () => context.push('/orders'),
            ),
            FutureBuilder<OrderDashboardData>(
              future: _ordersFuture,
              builder: (context, snapshot) {
                final orders = snapshot.data?.recentOrders ?? [];
                if (orders.isEmpty) {
                  return const PgEmptyState(
                    message: 'No recent orders found.',
                    icon: Icons.receipt_long_outlined,
                  );
                }
                return Column(
                  children: orders.take(5).map((order) {
                    return RecentOrderTile(
                      order: order,
                      onTap: order.id == null
                          ? null
                          : () => context.push('/orders/${order.id}'),
                    );
                  }).toList(),
                );
              },
            ),
            const SizedBox(height: AppSpacing.xl),
          ],
        ),
      ),
    );
  }
}
