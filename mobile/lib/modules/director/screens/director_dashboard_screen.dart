import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/director_api.dart';

final _inr = NumberFormat.currency(locale: 'en_IN', symbol: '₹', decimalDigits: 0);

String _compactInr(double amount) {
  final sign = amount < 0 ? '-' : '';
  final abs = amount.abs();
  if (abs >= 10000000) {
    return '$sign₹${(abs / 10000000).toStringAsFixed(2)} Cr';
  }
  if (abs >= 100000) {
    return '$sign₹${(abs / 100000).toStringAsFixed(2)} L';
  }
  return _inr.format(amount);
}

/// Director sales/team lists: login role manager|employee only.
bool _isDirectorSalesTeamRole(Map<String, dynamic> row) {
  final role = '${row['role'] ?? ''}'.toLowerCase().trim();
  return role == 'manager' || role == 'employee';
}

List<Map<String, dynamic>> _salesTeamOnly(List<Map<String, dynamic>> rows) {
  return rows.where(_isDirectorSalesTeamRole).toList(growable: false);
}

String _directorGreeting() {
  final hour = DateTime.now().hour;
  if (hour < 12) return 'Good Morning';
  if (hour < 17) return 'Good Afternoon';
  return 'Good Evening';
}

class DirectorDashboardScreen extends StatefulWidget {
  const DirectorDashboardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<DirectorDashboardScreen> createState() =>
      _DirectorDashboardScreenState();
}

class _DirectorDashboardScreenState extends State<DirectorDashboardScreen> {
  late Future<DirectorDashboardData> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<DirectorDashboardData> _load() => _api.loadDashboard(period: 'month');

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
        : 'D';

    return Scaffold(
      backgroundColor: Theme.of(context).scaffoldBackgroundColor,
      body: RefreshIndicator(
        color: AppColors.primary,
        onRefresh: _reload,
        child: FutureBuilder<DirectorDashboardData>(
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
                    message: 'Unable to load dashboard',
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final data = snapshot.data!;

            return CustomScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              slivers: [
                SliverToBoxAdapter(
                  child: _DirectorHeader(
                    auth: widget.auth,
                    name: employee.fullName,
                    initial: initial,
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
                      _OverviewGrid(data: data, onOpen: _open),
                      const SizedBox(height: AppSpacing.md),
                      _AttentionSection(data: data, onOpen: _open),
                      const SizedBox(height: AppSpacing.md),
                      _MonthPerformanceSection(data: data, onOpen: _open),
                      const SizedBox(height: AppSpacing.md),
                      _TeamActivitySection(data: data, onOpen: _open),
                      const SizedBox(height: AppSpacing.md),
                      _OrderPipelineSection(data: data, onOpen: _open),
                      const SizedBox(height: AppSpacing.md),
                      _PaymentSection(data: data, onOpen: _open),
                      const SizedBox(height: AppSpacing.md),
                      _TeamPerformanceSection(data: data, onOpen: _open),
                      const SizedBox(height: AppSpacing.md),
                      _CollectionSection(data: data, onOpen: _open),
                      const SizedBox(height: AppSpacing.lg),
                      const PgSectionHeader(title: 'Modules'),
                      _ModuleList(
                        items: [
                          _ModuleItem(
                            title: 'Payment Approval',
                            subtitle: data.myPendingPayments > 0
                                ? '${data.myPendingPayments} pending my approval'
                                : 'No pending approvals',
                            icon: Icons.payments_outlined,
                            onTap: () => _open('/director/payment-requests'),
                          ),
                          _ModuleItem(
                            title: 'Order Monitoring',
                            subtitle:
                                '${data.placedOrders} pending · ${data.dispatchedOrders} dispatched',
                            icon: Icons.fact_check_outlined,
                            onTap: () => _open('/director/orders'),
                          ),
                          _ModuleItem(
                            title: 'Sales Performance',
                            subtitle:
                                '${data.salesPercentage.round()}% of ${_compactInr(data.salesTarget)} target',
                            icon: Icons.insights_rounded,
                            onTap: () => _open('/director/sales-performance'),
                          ),
                          _ModuleItem(
                            title: 'Collections',
                            subtitle: _compactInr(
                              data.monthCollection > 0
                                  ? data.monthCollection
                                  : data.collectionAchieved,
                            ),
                            icon: Icons.account_balance_wallet_outlined,
                            onTap: () => _open('/director/collections'),
                          ),
                          _ModuleItem(
                            title: 'Dealer Accounts',
                            subtitle: 'Outstanding & dealer ledger',
                            icon: Icons.storefront_outlined,
                            onTap: () => _open('/dealers'),
                          ),
                          _ModuleItem(
                            title: 'Team Activity',
                            subtitle:
                                '${data.punchedIn} punched in · ${data.dealerVisits} dealer visits',
                            icon: Icons.groups_rounded,
                            onTap: () => _open('/director/team-activity'),
                          ),
                          _ModuleItem(
                            title: 'Route Tracking',
                            subtitle: data.activeRoutes > 0
                                ? '${data.activeRoutes} active routes'
                                : 'Manager & Employee routes',
                            icon: Icons.route_outlined,
                            onTap: () => _open('/director/route-tracking'),
                          ),
                          _ModuleItem(
                            title: 'TA/DA Overview',
                            subtitle: data.pendingClaims > 0
                                ? '${data.pendingClaims} pending'
                                : 'View claims',
                            icon: Icons.receipt_long_rounded,
                            onTap: () => _open('/director/ta-da-claims'),
                          ),
                          _ModuleItem(
                            title: 'Reports',
                            subtitle: 'Sales · Collections · Orders · Activity',
                            icon: Icons.assessment_outlined,
                            onTap: () => _open('/director/reports'),
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
}

class _DirectorHeader extends StatelessWidget {
  const _DirectorHeader({
    required this.auth,
    required this.name,
    required this.initial,
    this.photoUrl,
  });

  final AuthController auth;
  final String name;
  final String initial;
  final String? photoUrl;

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;

    return Container(
      width: double.infinity,
      padding: EdgeInsets.fromLTRB(
        AppSpacing.screenPadding,
        top + AppSpacing.sm,
        AppSpacing.screenPadding,
        AppSpacing.md,
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
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(20)),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  _directorGreeting(),
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: Colors.white.withValues(alpha: 0.82),
                        fontWeight: FontWeight.w500,
                      ),
                ),
                const SizedBox(height: 2),
                Text(
                  name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w800,
                        height: 1.2,
                      ),
                ),
                const SizedBox(height: 2),
                Text(
                  'Company monitoring',
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                        color: Colors.white.withValues(alpha: 0.78),
                        fontWeight: FontWeight.w600,
                      ),
                ),
              ],
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
          _DirectorProfileMenu(
            auth: auth,
            initial: initial,
            photoUrl: photoUrl,
          ),
        ],
      ),
    );
  }
}

class _DirectorProfileMenu extends StatelessWidget {
  const _DirectorProfileMenu({
    required this.auth,
    required this.initial,
    this.photoUrl,
  });

  final AuthController auth;
  final String initial;
  final String? photoUrl;

  @override
  Widget build(BuildContext context) {
    return PopupMenuButton<String>(
      tooltip: 'Profile',
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
      child: CircleAvatar(
        radius: 18,
        backgroundColor: Colors.white.withValues(alpha: 0.2),
        backgroundImage: photoUrl != null ? NetworkImage(photoUrl!) : null,
        child: photoUrl == null
            ? Text(
                initial,
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  fontSize: 14,
                ),
              )
            : null,
      ),
    );
  }
}

class _OverviewGrid extends StatelessWidget {
  const _OverviewGrid({required this.data, required this.onOpen});

  final DirectorDashboardData data;
  final Future<void> Function(String path) onOpen;

  @override
  Widget build(BuildContext context) {
    final salesValue = data.hasMonitoring
        ? _compactInr(data.todaySales)
        : _compactInr(data.salesAchieved);
    final collectionValue = data.hasMonitoring
        ? _compactInr(data.todayCollection)
        : _compactInr(
            data.collectionAmount > 0
                ? data.collectionAmount
                : data.collectionAchieved,
          );

    final items = [
      _DashTile(
        label: data.hasMonitoring ? 'Today Sales' : 'Sales MTD',
        value: salesValue,
        icon: Icons.trending_up_rounded,
        accent: AppColors.primary,
        onTap: () => onOpen('/director/sales-performance'),
      ),
      _DashTile(
        label: data.hasMonitoring ? 'Today Collection' : 'Collection MTD',
        value: collectionValue,
        icon: Icons.account_balance_wallet_outlined,
        accent: AppColors.accent,
        onTap: () => onOpen('/director/collections'),
      ),
      _DashTile(
        label: 'Team Punch In',
        value: data.activeEmployees > 0
            ? '${data.punchedIn} / ${data.activeEmployees}'
            : '${data.punchedIn}',
        subtitle: '${data.notPunchedIn} Not Punched In',
        icon: Icons.fingerprint_rounded,
        accent: data.notPunchedIn > 0 ? AppColors.warning : AppColors.success,
        onTap: () => onOpen('/director/team-activity'),
      ),
      _DashTile(
        label: 'Dealer Visits Today',
        value: '${data.dealerVisits}',
        icon: Icons.storefront_outlined,
        accent: AppColors.primary,
        onTap: () => onOpen('/director/team-activity'),
      ),
      _DashTile(
        label: 'Pending Orders',
        value: '${data.placedOrders}',
        icon: Icons.pending_actions_rounded,
        accent: data.placedOrders > 0 ? AppColors.warning : AppColors.success,
        onTap: () => onOpen('/director/orders?status=pending_approval'),
      ),
      _DashTile(
        label: 'Payment Approval',
        value: '${data.myPendingPayments}',
        icon: Icons.payments_outlined,
        accent: data.myPendingPayments > 0
            ? AppColors.warning
            : AppColors.textSecondary,
        onTap: () => onOpen('/director/payment-requests?filter=pending'),
      ),
    ];

    return LayoutBuilder(
      builder: (context, constraints) {
        final aspect = constraints.maxWidth >= 400 ? 1.7 : 1.38;
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
          itemBuilder: (context, index) => items[index],
        );
      },
    );
  }
}

class _DashTile extends StatelessWidget {
  const _DashTile({
    required this.label,
    required this.value,
    required this.icon,
    required this.accent,
    required this.onTap,
    this.subtitle,
  });

  final String label;
  final String value;
  final String? subtitle;
  final IconData icon;
  final Color accent;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return PgCard(
      onTap: onTap,
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 28,
                height: 28,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Icon(icon, size: 15, color: accent),
              ),
              const Spacer(),
              Icon(Icons.chevron_right_rounded, size: 16, color: accent),
            ],
          ),
          const Spacer(),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.titleMedium?.copyWith(
              fontWeight: FontWeight.w800,
            ),
          ),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
              fontWeight: FontWeight.w600,
            ),
          ),
          if (subtitle != null)
            Text(
              subtitle!,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.labelSmall?.copyWith(
                color: accent,
                fontWeight: FontWeight.w700,
              ),
            ),
        ],
      ),
    );
  }
}

class _AttentionItem {
  const _AttentionItem({
    required this.label,
    required this.count,
    required this.path,
  });

  final String label;
  final int count;
  final String path;
}

class _AttentionSection extends StatelessWidget {
  const _AttentionSection({required this.data, required this.onOpen});

  final DirectorDashboardData data;
  final Future<void> Function(String path) onOpen;

  @override
  Widget build(BuildContext context) {
    final items = [
      _AttentionItem(
        label: 'Employees Not Punched In',
        count: data.notPunchedIn,
        path: '/director/team-activity',
      ),
      _AttentionItem(
        label: 'Payment Requests Pending',
        count: data.myPendingPayments,
        path: '/director/payment-requests?filter=pending',
      ),
      _AttentionItem(
        label: 'Orders Pending Approval',
        count: data.placedOrders,
        path: '/director/orders?status=pending_approval',
      ),
      _AttentionItem(
        label: 'Orders Pending Billing',
        count: data.sentForBillOrders,
        path: '/director/orders?status=pending_for_billing',
      ),
      _AttentionItem(
        label: 'Orders On Hold',
        count: data.onHoldOrders,
        path: '/director/orders?status=on_hold',
      ),
      _AttentionItem(
        label: 'Orders Returned to Manager',
        count: data.revertedOrders,
        path: '/director/orders?status=reverted_to_manager',
      ),
      _AttentionItem(
        label: 'Billed Orders Waiting for Dispatch',
        count: data.billedOrders,
        path: '/director/orders?status=billed',
      ),
      _AttentionItem(
        label: 'Employees With No Field Activity Today',
        count: data.noFieldActivityToday,
        path: '/director/team-activity',
      ),
    ].where((item) => item.count > 0).toList(growable: false);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PgSectionHeader(title: 'Attention Required'),
        if (items.isEmpty)
          PgCard(
            padding: const EdgeInsets.all(12),
            child: Text(
              'No urgent issues right now.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          )
        else
          PgCard(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Column(
              children: [
                for (var i = 0; i < items.length; i++) ...[
                  if (i > 0) const Divider(height: 1),
                  ListTile(
                    dense: true,
                    visualDensity: VisualDensity.compact,
                    contentPadding: const EdgeInsets.symmetric(horizontal: 12),
                    leading: Container(
                      width: 8,
                      height: 8,
                      decoration: BoxDecoration(
                        color: items[i].count > 5
                            ? AppColors.error
                            : AppColors.warning,
                        shape: BoxShape.circle,
                      ),
                    ),
                    title: Text(
                      items[i].label,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                    ),
                    trailing: Text(
                      '${items[i].count}',
                      style: Theme.of(context).textTheme.titleSmall?.copyWith(
                            fontWeight: FontWeight.w800,
                            color: AppColors.warning,
                          ),
                    ),
                    onTap: () => onOpen(items[i].path),
                  ),
                ],
              ],
            ),
          ),
      ],
    );
  }
}

class _MonthPerformanceSection extends StatelessWidget {
  const _MonthPerformanceSection({required this.data, required this.onOpen});

  final DirectorDashboardData data;
  final Future<void> Function(String path) onOpen;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PgSectionHeader(title: 'This Month Performance'),
        _TargetCard(
          title: 'Sales',
          target: data.salesTarget,
          achieved: data.salesAchieved,
          remaining: data.salesRemaining,
          percentage: data.salesPercentage,
          color: AppColors.primary,
          onTap: () => onOpen('/director/sales-performance'),
        ),
        const SizedBox(height: AppSpacing.sm),
        _TargetCard(
          title: 'Collection',
          target: data.collectionTarget,
          achieved: data.collectionAchieved,
          remaining: data.collectionRemaining,
          percentage: data.collectionPercentage,
          color: AppColors.accent,
          onTap: () => onOpen('/director/collections'),
        ),
      ],
    );
  }
}

class _TargetCard extends StatelessWidget {
  const _TargetCard({
    required this.title,
    required this.target,
    required this.achieved,
    required this.remaining,
    required this.percentage,
    required this.color,
    required this.onTap,
  });

  final String title;
  final double target;
  final double achieved;
  final double remaining;
  final double percentage;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final progress = (percentage / 100).clamp(0.0, 1.0);
    return PgCard(
      onTap: onTap,
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                title,
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              const Spacer(),
              Text(
                '${percentage.round()}%',
                style: theme.textTheme.titleSmall?.copyWith(
                  color: color,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(99),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 7,
              color: color,
              backgroundColor: color.withValues(alpha: 0.14),
            ),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(child: _kv('Target', _compactInr(target))),
              Expanded(child: _kv('Achieved', _compactInr(achieved))),
              Expanded(child: _kv('Remaining', _compactInr(remaining))),
            ],
          ),
        ],
      ),
    );
  }

  Widget _kv(String label, String value) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
        Text(
          value,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12),
        ),
      ],
    );
  }
}

class _TeamActivitySection extends StatelessWidget {
  const _TeamActivitySection({required this.data, required this.onOpen});

  final DirectorDashboardData data;
  final Future<void> Function(String path) onOpen;

  @override
  Widget build(BuildContext context) {
    final chips = [
      ('Active Employees', '${data.activeEmployees}', Icons.badge_outlined, '/director/employees'),
      ('Punched In', '${data.punchedIn}', Icons.fingerprint_rounded, '/director/team-activity'),
      ('Not Punched In', '${data.notPunchedIn}', Icons.person_off_outlined, '/director/team-activity'),
      ('Dealer Visits', '${data.dealerVisits}', Icons.storefront_outlined, '/director/team-activity'),
      ('Field Visits', '${data.fieldActivities}', Icons.travel_explore_rounded, '/director/team-activity'),
      ('Active Routes', '${data.activeRoutes}', Icons.route_outlined, '/director/route-tracking'),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PgSectionHeader(title: 'Team Activity Today'),
        GridView.builder(
          itemCount: chips.length,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 3,
            mainAxisSpacing: 8,
            crossAxisSpacing: 8,
            childAspectRatio: 1.15,
          ),
          itemBuilder: (context, index) {
            final chip = chips[index];
            return PgCard(
              onTap: () => onOpen(chip.$4),
              padding: const EdgeInsets.all(8),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(chip.$3, size: 16, color: AppColors.primary),
                  const SizedBox(height: 4),
                  Text(
                    chip.$2,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                  Text(
                    chip.$1,
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.labelSmall,
                  ),
                ],
              ),
            );
          },
        ),
      ],
    );
  }
}

class _OrderPipelineSection extends StatelessWidget {
  const _OrderPipelineSection({required this.data, required this.onOpen});

  final DirectorDashboardData data;
  final Future<void> Function(String path) onOpen;

  @override
  Widget build(BuildContext context) {
    final stages = [
      ('Placed', data.placedOrders, '/director/orders?status=pending_approval'),
      ('Approved', data.approvedOrders, '/director/orders?status=approved'),
      ('Sent for Bill', data.sentForBillOrders, '/director/orders?status=pending_for_billing'),
      ('Billed', data.billedOrders, '/director/orders?status=billed'),
      ('Dispatched', data.dispatchedOrders, '/director/orders?status=dispatched'),
    ];
    final extras = [
      ('On Hold', data.onHoldOrders, '/director/orders?status=on_hold'),
      ('Returned to Manager', data.revertedOrders, '/director/orders?status=reverted_to_manager'),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PgSectionHeader(title: 'Order Pipeline'),
        PgCard(
          padding: const EdgeInsets.all(12),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (var i = 0; i < stages.length; i++) ...[
                _PipelineChip(
                  label: stages[i].$1,
                  count: stages[i].$2,
                  onTap: () => onOpen(stages[i].$3),
                ),
                if (i < stages.length - 1)
                  Padding(
                    padding: const EdgeInsets.only(top: 10),
                    child: Icon(
                      Icons.arrow_forward_rounded,
                      size: 14,
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
                  ),
              ],
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        Row(
          children: [
            for (final extra in extras)
              Expanded(
                child: Padding(
                  padding: EdgeInsets.only(
                    right: extra == extras.last ? 0 : 8,
                  ),
                  child: _PipelineChip(
                    label: extra.$1,
                    count: extra.$2,
                    tone: AppColors.warning,
                    onTap: () => onOpen(extra.$3),
                  ),
                ),
              ),
          ],
        ),
      ],
    );
  }
}

class _PipelineChip extends StatelessWidget {
  const _PipelineChip({
    required this.label,
    required this.count,
    required this.onTap,
    this.tone,
  });

  final String label;
  final int count;
  final VoidCallback onTap;
  final Color? tone;

  @override
  Widget build(BuildContext context) {
    final color = tone ?? AppColors.primary;
    return Material(
      color: color.withValues(alpha: 0.08),
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '$count',
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: color,
                    ),
              ),
              Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.labelSmall,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _PaymentSection extends StatelessWidget {
  const _PaymentSection({required this.data, required this.onOpen});

  final DirectorDashboardData data;
  final Future<void> Function(String path) onOpen;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PgSectionHeader(title: 'Payment Approval'),
        PgCard(
          onTap: () => onOpen('/director/payment-requests?filter=pending'),
          padding: const EdgeInsets.all(12),
          child: Column(
            children: [
              Row(
                children: [
                  Expanded(
                    child: _payStat(
                      context,
                      'Pending My Approval',
                      '${data.myPendingPayments}',
                    ),
                  ),
                  Expanded(
                    child: _payStat(
                      context,
                      'Pending amount',
                      _compactInr(data.myPendingPaymentAmount),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: _payStat(
                      context,
                      'Pending Next Approval',
                      '${data.nextPendingPayments}',
                    ),
                  ),
                  Expanded(
                    child: _payStat(
                      context,
                      'Payment Done Today',
                      '${data.paidTodayPayments}',
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _payStat(BuildContext context, String label, String value) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.labelSmall),
        Text(
          value,
          style: Theme.of(context).textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w800,
              ),
        ),
      ],
    );
  }
}

class _TeamPerformanceSection extends StatelessWidget {
  const _TeamPerformanceSection({required this.data, required this.onOpen});

  final DirectorDashboardData data;
  final Future<void> Function(String path) onOpen;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PgSectionHeader(title: 'Team Performance'),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: _PeopleListCard(
                title: 'Top Performers',
                empty: 'No ranked employees yet.',
                rows: data.topPerformers.take(5).toList(),
                positive: true,
                onOpen: onOpen,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _PeopleListCard(
                title: 'Needs Attention',
                empty: 'No low-activity employees.',
                rows: data.needsAttention.take(5).toList(),
                positive: false,
                onOpen: onOpen,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _PeopleListCard extends StatelessWidget {
  const _PeopleListCard({
    required this.title,
    required this.empty,
    required this.rows,
    required this.positive,
    required this.onOpen,
  });

  final String title;
  final String empty;
  final List<Map<String, dynamic>> rows;
  final bool positive;
  final Future<void> Function(String path) onOpen;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      onTap: () => onOpen('/director/employees'),
      padding: const EdgeInsets.all(10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: Theme.of(context).textTheme.labelLarge?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(height: 6),
          if (rows.isEmpty)
            Text(empty, style: Theme.of(context).textTheme.labelSmall)
          else
            ...rows.map((row) {
              final name = (row['employee_name'] ?? row['name'] ?? '—')
                  .toString();
              final pct = double.tryParse(
                    '${row['sales_percentage'] ?? row['sales_pct'] ?? 0}',
                  ) ??
                  0;
              return Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.labelSmall,
                      ),
                    ),
                    Text(
                      '${pct.round()}%',
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        fontSize: 11,
                        color: positive ? AppColors.success : AppColors.warning,
                      ),
                    ),
                  ],
                ),
              );
            }),
        ],
      ),
    );
  }
}

class _CollectionSection extends StatelessWidget {
  const _CollectionSection({required this.data, required this.onOpen});

  final DirectorDashboardData data;
  final Future<void> Function(String path) onOpen;

  @override
  Widget build(BuildContext context) {
    final items = [
      ('Today\'s Collection', _compactInr(data.todayCollection), '/director/collections'),
      ('This Month Collection', _compactInr(data.monthCollection > 0 ? data.monthCollection : data.collectionAchieved), '/director/collections'),
      ('Total Outstanding', _compactInr(data.totalOutstanding), '/director/collections'),
      ('High Outstanding Dealers', '${data.highOutstandingDealers}', '/director/collections'),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PgSectionHeader(title: 'Collection & Outstanding'),
        GridView.builder(
          itemCount: items.length,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 2,
            mainAxisSpacing: 8,
            crossAxisSpacing: 8,
            childAspectRatio: 2.1,
          ),
          itemBuilder: (context, index) {
            final item = items[index];
            return PgCard(
              onTap: () => onOpen(item.$3),
              padding: const EdgeInsets.all(10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    item.$1,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.labelSmall,
                  ),
                  Text(
                    item.$2,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                ],
              ),
            );
          },
        ),
      ],
    );
  }
}

class _KpiItem {
  const _KpiItem({
    required this.label,
    required this.value,
    required this.icon,
    required this.accent,
    required this.onTap,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color accent;
  final VoidCallback onTap;
}

class _KpiGrid extends StatelessWidget {
  const _KpiGrid({required this.items});

  final List<_KpiItem> items;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final aspect = constraints.maxWidth >= 400 ? 1.75 : 1.4;
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
            return PgCard(
              onTap: item.onTap,
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 32,
                    height: 32,
                    decoration: BoxDecoration(
                      color: item.accent.withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(9),
                    ),
                    child: Icon(item.icon, size: 16, color: item.accent),
                  ),
                  const Spacer(),
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: Text(
                      item.value,
                      maxLines: 1,
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w800,
                            color: AppColors.textPrimary,
                          ),
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    item.label,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.labelSmall?.copyWith(
                          color: AppColors.textSecondary,
                          fontWeight: FontWeight.w600,
                        ),
                  ),
                ],
              ),
            );
          },
        );
      },
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

class _ModuleList extends StatelessWidget {
  const _ModuleList({required this.items});

  final List<_ModuleItem> items;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        for (var i = 0; i < items.length; i++) ...[
          if (i > 0) const SizedBox(height: AppSpacing.sm),
          PgCard(
            onTap: items[i].onTap,
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.md,
              vertical: 12,
            ),
            child: Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    gradient: const LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: AppColors.tealGradient,
                    ),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Icon(items[i].icon, color: Colors.white, size: 20),
                ),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        items[i].title,
                        style: Theme.of(context).textTheme.titleSmall?.copyWith(
                              fontWeight: FontWeight.w800,
                            ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        items[i].subtitle,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: AppColors.textSecondary,
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
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Sales Performance (existing employee performance + company totals)
// ---------------------------------------------------------------------------

class DirectorEmployeePerformanceScreen extends StatefulWidget {
  const DirectorEmployeePerformanceScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<DirectorEmployeePerformanceScreen> createState() =>
      _DirectorEmployeePerformanceScreenState();
}

class _DirectorEmployeePerformanceScreenState
    extends State<DirectorEmployeePerformanceScreen> {
  String _period = 'month';
  late Future<DirectorDashboardData> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.loadDashboard(period: _period);
  }

  void _setPeriod(String period) {
    if (_period == period) return;
    setState(() {
      _period = period;
      _future = _api.loadDashboard(period: period);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Sales Performance', auth: widget.auth),
      body: FutureBuilder<DirectorDashboardData>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              !snapshot.hasData) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(
              message: 'Unable to load dashboard',
              onRetry: () => setState(() {
                _future = _api.loadDashboard(period: _period);
              }),
            );
          }
          final data = snapshot.data!;
          final employees = _salesTeamOnly(data.employeePerformance);

          return RefreshIndicator(
            color: AppColors.primary,
            onRefresh: () async {
              setState(() {
                _future = _api.loadDashboard(period: _period);
              });
              await _future;
            },
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                Wrap(
                  spacing: 8,
                  children: [
                    _PeriodChip(
                      label: 'Today',
                      selected: _period == 'today',
                      onTap: () => _setPeriod('today'),
                    ),
                    _PeriodChip(
                      label: 'This Week',
                      selected: _period == 'week',
                      onTap: () => _setPeriod('week'),
                    ),
                    _PeriodChip(
                      label: 'This Month',
                      selected: _period == 'month',
                      onTap: () => _setPeriod('month'),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    children: [
                      _MetricRow(
                        'Total Sales Target',
                        _inr.format(data.salesTarget),
                      ),
                      _MetricRow(
                        'Total Achievement',
                        _inr.format(data.salesAchieved),
                      ),
                      _MetricRow(
                        'Achievement %',
                        '${data.salesPercentage.round()}%',
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                Text(
                  'Employee-wise',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
                const SizedBox(height: AppSpacing.sm),
                if (employees.isEmpty)
                  const PgEmptyState(message: 'No Activity Today')
                else
                  ...employees.map((employee) {
                    return PgCard(
                      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                      onTap: () => context.push(
                        '/director/employees/${employee['employee_id']}',
                        extra: employee,
                      ),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  employee['employee_name']?.toString() ?? '-',
                                  style:
                                      Theme.of(context).textTheme.titleSmall,
                                ),
                                Text(
                                  '${employee['role_label'] ?? '-'} • '
                                  'Sales ${employee['sales_percentage'] ?? 0}%',
                                  style: Theme.of(context).textTheme.bodySmall,
                                ),
                              ],
                            ),
                          ),
                          Text(
                            _inr.format(
                              double.tryParse(
                                    '${employee['sales_achieved'] ?? 0}',
                                  ) ??
                                  0,
                            ),
                            style: Theme.of(context)
                                .textTheme
                                .titleSmall
                                ?.copyWith(fontWeight: FontWeight.w700),
                          ),
                        ],
                      ),
                    );
                  }),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _PeriodChip extends StatelessWidget {
  const _PeriodChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ChoiceChip(
      label: Text(label),
      selected: selected,
      onSelected: (_) => onTap(),
      selectedColor: AppColors.primary.withValues(alpha: 0.18),
      labelStyle: TextStyle(
        fontWeight: FontWeight.w700,
        color: selected ? AppColors.primary : AppColors.textSecondary,
        fontSize: 12,
      ),
    );
  }
}

class _MetricRow extends StatelessWidget {
  const _MetricRow(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: AppColors.textSecondary,
                  ),
            ),
          ),
          Text(
            value,
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
          ),
        ],
      ),
    );
  }
}

class DirectorEmployeeDetailScreen extends StatelessWidget {
  const DirectorEmployeeDetailScreen({
    super.key,
    required this.auth,
    required this.employee,
  });

  final AuthController auth;
  final Map<String, dynamic> employee;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Employee Details', auth: auth),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            employee['employee_name']?.toString() ?? '-',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          Text('Role: ${employee['role_label'] ?? '-'}'),
          const SizedBox(height: 16),
          _Row(
            'Sales Target',
            _inr.format(
              double.tryParse('${employee['sales_target'] ?? 0}') ?? 0,
            ),
          ),
          _Row(
            'Sales Achieved',
            _inr.format(
              double.tryParse('${employee['sales_achieved'] ?? 0}') ?? 0,
            ),
          ),
          _Row('Sales %', '${employee['sales_percentage'] ?? 0}%'),
          _Row(
            'Collection Target',
            _inr.format(
              double.tryParse('${employee['collection_target'] ?? 0}') ?? 0,
            ),
          ),
          _Row(
            'Collection Achieved',
            _inr.format(
              double.tryParse('${employee['collection_achieved'] ?? 0}') ?? 0,
            ),
          ),
          _Row('Collection %', '${employee['collection_percentage'] ?? 0}%'),
          _Row('Pending Orders', '${employee['pending_orders'] ?? 0}'),
          _Row('Approved Orders', '${employee['approved_orders'] ?? 0}'),
          _Row('Dispatched Orders', '${employee['dispatched_orders'] ?? 0}'),
          _Row(
            'Total Collections',
            _inr.format(
              double.tryParse('${employee['total_collections'] ?? 0}') ?? 0,
            ),
          ),
          _Row('Attendance', '${employee['attendance_status'] ?? '-'}'),
          _Row('Dealer Visits', '${employee['dealer_visits'] ?? 0}'),
          _Row('Field Activities', '${employee['field_activities'] ?? 0}'),
        ],
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row(this.label, this.value);
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(
          children: [
            Expanded(child: Text(label)),
            Text(value, style: const TextStyle(fontWeight: FontWeight.w600)),
          ],
        ),
      );
}

// ---------------------------------------------------------------------------
// Collections Overview
// ---------------------------------------------------------------------------

class DirectorCollectionsScreen extends StatefulWidget {
  const DirectorCollectionsScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<DirectorCollectionsScreen> createState() =>
      _DirectorCollectionsScreenState();
}

class _DirectorCollectionsScreenState extends State<DirectorCollectionsScreen> {
  String _period = 'month';
  late Future<DirectorDashboardData> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.loadDashboard(period: _period);
  }

  void _setPeriod(String period) {
    if (_period == period) return;
    setState(() {
      _period = period;
      _future = _api.loadDashboard(period: period);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Collections', auth: widget.auth),
      body: FutureBuilder<DirectorDashboardData>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              !snapshot.hasData) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(
              message: 'Unable to load dashboard',
              onRetry: () => setState(() {
                _future = _api.loadDashboard(period: _period);
              }),
            );
          }
          final data = snapshot.data!;
          final amount = data.collectionAmount > 0
              ? data.collectionAmount
              : data.collectionAchieved;

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              Wrap(
                spacing: 8,
                children: [
                  _PeriodChip(
                    label: 'Today',
                    selected: _period == 'today',
                    onTap: () => _setPeriod('today'),
                  ),
                  _PeriodChip(
                    label: 'This Week',
                    selected: _period == 'week',
                    onTap: () => _setPeriod('week'),
                  ),
                  _PeriodChip(
                    label: 'This Month',
                    selected: _period == 'month',
                    onTap: () => _setPeriod('month'),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(
                  children: [
                    _MetricRow('Total Collection', _inr.format(amount)),
                    _MetricRow('Entries', '${data.collections}'),
                    _MetricRow(
                      'Collection Target',
                      _inr.format(data.collectionTarget),
                    ),
                    _MetricRow(
                      'Achievement %',
                      '${data.collectionPercentage.round()}%',
                    ),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.lg),
              Text(
                'Employee Collections',
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const SizedBox(height: AppSpacing.sm),
              if (_salesTeamOnly(data.employeePerformance).isEmpty)
                const PgEmptyState(message: 'No Activity Today')
              else
                ..._salesTeamOnly(data.employeePerformance).map((employee) {
                  return PgCard(
                    margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                    onTap: () => context.push(
                      '/director/employees/${employee['employee_id']}',
                      extra: employee,
                    ),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            employee['employee_name']?.toString() ?? '-',
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                        ),
                        Text(
                          _inr.format(
                            double.tryParse(
                                  '${employee['collection_achieved'] ?? employee['total_collections'] ?? 0}',
                                ) ??
                                0,
                          ),
                          style: Theme.of(context)
                              .textTheme
                              .titleSmall
                              ?.copyWith(fontWeight: FontWeight.w700),
                        ),
                      ],
                    ),
                  );
                }),
            ],
          );
        },
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Team Activity
// ---------------------------------------------------------------------------

class DirectorTeamActivityScreen extends StatefulWidget {
  const DirectorTeamActivityScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<DirectorTeamActivityScreen> createState() =>
      _DirectorTeamActivityScreenState();
}

class _DirectorTeamActivityScreenState
    extends State<DirectorTeamActivityScreen> {
  late Future<DirectorDashboardData> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.loadDashboard(period: 'today');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Team Activity', auth: widget.auth),
      body: FutureBuilder<DirectorDashboardData>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              !snapshot.hasData) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(
              message: 'Unable to load dashboard',
              onRetry: () => setState(() {
                _future = _api.loadDashboard(period: 'today');
              }),
            );
          }
          final data = snapshot.data!;
          final employees = _salesTeamOnly(data.employeePerformance);

          return RefreshIndicator(
            color: AppColors.primary,
            onRefresh: () async {
              setState(() {
                _future = _api.loadDashboard(period: 'today');
              });
              await _future;
            },
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgCard(
                  onTap: () => context.push('/director/route-tracking'),
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.md,
                    vertical: 12,
                  ),
                  child: Row(
                    children: [
                      Container(
                        width: 40,
                        height: 40,
                        decoration: BoxDecoration(
                          gradient: const LinearGradient(
                            colors: AppColors.tealGradient,
                          ),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: const Icon(
                          Icons.route_outlined,
                          color: Colors.white,
                          size: 20,
                        ),
                      ),
                      const SizedBox(width: AppSpacing.md),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Route Tracking',
                              style: Theme.of(context)
                                  .textTheme
                                  .titleSmall
                                  ?.copyWith(fontWeight: FontWeight.w800),
                            ),
                            Text(
                              'View Manager & Employee routes',
                              style: Theme.of(context)
                                  .textTheme
                                  .bodySmall
                                  ?.copyWith(color: AppColors.textSecondary),
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
                const SizedBox(height: AppSpacing.md),
                _KpiGrid(
                  items: [
                    _KpiItem(
                      label: 'Present Today',
                      value: '${data.presentToday}',
                      icon: Icons.groups_outlined,
                      accent: AppColors.success,
                      onTap: () {},
                    ),
                    _KpiItem(
                      label: 'Absent Today',
                      value: '${data.absentToday}',
                      icon: Icons.person_off_outlined,
                      accent: AppColors.error,
                      onTap: () {},
                    ),
                    _KpiItem(
                      label: 'Dealer Visits',
                      value: '${data.dealerVisits}',
                      icon: Icons.storefront_outlined,
                      accent: AppColors.primary,
                      onTap: () {},
                    ),
                    _KpiItem(
                      label: 'Field Visits',
                      value: '${data.fieldActivities}',
                      icon: Icons.travel_explore_rounded,
                      accent: AppColors.accent,
                      onTap: () {},
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.lg),
                Text(
                  'Employees',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
                const SizedBox(height: AppSpacing.sm),
                if (employees.isEmpty)
                  const PgEmptyState(message: 'No Activity Today')
                else
                  ...employees.map((employee) {
                    return PgCard(
                      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                      onTap: () => context.push(
                        '/director/employees/${employee['employee_id']}',
                        extra: employee,
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            employee['employee_name']?.toString() ?? '-',
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'Attendance: ${employee['attendance_status'] ?? '-'}'
                            ' · Dealer ${employee['dealer_visits'] ?? 0}'
                            ' · Field ${employee['field_activities'] ?? 0}',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    );
                  }),
              ],
            ),
          );
        },
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// TA/DA Overview (view only — no new approval actions)
// ---------------------------------------------------------------------------

class DirectorTaDaClaimsScreen extends StatefulWidget {
  const DirectorTaDaClaimsScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<DirectorTaDaClaimsScreen> createState() =>
      _DirectorTaDaClaimsScreenState();
}

class _DirectorTaDaClaimsScreenState extends State<DirectorTaDaClaimsScreen>
    with SingleTickerProviderStateMixin {
  static const _tabs = <({String label, String? status})>[
    (label: 'All', status: null),
    (label: 'Pending', status: 'pending'),
    (label: 'Approved', status: 'approved'),
    (label: 'Rejected', status: 'rejected'),
  ];

  late final TabController _tabsCtrl;
  late Future<List<Map<String, dynamic>>> _listFuture;
  late Future<DirectorDashboardData> _summaryFuture;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _tabsCtrl = TabController(length: _tabs.length, vsync: this);
    _tabsCtrl.addListener(() {
      if (_tabsCtrl.indexIsChanging) return;
      _reloadList();
    });
    _listFuture = _loadList();
    _summaryFuture = _api.loadDashboard(period: 'month');
  }

  @override
  void dispose() {
    _tabsCtrl.dispose();
    super.dispose();
  }

  Future<List<Map<String, dynamic>>> _loadList() {
    return _api.listTaDaClaims(status: _tabs[_tabsCtrl.index].status);
  }

  Future<void> _reloadList() async {
    setState(() => _listFuture = _loadList());
    await _listFuture;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'TA/DA Overview',
        auth: widget.auth,
        bottom: TabBar(
          controller: _tabsCtrl,
          isScrollable: true,
          tabs: [for (final t in _tabs) Tab(text: t.label)],
        ),
      ),
      body: Column(
        children: [
          FutureBuilder<DirectorDashboardData>(
            future: _summaryFuture,
            builder: (context, snapshot) {
              if (!snapshot.hasData) return const SizedBox.shrink();
              final d = snapshot.data!;
              return Padding(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                child: PgCard(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Row(
                    children: [
                      Expanded(
                        child: _MiniStat('Pending', '${d.pendingClaims}'),
                      ),
                      Expanded(
                        child: _MiniStat('Approved', '${d.approvedClaims}'),
                      ),
                      Expanded(
                        child: _MiniStat('Rejected', '${d.rejectedClaims}'),
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
          Expanded(
            child: FutureBuilder<List<Map<String, dynamic>>>(
              future: _listFuture,
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting &&
                    !snapshot.hasData) {
                  return const PgLoadingState();
                }
                if (snapshot.hasError) {
                  return PgErrorState(
                    message: 'Unable to load dashboard',
                    onRetry: _reloadList,
                  );
                }
                final claims = snapshot.data ?? const [];
                if (claims.isEmpty) {
                  return const PgEmptyState(message: 'No Activity Today');
                }
                final totalAmount = claims.fold<double>(
                  0,
                  (sum, c) =>
                      sum + (double.tryParse('${c['total_amount'] ?? 0}') ?? 0),
                );
                return RefreshIndicator(
                  color: AppColors.primary,
                  onRefresh: _reloadList,
                  child: ListView.builder(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(AppSpacing.screenPadding),
                    itemCount: claims.length + 1,
                    itemBuilder: (context, index) {
                      if (index == 0) {
                        return Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: Text(
                            'Total Amount (list): ${_inr.format(totalAmount)}',
                            style: Theme.of(context)
                                .textTheme
                                .labelLarge
                                ?.copyWith(fontWeight: FontWeight.w700),
                          ),
                        );
                      }
                      final claim = claims[index - 1];
                      return PgCard(
                        margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              claim['employee_name']?.toString() ?? '-',
                              style: Theme.of(context).textTheme.titleSmall,
                            ),
                            const SizedBox(height: 4),
                            Text(
                              '${claim['claim_date'] ?? '-'} • '
                              '${claim['status_label'] ?? claim['status']}'
                              ' • ${_inr.format(double.tryParse('${claim['total_amount'] ?? 0}') ?? 0)}',
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ],
                        ),
                      );
                    },
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _MiniStat extends StatelessWidget {
  const _MiniStat(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(
          value,
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
        ),
        Text(
          label,
          style: Theme.of(context).textTheme.labelSmall?.copyWith(
                color: AppColors.textSecondary,
              ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Reports hub — links only to existing Director modules
// ---------------------------------------------------------------------------

class DirectorReportsScreen extends StatelessWidget {
  const DirectorReportsScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    final items = <_ModuleItem>[
      _ModuleItem(
        title: 'Sales',
        subtitle: 'Targets & employee achievement',
        icon: Icons.trending_up_rounded,
        onTap: () => context.push('/director/sales-performance'),
      ),
      _ModuleItem(
        title: 'Collections',
        subtitle: 'Collection totals & employee split',
        icon: Icons.account_balance_wallet_outlined,
        onTap: () => context.push('/director/collections'),
      ),
      _ModuleItem(
        title: 'Orders',
        subtitle: 'Order monitoring by status',
        icon: Icons.fact_check_outlined,
        onTap: () => context.push('/director/orders'),
      ),
      _ModuleItem(
        title: 'Attendance / Activity',
        subtitle: 'Present, visits & field activity',
        icon: Icons.groups_rounded,
        onTap: () => context.push('/director/team-activity'),
      ),
      _ModuleItem(
        title: 'Payment Approvals',
        subtitle: 'Pending / Approved / Payment Done',
        icon: Icons.payments_outlined,
        onTap: () => context.push('/director/payment-requests'),
      ),
    ];

    return Scaffold(
      appBar: RoleAppBar(title: 'Reports', auth: auth),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          Text(
            'Available reports use existing Director data.',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
          ),
          const SizedBox(height: AppSpacing.md),
          _ModuleList(items: items),
        ],
      ),
    );
  }
}

class DirectorCollectionDetailRoute {
  static Future<Map<String, dynamic>> load(
    AuthController auth,
    int collectionId,
  ) {
    return DirectorApi(
      ApiClient(SessionStore(), onUnauthorized: auth.sessionExpired).dio,
    ).getCollection(collectionId);
  }
}
