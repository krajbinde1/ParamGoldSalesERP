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
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/director_api.dart';

final _inr = NumberFormat.currency(locale: 'en_IN', symbol: '₹', decimalDigits: 0);

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
      backgroundColor: AppColors.background,
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
                      const PgSectionHeader(title: 'Executive Summary'),
                      _KpiGrid(
                        items: [
                          _KpiItem(
                            label: 'Sales MTD',
                            value: _inr.format(data.salesAchieved),
                            icon: Icons.trending_up_rounded,
                            accent: AppColors.primary,
                            onTap: () => _open('/director/sales-performance'),
                          ),
                          _KpiItem(
                            label: 'Collections MTD',
                            value: _inr.format(data.collectionAmount > 0
                                ? data.collectionAmount
                                : data.collectionAchieved),
                            icon: Icons.account_balance_wallet_outlined,
                            accent: AppColors.accent,
                            onTap: () => _open('/director/collections'),
                          ),
                          _KpiItem(
                            label: 'Pending Payment Approvals',
                            value: '${data.pendingPaymentApprovals}',
                            icon: Icons.payments_outlined,
                            accent: data.pendingPaymentApprovals > 0
                                ? AppColors.warning
                                : AppColors.textSecondary,
                            onTap: () => _open('/director/payment-requests'),
                          ),
                          _KpiItem(
                            label: 'Pending Orders',
                            value: '${data.pendingOrders}',
                            icon: Icons.pending_actions_rounded,
                            accent: data.pendingOrders > 0
                                ? AppColors.warning
                                : AppColors.textSecondary,
                            onTap: () =>
                                _open('/director/orders?status=pending_approval'),
                          ),
                        ],
                      ),
                      const SizedBox(height: AppSpacing.lg),
                      const PgSectionHeader(title: 'Team Snapshot'),
                      _KpiGrid(
                        items: [
                          _KpiItem(
                            label: 'Present Today',
                            value: '${data.presentToday}',
                            icon: Icons.groups_outlined,
                            accent: AppColors.success,
                            onTap: () => _open('/director/team-activity'),
                          ),
                          _KpiItem(
                            label: 'Dealer Visits',
                            value: '${data.dealerVisits}',
                            icon: Icons.storefront_outlined,
                            accent: AppColors.primary,
                            onTap: () => _open('/director/team-activity'),
                          ),
                          _KpiItem(
                            label: 'Field Visits',
                            value: '${data.fieldActivities}',
                            icon: Icons.travel_explore_rounded,
                            accent: AppColors.accent,
                            onTap: () => _open('/director/team-activity'),
                          ),
                          _KpiItem(
                            label: 'Dispatched',
                            value: '${data.dispatchedOrders}',
                            icon: Icons.local_shipping_outlined,
                            accent: AppColors.info,
                            onTap: () =>
                                _open('/director/orders?status=dispatched'),
                          ),
                        ],
                      ),
                      const SizedBox(height: AppSpacing.lg),
                      const PgSectionHeader(title: 'Management'),
                      _ModuleList(
                        items: [
                          _ModuleItem(
                            title: 'Payment Approval',
                            subtitle: data.pendingPaymentApprovals > 0
                                ? '${data.pendingPaymentApprovals} Pending'
                                : 'No Pending Approvals',
                            icon: Icons.payments_outlined,
                            onTap: () => _open('/director/payment-requests'),
                          ),
                          _ModuleItem(
                            title: 'Order Monitoring',
                            subtitle:
                                '${data.pendingOrders} pending · ${data.dispatchedOrders} dispatched',
                            icon: Icons.fact_check_outlined,
                            onTap: () => _open('/director/orders'),
                          ),
                          _ModuleItem(
                            title: 'Sales Performance',
                            subtitle:
                                '${data.salesPercentage.round()}% of ${_inr.format(data.salesTarget)} target',
                            icon: Icons.insights_rounded,
                            onTap: () => _open('/director/sales-performance'),
                          ),
                          _ModuleItem(
                            title: 'Collections',
                            subtitle: _inr.format(data.collectionAmount > 0
                                ? data.collectionAmount
                                : data.collectionAchieved),
                            icon: Icons.account_balance_wallet_outlined,
                            onTap: () => _open('/director/collections'),
                          ),
                          _ModuleItem(
                            title: 'Team Activity',
                            subtitle:
                                '${data.presentToday} present · ${data.dealerVisits} dealer visits',
                            icon: Icons.groups_rounded,
                            onTap: () => _open('/director/team-activity'),
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
          final employees = data.employeePerformance;

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
// Order Monitoring
// ---------------------------------------------------------------------------

class DirectorOrdersScreen extends StatefulWidget {
  const DirectorOrdersScreen({
    super.key,
    required this.auth,
    this.initialStatus,
  });

  final AuthController auth;
  final String? initialStatus;

  @override
  State<DirectorOrdersScreen> createState() => _DirectorOrdersScreenState();
}

class _DirectorOrdersScreenState extends State<DirectorOrdersScreen>
    with SingleTickerProviderStateMixin {
  static const _tabs = <({String label, String? status})>[
    (label: 'All', status: null),
    (label: 'Pending', status: 'pending_approval'),
    (label: 'Approved', status: 'approved'),
    (label: 'Billed', status: 'billed'),
    (label: 'Dispatched', status: 'dispatched'),
    (label: 'Rejected', status: 'rejected'),
  ];

  late final TabController _tabsCtrl;
  late Future<List<Map<String, dynamic>>> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    final initial = _tabs.indexWhere((t) => t.status == widget.initialStatus);
    _tabsCtrl = TabController(
      length: _tabs.length,
      vsync: this,
      initialIndex: initial >= 0 ? initial : 0,
    );
    _tabsCtrl.addListener(() {
      if (_tabsCtrl.indexIsChanging) return;
      _reload();
    });
    _future = _load();
  }

  @override
  void dispose() {
    _tabsCtrl.dispose();
    super.dispose();
  }

  Future<List<Map<String, dynamic>>> _load() {
    return _api.listOrders(status: _tabs[_tabsCtrl.index].status);
  }

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Order Monitoring',
        auth: widget.auth,
        bottom: TabBar(
          controller: _tabsCtrl,
          isScrollable: true,
          tabs: [for (final t in _tabs) Tab(text: t.label)],
        ),
      ),
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              !snapshot.hasData) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(
              message: 'Unable to load dashboard',
              onRetry: _reload,
            );
          }
          final orders = snapshot.data ?? const [];
          if (orders.isEmpty) {
            return const PgEmptyState(message: 'No Orders Found');
          }
          return RefreshIndicator(
            color: AppColors.primary,
            onRefresh: _reload,
            child: ListView.builder(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              itemCount: orders.length,
              itemBuilder: (context, index) {
                final order = orders[index];
                return PgCard(
                  margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                  onTap: () => context.push('/director/orders/${order['id']}'),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        order['order_no']?.toString() ?? '-',
                        style: Theme.of(context).textTheme.titleSmall?.copyWith(
                              fontWeight: FontWeight.w800,
                            ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${order['employee_name'] ?? '-'} • '
                        '${order['status_label'] ?? order['status'] ?? '-'}',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      if (order['grand_total'] != null) ...[
                        const SizedBox(height: 4),
                        Text(
                          _inr.format(
                            double.tryParse('${order['grand_total']}') ?? 0,
                          ),
                          style:
                              Theme.of(context).textTheme.bodySmall?.copyWith(
                                    fontWeight: FontWeight.w700,
                                    color: AppColors.primary,
                                  ),
                        ),
                      ],
                    ],
                  ),
                );
              },
            ),
          );
        },
      ),
    );
  }
}

class DirectorOrderDetailScreen extends StatefulWidget {
  const DirectorOrderDetailScreen({
    super.key,
    required this.auth,
    required this.orderId,
  });

  final AuthController auth;
  final int orderId;

  @override
  State<DirectorOrderDetailScreen> createState() =>
      _DirectorOrderDetailScreenState();
}

class _DirectorOrderDetailScreenState extends State<DirectorOrderDetailScreen> {
  late Future<Map<String, dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _future = DirectorApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    ).getOrder(widget.orderId);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Order Details', auth: widget.auth),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return Center(child: Text(errorMessage(snapshot.error)));
          }
          final order = snapshot.data!;
          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              Text(
                order['order_no']?.toString() ?? '-',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              Text('Status: ${order['status_label'] ?? order['status']}'),
              Text('Employee: ${order['employee_name'] ?? '-'}'),
              Text('Approved By: ${order['approved_by'] ?? '-'}'),
              Text('Dispatched By: ${order['dispatched_by'] ?? '-'}'),
              if (order['rejection_remark'] != null)
                Text('Rejection Remark: ${order['rejection_remark']}'),
              if (order['dispatch_remark'] != null)
                Text('Dispatch Remark: ${order['dispatch_remark']}'),
            ],
          );
        },
      ),
    );
  }
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
              if (data.employeePerformance.isEmpty)
                const PgEmptyState(message: 'No Activity Today')
              else
                ...data.employeePerformance.map((employee) {
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
          final employees = data.employeePerformance;

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
