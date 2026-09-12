import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/navigation/navigation_guard.dart';
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
  ManagerDashboardData? _data;
  Object? _error;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _reload();
  }

  Future<ManagerDashboardData> _load() => _api.loadDashboard(period: 'month');

  Future<void> _reload() async {
    try {
      final data = await _load();
      if (!mounted) return;
      setState(() {
        _data = data;
        _error = null;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
      });
    }
  }

  Future<void> _open(String path) async {
    await context.push(path);
    if (!context.mounted) return;
    await afterNavigation(context, _reload);
  }

  @override
  Widget build(BuildContext context) {
    final employee = widget.auth.session!.employee;
    final initial = employee.fullName.trim().isNotEmpty
        ? employee.fullName.trim()[0].toUpperCase()
        : 'M';
    final data = _data;
    final dateLabel = DateFormat('EEE, d MMM yyyy').format(DateTime.now());

    return Scaffold(
      backgroundColor: AppColors.background,
      body: RefreshIndicator(
        color: AppColors.primary,
        onRefresh: _reload,
        child: CustomScrollView(
          key: const PageStorageKey('manager-dashboard-scroll'),
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: [
            if (data == null && _error != null)
              SliverFillRemaining(
                hasScrollBody: false,
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.screenPadding),
                  child: PgErrorState(
                    message: errorMessage(_error),
                    onRetry: _reload,
                  ),
                ),
              )
            else if (data == null)
              const SliverFillRemaining(
                hasScrollBody: false,
                child: PgLoadingState(),
              )
            else ...[
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
                    if (data.returnedByProduction > 0) ...[
                      PgCard(
                        child: ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: const Icon(Icons.undo_rounded),
                          title: Text(
                            'Returned by Production: ${data.returnedByProduction}',
                          ),
                          subtitle: const Text(
                            'Orders waiting for manager re-approval',
                          ),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: () => _open('/manager/orders?tab=returned'),
                        ),
                      ),
                      const SizedBox(height: AppSpacing.md),
                    ],
                    _SummaryGrid(
                      pendingOrders: data.pendingOrders,
                      presentToday: data.presentToday,
                      teamSize: data.employeePerformance.length,
                      salesPct: _teamSalesAchievement(data),
                      onOrders: () => _open('/manager/orders?tab=pending'),
                      onTargets: () => _open('/manager/targets'),
                      onTeamAttendance: () => _open('/manager/team-attendance'),
                      onSales: () => _open('/manager/targets'),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    _PaymentFollowUpCard(
                      count: data.paymentFollowUpActionRequired,
                      onTap: () => _open('/manager/payment-follow-ups'),
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
                          onTap: () => _open('/manager/orders?tab=pending'),
                        ),
                        _ModuleItem(
                          title: 'Credit Notes',
                          subtitle: data.pendingCreditNotes > 0
                              ? '${data.pendingCreditNotes} pending approval'
                              : 'Review team credit notes',
                          icon: Icons.note_alt_outlined,
                          onTap: () =>
                              _open('/manager/credit-notes?tab=pending'),
                        ),
                        _ModuleItem(
                          title: 'Collections',
                          subtitle: 'View team collections',
                          icon: Icons.payments_rounded,
                          onTap: () => _open('/manager/collections'),
                        ),
                        _ModuleItem(
                          title: 'Team Attendance',
                          subtitle: data.employeePerformance.isNotEmpty
                              ? '${data.presentToday} present today'
                              : 'View team attendance',
                          icon: Icons.groups_rounded,
                          onTap: () => _open('/manager/team-attendance'),
                        ),
                        _ModuleItem(
                          title: 'Employee Route Tracking',
                          subtitle: 'Team routes & stoppages',
                          icon: Icons.route_outlined,
                          onTap: () => _open('/manager/route-tracking'),
                        ),
                        _ModuleItem(
                          title: 'Team Performance',
                          subtitle: data.employeePerformance.isNotEmpty
                              ? '${data.employeePerformance.length} employees'
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
                          title: 'Dealer Accounts',
                          subtitle: 'Team outstanding & ledger',
                          icon: Icons.account_balance_wallet_outlined,
                          onTap: () => _open('/dealers'),
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
          ],
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

class _PaymentFollowUpCard extends StatelessWidget {
  const _PaymentFollowUpCard({
    required this.count,
    required this.onTap,
  });

  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final alert = count > 0;
    return SizedBox(
      height: 132,
      child: Row(
        children: [
          Expanded(
            child: PgCard(
              onTap: onTap,
              padding: const EdgeInsets.fromLTRB(14, 13, 12, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        width: 36,
                        height: 36,
                        decoration: BoxDecoration(
                          color: AppColors.primary.withValues(alpha: 0.10),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: const Icon(
                          Icons.support_agent_outlined,
                          size: 18,
                          color: AppColors.primary,
                        ),
                      ),
                      const Spacer(),
                      Icon(
                        Icons.chevron_right_rounded,
                        size: 18,
                        color: AppColors.textMuted.withValues(alpha: 0.85),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Expanded(
                    child: ClipRect(
                      child: Align(
                        alignment: Alignment.bottomLeft,
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            FittedBox(
                              fit: BoxFit.scaleDown,
                              alignment: Alignment.centerLeft,
                              child: Text(
                                '$count',
                                maxLines: 1,
                                style: theme.textTheme.headlineSmall?.copyWith(
                                  fontWeight: FontWeight.w800,
                                  letterSpacing: -0.7,
                                  height: 1.05,
                                  fontSize: 24,
                                  color: AppColors.textPrimary,
                                ),
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              'Payment Follow-up',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.labelSmall?.copyWith(
                                color: AppColors.textSecondary,
                                fontWeight: FontWeight.w600,
                                fontSize: 12,
                                height: 1.15,
                              ),
                            ),
                            const SizedBox(height: 3),
                            Text(
                              'Action Required',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.labelSmall?.copyWith(
                                color: alert
                                    ? AppColors.warning
                                    : AppColors.textMuted,
                                fontWeight: FontWeight.w700,
                                fontSize: 11,
                                height: 1.1,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          const Expanded(child: SizedBox.shrink()),
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

double _twoColumnTileHeight(BuildContext context, double aspectRatio) {
  final width = MediaQuery.sizeOf(context).width - AppSpacing.screenPadding * 2;
  final tileWidth = (width - AppSpacing.sm) / 2;
  return tileWidth / aspectRatio;
}

class _TwoColumn extends StatelessWidget {
  const _TwoColumn({
    required this.children,
    required this.rowHeight,
  });

  final List<Widget> children;
  final double rowHeight;

  @override
  Widget build(BuildContext context) {
    const spacing = AppSpacing.sm;
    final rows = <Widget>[];
    for (var i = 0; i < children.length; i += 2) {
      if (i > 0) rows.add(SizedBox(height: spacing));
      final right = i + 1 < children.length ? children[i + 1] : null;
      rows.add(
        SizedBox(
          height: rowHeight,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(child: children[i]),
              SizedBox(width: spacing),
              Expanded(child: right ?? const SizedBox.shrink()),
            ],
          ),
        ),
      );
    }
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: rows,
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
    final width = MediaQuery.sizeOf(context).width;
    final aspect = width >= 400 ? 1.85 : 1.45;

    return _TwoColumn(
      rowHeight: _twoColumnTileHeight(context, aspect),
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
    final width = MediaQuery.sizeOf(context).width;
    final aspect = width >= 400 ? 1.35 : 1.15;
    return _TwoColumn(
      rowHeight: _twoColumnTileHeight(context, aspect),
      children: [for (final item in items) _ModuleCard(item: item)],
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
