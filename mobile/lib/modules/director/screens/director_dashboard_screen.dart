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
                    message: errorMessage(snapshot.error),
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final data = snapshot.data!;
            final dateLabel =
                DateFormat('EEE, d MMM yyyy').format(DateTime.now());

            return CustomScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              slivers: [
                SliverToBoxAdapter(
                  child: _DirectorHeader(
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
                      _PaymentApprovalEntryCard(
                        pendingCount: data.pendingPaymentApprovals,
                        onTap: () => _open('/director/payment-requests'),
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
                  'Director Dashboard',
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
              _DirectorHeaderAccountMenu(auth: auth),
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
                      'Role: Director',
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

class _DirectorHeaderAccountMenu extends StatelessWidget {
  const _DirectorHeaderAccountMenu({required this.auth});

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

class _PaymentApprovalEntryCard extends StatelessWidget {
  const _PaymentApprovalEntryCard({
    required this.pendingCount,
    required this.onTap,
  });

  final int pendingCount;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final pendingLabel = pendingCount == 1
        ? '1 Pending'
        : '$pendingCount Pending';

    return PgCard(
      onTap: onTap,
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Row(
        children: [
          Container(
            width: 52,
            height: 52,
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
            child: const Icon(
              Icons.payments_outlined,
              color: Colors.white,
              size: 26,
            ),
          ),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Payment Approval',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: AppColors.textPrimary,
                      ),
                ),
                const SizedBox(height: 4),
                Text(
                  pendingLabel,
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: pendingCount > 0
                            ? AppColors.warning
                            : AppColors.textSecondary,
                      ),
                ),
                const SizedBox(height: 2),
                Text(
                  'Review payment requests',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AppColors.textSecondary,
                        fontWeight: FontWeight.w500,
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
    );
  }
}

class DirectorEmployeePerformanceScreen extends StatelessWidget {
  const DirectorEmployeePerformanceScreen({
    super.key,
    required this.auth,
    required this.employees,
  });

  final AuthController auth;
  final List<Map<String, dynamic>> employees;

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(locale: 'en_IN', symbol: '₹');

    return Scaffold(
      appBar: RoleAppBar(title: 'Employee Performance', auth: auth),
      body: employees.isEmpty
          ? const PgEmptyState(message: 'No employee data available.')
          : ListView.builder(
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              itemCount: employees.length,
              itemBuilder: (context, index) {
                final employee = employees[index];
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
                              style: Theme.of(context).textTheme.titleSmall,
                            ),
                            Text(
                              '${employee['role_label'] ?? '-'} • Sales ${employee['sales_percentage'] ?? 0}% • Pending ${employee['pending_orders'] ?? 0}',
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ],
                        ),
                      ),
                      Text(
                        currency.format(
                          double.tryParse('${employee['sales_achieved'] ?? 0}') ??
                              0,
                        ),
                        style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                );
              },
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
    final currency = NumberFormat.currency(locale: 'en_IN', symbol: '₹');

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
          _Row('Sales Target', currency.format(double.tryParse('${employee['sales_target'] ?? 0}') ?? 0)),
          _Row('Sales Achieved', currency.format(double.tryParse('${employee['sales_achieved'] ?? 0}') ?? 0)),
          _Row('Sales %', '${employee['sales_percentage'] ?? 0}%'),
          _Row('Collection Target', currency.format(double.tryParse('${employee['collection_target'] ?? 0}') ?? 0)),
          _Row('Collection Achieved', currency.format(double.tryParse('${employee['collection_achieved'] ?? 0}') ?? 0)),
          _Row('Collection %', '${employee['collection_percentage'] ?? 0}%'),
          _Row('Pending Orders', '${employee['pending_orders'] ?? 0}'),
          _Row('Approved Orders', '${employee['approved_orders'] ?? 0}'),
          _Row('Dispatched Orders', '${employee['dispatched_orders'] ?? 0}'),
          _Row('Total Collections', currency.format(double.tryParse('${employee['total_collections'] ?? 0}') ?? 0)),
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

class DirectorOrdersScreen extends StatefulWidget {
  const DirectorOrdersScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<DirectorOrdersScreen> createState() => _DirectorOrdersScreenState();
}

class _DirectorOrdersScreenState extends State<DirectorOrdersScreen> {
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    _future = DirectorApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    ).listOrders();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'All Orders', auth: widget.auth),
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(message: errorMessage(snapshot.error));
          }
          final orders = snapshot.data ?? const [];
          return ListView.builder(
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
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                    Text(
                      '${order['employee_name'] ?? '-'} • ${order['status_label'] ?? order['status'] ?? '-'}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              );
            },
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
              Text(order['order_no']?.toString() ?? '-',
                  style: Theme.of(context).textTheme.titleLarge),
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

class DirectorTaDaClaimsScreen extends StatefulWidget {
  const DirectorTaDaClaimsScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<DirectorTaDaClaimsScreen> createState() =>
      _DirectorTaDaClaimsScreenState();
}

class _DirectorTaDaClaimsScreenState extends State<DirectorTaDaClaimsScreen> {
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    _future = DirectorApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    ).listTaDaClaims();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'TA/DA Claims', auth: widget.auth),
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(message: errorMessage(snapshot.error));
          }
          final claims = snapshot.data ?? const [];
          return ListView.builder(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            itemCount: claims.length,
            itemBuilder: (context, index) {
              final claim = claims[index];
              return PgCard(
                margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      claim['employee_name']?.toString() ?? '-',
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                    Text(
                      '${claim['claim_date'] ?? '-'} • ${claim['status_label'] ?? claim['status']}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              );
            },
          );
        },
      ),
    );
  }
}
