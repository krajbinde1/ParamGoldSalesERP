import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart'
    show PgErrorState, PgLoadingState;
import '../../../core/widgets/design/pg_progress_bar.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../attendance/models/attendance.dart';
import '../../attendance/models/attendance_format.dart';
import '../../attendance/providers/attendance_provider.dart';
import '../../auth/providers/auth_controller.dart';
import '../../orders/api/order_api.dart';
import '../../orders/models/order_dashboard_data.dart';
import '../api/dashboard_api.dart';
import '../models/dashboard_data.dart';

/// Employee / Sales Person welcome dashboard.
/// Visual language matches [ManagerDashboardScreen]; data/routes stay employee-specific.
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

  Future<void> _open(String path) async {
    await context.push(path);
    if (!mounted) return;
    await _reload();
  }

  Future<void> _reload() async {
    _loadAll();
    setState(() {});
    await Future.wait([_dashboardFuture, _ordersFuture]);
  }

  String _displayAttendanceStatus({
    required String apiStatus,
    required Attendance? live,
  }) {
    if (live != null) {
      if (live.punchIn != null && live.punchOut == null) return 'Active';
      if (live.punchIn != null && live.punchOut != null) {
        return live.status.isNotEmpty ? live.status : 'Present';
      }
      return 'Not Marked';
    }
    final normalized = apiStatus.toLowerCase();
    if (normalized.contains('present') || normalized.contains('punched')) {
      return 'Present';
    }
    if (normalized.contains('active')) return 'Active';
    if (normalized.contains('absent')) return 'Absent';
    return apiStatus.isEmpty ? 'Not Marked' : apiStatus;
  }

  String _workingHoursLabel(DashboardData data, Attendance? live) {
    final liveHours = live?.workingHours?.trim();
    if (liveHours != null && liveHours.isNotEmpty) return liveHours;
    final apiHours = data.attendanceWorkingHours?.trim();
    if (apiHours != null && apiHours.isNotEmpty) return apiHours;
    if (live?.punchIn != null && live?.punchOut == null) {
      return 'Since ${AttendanceFormat.time(live!.punchIn)}';
    }
    return '—';
  }

  String _percentLabel(double value) {
    if (value == value.roundToDouble()) return '${value.toInt()}%';
    return '${value.toStringAsFixed(0)}%';
  }

  String _roleLabel(String designation) {
    final trimmed = designation.trim();
    if (trimmed.isNotEmpty) return trimmed;
    return 'Sales Person';
  }

  @override
  Widget build(BuildContext context) {
    final employee = widget.auth.session!.employee;
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 0,
    );
    final dateLabel = DateFormat('EEE, d MMM yyyy').format(DateTime.now());
    final liveAttendance = ref.watch(todayAttendanceProvider).asData?.value;
    final initial = employee.fullName.trim().isNotEmpty
        ? employee.fullName.trim()[0].toUpperCase()
        : 'E';

    // Keep employee bottom nav (Dashboard / Profile) via PgPageScaffold shell.
    return PgPageScaffold(
      auth: widget.auth,
      body: ColoredBox(
        color: AppColors.background,
        child: RefreshIndicator(
          color: AppColors.primary,
          onRefresh: () async {
            ref.invalidate(todayAttendanceProvider);
            await _reload();
          },
          child: FutureBuilder<DashboardData>(
            future: _dashboardFuture,
            builder: (context, snapshot) {
              if (snapshot.connectionState == ConnectionState.waiting &&
                  !snapshot.hasData) {
                return const PgLoadingState();
              }
              if (snapshot.hasError) {
                return ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(AppSpacing.screenPadding),
                  children: [
                    SizedBox(height: MediaQuery.paddingOf(context).top),
                    PgErrorState(
                      message: 'Unable to load dashboard.',
                      onRetry: _reload,
                    ),
                  ],
                );
              }

              final data = snapshot.data!;
              return FutureBuilder<OrderDashboardData>(
                future: _ordersFuture,
                builder: (context, orderSnap) {
                  final pendingOrders = orderSnap.data?.pendingOrders ?? 0;
                  final attendanceStatus = _displayAttendanceStatus(
                    apiStatus: data.attendanceStatus,
                    live: liveAttendance,
                  );
                  final workingHours = _workingHoursLabel(
                    data,
                    liveAttendance,
                  );

                  return CustomScrollView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    slivers: [
                      SliverToBoxAdapter(
                        child: _EmployeeHeader(
                          auth: widget.auth,
                          name: employee.fullName,
                          initial: initial,
                          dateLabel: dateLabel,
                          roleLabel: _roleLabel(employee.designation),
                          photoUrl: employee.profilePhotoUrl,
                        ),
                      ),
                      SliverPadding(
                        padding: const EdgeInsets.fromLTRB(
                          AppSpacing.screenPadding,
                          AppSpacing.md,
                          AppSpacing.screenPadding,
                          AppSpacing.xxl,
                        ),
                        sliver: SliverList(
                          delegate: SliverChildListDelegate([
                            const PgSectionHeader(title: 'Today'),
                            const SizedBox(height: AppSpacing.sm),
                            _EmployeeSummaryGrid(
                              attendanceStatus: attendanceStatus,
                              workingHours: workingHours,
                              salesPct: _percentLabel(
                                data.weeklySalesPercentage,
                              ),
                              collectionPct: _percentLabel(
                                data.weeklyCollectionPercentage,
                              ),
                              planningPending: data.todayPlanningPending,
                              planningCompleted: data.todayPlanningCompleted,
                              onAttendance: _openAttendance,
                              onPlanning: () => _open('/planning'),
                            ),
                            const SizedBox(height: AppSpacing.lg),
                            const PgSectionHeader(title: 'Work'),
                            const SizedBox(height: AppSpacing.sm),
                            _EmployeeModuleGrid(
                              items: [
                                _EmployeeModuleItem(
                                  title: 'Dealer Visit',
                                  subtitle: data.todayDealerVisits > 0
                                      ? '${data.todayDealerVisits} today'
                                      : 'Start / record visit',
                                  icon: Icons.storefront_outlined,
                                  onTap: () => _open('/dealer-visits'),
                                ),
                                _EmployeeModuleItem(
                                  title: 'Field Activity',
                                  subtitle: data.todayFieldActivities > 0
                                      ? '${data.todayFieldActivities} today'
                                      : "Add today's field work",
                                  icon: Icons.route_outlined,
                                  onTap: () => _open('/field-activities'),
                                ),
                                _EmployeeModuleItem(
                                  title: 'New Order',
                                  subtitle: 'Place dealer order',
                                  icon: Icons.add_shopping_cart_outlined,
                                  onTap: () => _open('/orders/new'),
                                ),
                                _EmployeeModuleItem(
                                  title: 'My Orders',
                                  subtitle: pendingOrders > 0
                                      ? '$pendingOrders pending'
                                      : 'Review order status',
                                  icon: Icons.list_alt_rounded,
                                  onTap: () => _open('/orders'),
                                ),
                                _EmployeeModuleItem(
                                  title: 'Collection',
                                  subtitle: 'Record payment collection',
                                  icon: Icons.payments_outlined,
                                  onTap: () => _open('/collections'),
                                ),
                                _EmployeeModuleItem(
                                  title: 'TA / DA Claim',
                                  subtitle: 'Submit expense claim',
                                  icon: Icons.receipt_long_outlined,
                                  onTap: () => _open('/ta-da-claims'),
                                ),
                              ],
                            ),
                            const SizedBox(height: AppSpacing.lg),
                            const PgSectionHeader(title: 'Performance'),
                            const SizedBox(height: AppSpacing.sm),
                            PgCard(
                              child: Column(
                                children: [
                                  PgProgressBar(
                                    label: 'Sales Target',
                                    percentage: data.weeklySalesPercentage,
                                    currentLabel: currency.format(
                                      data.weeklySalesAchieved,
                                    ),
                                    targetLabel: currency.format(
                                      data.weeklySalesTarget,
                                    ),
                                    color: AppColors.primary,
                                  ),
                                  const SizedBox(height: AppSpacing.md),
                                  PgProgressBar(
                                    label: 'Collection Target',
                                    percentage:
                                        data.weeklyCollectionPercentage,
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
                          ]),
                        ),
                      ),
                    ],
                  );
                },
              );
            },
          ),
        ),
      ),
    );
  }
}

/// Matches Manager gradient welcome header styling.
class _EmployeeHeader extends StatelessWidget {
  const _EmployeeHeader({
    required this.auth,
    required this.name,
    required this.initial,
    required this.dateLabel,
    required this.roleLabel,
    this.photoUrl,
  });

  final AuthController auth;
  final String name;
  final String initial;
  final String dateLabel;
  final String roleLabel;
  final String? photoUrl;

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;

    return Container(
      width: double.infinity,
      padding: EdgeInsets.fromLTRB(
        AppSpacing.screenPadding,
        top + AppSpacing.md,
        AppSpacing.screenPadding,
        AppSpacing.lg,
      ),
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            Color(0xFF0B4F4A),
            Color(0xFF0F766E),
            Color(0xFF14B8A6),
          ],
        ),
        borderRadius: BorderRadius.vertical(
          bottom: Radius.circular(24),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Sales Dashboard',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 0.2,
                      ),
                ),
              ),
              IconButton(
                tooltip: 'Notifications',
                onPressed: () => context.push('/notifications'),
                icon: const Icon(
                  Icons.notifications_none_rounded,
                  color: Colors.white,
                ),
              ),
              _HeaderAccountMenu(auth: auth),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          Row(
            children: [
              CircleAvatar(
                radius: 26,
                backgroundColor: Colors.white.withValues(alpha: 0.18),
                backgroundImage:
                    photoUrl != null && photoUrl!.isNotEmpty
                        ? NetworkImage(photoUrl!)
                        : null,
                child: photoUrl == null || photoUrl!.isEmpty
                    ? Text(
                        initial,
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w800,
                          fontSize: 22,
                        ),
                      )
                    : null,
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Welcome,',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: Colors.white.withValues(alpha: 0.8),
                          ),
                    ),
                    Text(
                      name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                            color: Colors.white,
                            fontWeight: FontWeight.w800,
                            height: 1.2,
                          ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Role: $roleLabel',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.labelMedium?.copyWith(
                            color: Colors.white.withValues(alpha: 0.88),
                            fontWeight: FontWeight.w600,
                          ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      dateLabel,
                      style: Theme.of(context).textTheme.labelSmall?.copyWith(
                            color: Colors.white.withValues(alpha: 0.7),
                          ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _HeaderAccountMenu extends StatelessWidget {
  const _HeaderAccountMenu({required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    return PopupMenuButton<String>(
      tooltip: 'Account',
      onSelected: (value) async {
        switch (value) {
          case 'profile':
            context.push('/profile');
          case 'password':
            context.push('/change-password');
          case 'logout':
            await auth.logout();
        }
      },
      itemBuilder: (_) => const [
        PopupMenuItem(
          value: 'profile',
          child: ListTile(
            dense: true,
            leading: Icon(Icons.person_outline),
            title: Text('My Profile'),
          ),
        ),
        PopupMenuItem(
          value: 'password',
          child: ListTile(
            dense: true,
            leading: Icon(Icons.password_outlined),
            title: Text('Change Password'),
          ),
        ),
        PopupMenuDivider(),
        PopupMenuItem(
          value: 'logout',
          child: ListTile(
            dense: true,
            leading: Icon(Icons.logout),
            title: Text('Logout'),
          ),
        ),
      ],
      child: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.16),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: Colors.white.withValues(alpha: 0.22)),
        ),
        child: const Icon(
          Icons.more_horiz_rounded,
          color: Colors.white,
        ),
      ),
    );
  }
}

class _EmployeeSummaryGrid extends StatelessWidget {
  const _EmployeeSummaryGrid({
    required this.attendanceStatus,
    required this.workingHours,
    required this.salesPct,
    required this.collectionPct,
    required this.planningPending,
    required this.planningCompleted,
    required this.onAttendance,
    required this.onPlanning,
  });

  final String attendanceStatus;
  final String workingHours;
  final String salesPct;
  final String collectionPct;
  final int planningPending;
  final int planningCompleted;
  final VoidCallback onAttendance;
  final VoidCallback onPlanning;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        // Slightly taller than Manager default so multi-line hours / ₹ fit.
        final aspect = constraints.maxWidth >= 400 ? 1.7 : 1.35;
        return Column(
          children: [
            GridView.count(
              crossAxisCount: 2,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              mainAxisSpacing: AppSpacing.sm,
              crossAxisSpacing: AppSpacing.sm,
              childAspectRatio: aspect,
              children: [
                _SummaryCard(
                  label: 'Attendance',
                  value: attendanceStatus,
                  icon: Icons.fingerprint_rounded,
                  accent: AppColors.primary,
                  onTap: onAttendance,
                ),
                _SummaryCard(
                  label: 'Working Hours',
                  value: workingHours,
                  icon: Icons.schedule_rounded,
                  accent: AppColors.info,
                  valueMaxLines: 2,
                ),
                _SummaryCard(
                  label: 'Sales',
                  value: salesPct,
                  icon: Icons.trending_up_rounded,
                  accent: AppColors.success,
                ),
                _SummaryCard(
                  label: 'Collection',
                  value: collectionPct,
                  icon: Icons.account_balance_wallet_outlined,
                  accent: AppColors.accent,
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            PgCard(
              onTap: onPlanning,
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Row(
                children: [
                  Container(
                    width: 34,
                    height: 34,
                    decoration: BoxDecoration(
                      color: AppColors.primary.withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: const Icon(
                      Icons.checklist_rounded,
                      size: 18,
                      color: AppColors.primary,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          "Today's Planning",
                          style: Theme.of(context).textTheme.titleSmall?.copyWith(
                                fontWeight: FontWeight.w800,
                              ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          '$planningPending Pending • $planningCompleted Done',
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                color: AppColors.textSecondary,
                                fontWeight: FontWeight.w600,
                              ),
                        ),
                      ],
                    ),
                  ),
                  const Icon(
                    Icons.chevron_right_rounded,
                    color: AppColors.textMuted,
                  ),
                ],
              ),
            ),
          ],
        );
      },
    );
  }
}

/// Same card pattern as Manager `_SummaryCard` (PgCard + FittedBox value).
class _SummaryCard extends StatelessWidget {
  const _SummaryCard({
    required this.label,
    required this.value,
    required this.icon,
    required this.accent,
    this.onTap,
    this.valueMaxLines = 1,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color accent;
  final VoidCallback? onTap;
  final int valueMaxLines;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      onTap: onTap,
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 34,
            height: 34,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, size: 18, color: accent),
          ),
          const Spacer(),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              value,
              maxLines: valueMaxLines,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                    height: 1.15,
                    fontSize: valueMaxLines > 1 ? 16 : null,
                  ),
            ),
          ),
          const SizedBox(height: 2),
          Text(
            label,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.labelMedium?.copyWith(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w600,
                ),
          ),
        ],
      ),
    );
  }
}

class _EmployeeModuleItem {
  const _EmployeeModuleItem({
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.onTap,
  });

  final String title;
  final String subtitle;
  final IconData icon;
  final VoidCallback onTap;
}

class _EmployeeModuleGrid extends StatelessWidget {
  const _EmployeeModuleGrid({required this.items});

  final List<_EmployeeModuleItem> items;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final aspect = constraints.maxWidth >= 400 ? 1.35 : 1.15;
        return GridView.builder(
          itemCount: items.length,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 2,
            mainAxisSpacing: AppSpacing.sm,
            crossAxisSpacing: AppSpacing.sm,
            childAspectRatio: aspect,
          ),
          itemBuilder: (context, index) {
            final item = items[index];
            return _ModuleCard(item: item);
          },
        );
      },
    );
  }
}

/// Same module card pattern as Manager `_ModuleCard`.
class _ModuleCard extends StatelessWidget {
  const _ModuleCard({required this.item});

  final _EmployeeModuleItem item;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      onTap: item.onTap,
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 46,
            height: 46,
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: AppColors.tealGradient,
              ),
              borderRadius: BorderRadius.circular(14),
              boxShadow: [
                BoxShadow(
                  color: AppColors.primary.withValues(alpha: 0.22),
                  blurRadius: 10,
                  offset: const Offset(0, 4),
                ),
              ],
            ),
            child: Icon(item.icon, color: Colors.white, size: 24),
          ),
          const Spacer(),
          Text(
            item.title,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(height: 4),
          Text(
            item.subtitle,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                  height: 1.25,
                ),
          ),
        ],
      ),
    );
  }
}
