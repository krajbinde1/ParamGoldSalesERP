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
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/manager_api.dart';

class ManagerDashboardScreen extends StatefulWidget {
  const ManagerDashboardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<ManagerDashboardScreen> createState() => _ManagerDashboardScreenState();
}

class _ManagerDashboardScreenState extends State<ManagerDashboardScreen> {
  late Future<ManagerDashboardData> _future;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<ManagerDashboardData> _load() => _api.loadDashboard(period: 'month');

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _open(String path) async {
    await context.push(path);
    if (!mounted) return;
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final employee = widget.auth.session!.employee;
    final initial = employee.fullName.trim().isNotEmpty
        ? employee.fullName.trim()[0].toUpperCase()
        : 'M';

    return Scaffold(
      backgroundColor: AppColors.background,
      body: RefreshIndicator(
        color: AppColors.primary,
        onRefresh: _reload,
        child: FutureBuilder<ManagerDashboardData>(
          future: _future,
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
                    message: errorMessage(snapshot.error),
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final data = snapshot.data!;
            final teamSize = data.employeePerformance.length;
            final salesPct = _teamSalesAchievement(data);
            final dateLabel =
                DateFormat('EEE, d MMM yyyy').format(DateTime.now());

            return CustomScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              slivers: [
                SliverToBoxAdapter(
                  child: _ManagerHeader(
                    auth: widget.auth,
                    name: employee.fullName,
                    initial: initial,
                    dateLabel: dateLabel,
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
                      _SummaryGrid(
                        pendingOrders: data.pendingOrders,
                        presentToday: data.presentToday,
                        teamSize: teamSize,
                        salesPct: salesPct,
                        onOrders: () =>
                            _open('/manager/orders?tab=pending'),
                        onTargets: () => _open('/manager/targets'),
                        onTeamAttendance: () =>
                            _open('/manager/team-attendance'),
                        onSales: () => _open('/manager/targets'),
                      ),
                      const SizedBox(height: AppSpacing.lg),
                      const PgSectionHeader(title: 'Quick Access'),
                      const SizedBox(height: AppSpacing.sm),
                      _ModuleGrid(
                        items: [
                          _ModuleItem(
                            title: 'Attendance',
                            subtitle: 'My punch in/out',
                            icon: Icons.fingerprint_rounded,
                            onTap: () => _open('/attendance'),
                          ),
                          _ModuleItem(
                            title: 'Orders',
                            subtitle: data.pendingOrders > 0
                                ? '${data.pendingOrders} pending approval'
                                : 'Review team orders',
                            icon: Icons.shopping_cart_checkout_rounded,
                            onTap: () =>
                                _open('/manager/orders?tab=pending'),
                          ),
                          _ModuleItem(
                            title: 'Collections',
                            subtitle: 'View team collections',
                            icon: Icons.payments_rounded,
                            onTap: () => _open('/manager/collections'),
                          ),
                          _ModuleItem(
                            title: 'Team Attendance',
                            subtitle: teamSize > 0
                                ? '${data.presentToday} present today'
                                : 'View team attendance',
                            icon: Icons.groups_rounded,
                            onTap: () =>
                                _open('/manager/team-attendance'),
                          ),
                          _ModuleItem(
                            title: 'Employee Route Tracking',
                            subtitle: 'Team routes & stoppages',
                            icon: Icons.route_outlined,
                            onTap: () =>
                                _open('/manager/route-tracking'),
                          ),
                          _ModuleItem(
                            title: 'Team Performance',
                            subtitle: teamSize > 0
                                ? '$teamSize employees'
                                : 'View team results',
                            icon: Icons.insights_rounded,
                            onTap: () => _open('/manager/employees'),
                          ),
                          _ModuleItem(
                            title: 'TA Approval',
                            subtitle: data.pendingClaims > 0
                                ? '${data.pendingClaims} pending'
                                : 'Review TA claims',
                            icon: Icons.receipt_long_rounded,
                            onTap: () => _open('/manager/ta-da-claims'),
                          ),
                          _ModuleItem(
                            title: 'Dealer Approvals',
                            subtitle: 'Review team dealer applications',
                            icon: Icons.assignment_turned_in_outlined,
                            onTap: () => _open('/manager/dealer-approvals'),
                          ),
                          _ModuleItem(
                            title: 'Team Activity',
                            subtitle: "Dealer visits & field activities",
                            icon: Icons.travel_explore_rounded,
                            onTap: () => _open('/manager/team-activity'),
                          ),
                          _ModuleItem(
                            title: 'Field Activities',
                            subtitle: 'Team farmer visits & recommendations',
                            icon: Icons.agriculture_outlined,
                            onTap: () => _open('/manager/field-activities'),
                          ),
                        ],
                      ),
                    ]),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  /// Prefer team employee rows already returned by the dashboard API.
  double? _teamSalesAchievement(ManagerDashboardData data) {
    var target = 0.0;
    var achieved = 0.0;
    for (final row in data.employeePerformance) {
      target += double.tryParse('${row['sales_target']}') ?? 0;
      achieved += double.tryParse('${row['sales_achieved']}') ?? 0;
    }
    if (target > 0) {
      return (achieved / target) * 100;
    }
    if (data.salesTarget > 0) return data.salesPercentage;
    return null;
  }
}

class _ManagerHeader extends StatelessWidget {
  const _ManagerHeader({
    required this.auth,
    required this.name,
    required this.initial,
    required this.dateLabel,
    this.photoUrl,
  });

  final AuthController auth;
  final String name;
  final String initial;
  final String dateLabel;
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
                  'Manager Dashboard',
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
                    photoUrl != null ? NetworkImage(photoUrl!) : null,
                child: photoUrl == null
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
                      'Role: Manager',
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

class _SummaryGrid extends StatelessWidget {
  const _SummaryGrid({
    required this.pendingOrders,
    required this.presentToday,
    required this.teamSize,
    required this.salesPct,
    required this.onOrders,
    required this.onTargets,
    required this.onTeamAttendance,
    required this.onSales,
  });

  final int pendingOrders;
  final int presentToday;
  final int teamSize;
  final double? salesPct;
  final VoidCallback onOrders;
  final VoidCallback onTargets;
  final VoidCallback onTeamAttendance;
  final VoidCallback onSales;

  @override
  Widget build(BuildContext context) {
    final teamPresentValue = teamSize > 0
        ? '$presentToday / $teamSize'
        : '$presentToday';
    final salesValue =
        salesPct != null ? '${salesPct!.round()}%' : '—';

    return LayoutBuilder(
      builder: (context, constraints) {
        final aspect = constraints.maxWidth >= 400 ? 1.85 : 1.45;
        return GridView.count(
          crossAxisCount: 2,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          mainAxisSpacing: AppSpacing.sm,
          crossAxisSpacing: AppSpacing.sm,
          childAspectRatio: aspect,
          children: [
            _SummaryCard(
              label: 'Pending Orders',
              value: '$pendingOrders',
              icon: Icons.pending_actions_rounded,
              accent: AppColors.warning,
              onTap: onOrders,
            ),
            _SummaryCard(
              label: 'Sales & Collection',
              value: 'Targets',
              icon: Icons.flag_outlined,
              accent: AppColors.accent,
              onTap: onTargets,
            ),
            _SummaryCard(
              label: 'Team Present Today',
              value: teamPresentValue,
              icon: Icons.groups_outlined,
              accent: AppColors.success,
              onTap: onTeamAttendance,
            ),
            _SummaryCard(
              label: 'Sales Achievement %',
              value: salesValue,
              icon: Icons.trending_up_rounded,
              accent: AppColors.primary,
              onTap: onSales,
            ),
          ],
        );
      },
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({
    required this.label,
    required this.value,
    required this.icon,
    required this.accent,
    required this.onTap,
    this.valueMaxLines = 1,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color accent;
  final VoidCallback onTap;
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

class _ModuleItem {
  const _ModuleItem({
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

class _ModuleGrid extends StatelessWidget {
  const _ModuleGrid({required this.items});

  final List<_ModuleItem> items;

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

class _ModuleCard extends StatelessWidget {
  const _ModuleCard({required this.item});

  final _ModuleItem item;

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
