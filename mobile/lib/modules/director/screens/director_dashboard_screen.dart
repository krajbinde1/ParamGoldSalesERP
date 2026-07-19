import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
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
  String _period = 'month';

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<DirectorDashboardData> _load() => _api.loadDashboard(period: _period);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 0,
    );

    return Scaffold(
      appBar: RoleAppBar(title: 'Director Dashboard', auth: widget.auth),
      body: RefreshIndicator(
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
                  PgErrorState(
                    message: errorMessage(snapshot.error),
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final data = snapshot.data!;
            return ListView(
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                Text(
                  'Company Overview',
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
                Text(data.period),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 8,
                  children: [
                    for (final period in ['today', 'week', 'month'])
                      ChoiceChip(
                        label: Text(period),
                        selected: _period == period,
                        onSelected: (selected) {
                          if (!selected) return;
                          setState(() => _period = period);
                          _reload();
                        },
                      ),
                  ],
                ),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 12,
                  runSpacing: 12,
                  children: [
                    DashboardMetricCard(
                      label: 'Sales Target',
                      value: currency.format(data.salesTarget),
                    ),
                    DashboardMetricCard(
                      label: 'Sales Achieved',
                      value: '${data.salesPercentage.toStringAsFixed(1)}%',
                    ),
                    DashboardMetricCard(
                      label: 'Collection Target',
                      value: currency.format(data.collectionTarget),
                    ),
                    DashboardMetricCard(
                      label: 'Collection Achieved',
                      value: '${data.collectionPercentage.toStringAsFixed(1)}%',
                    ),
                    DashboardMetricCard(
                      label: 'Pending Orders',
                      value: '${data.pendingOrders}',
                    ),
                    DashboardMetricCard(
                      label: 'Dispatched Orders',
                      value: '${data.dispatchedOrders}',
                    ),
                    DashboardMetricCard(
                      label: 'Pending TA/DA',
                      value: '${data.pendingClaims}',
                    ),
                    DashboardMetricCard(
                      label: 'Paid TA/DA',
                      value: '${data.paidClaims}',
                    ),
                    DashboardMetricCard(
                      label: 'Present Today',
                      value: '${data.presentToday}',
                    ),
                    DashboardMetricCard(
                      label: 'Absent Today',
                      value: '${data.absentToday}',
                    ),
                    DashboardMetricCard(
                      label: 'Dealer Visits',
                      value: '${data.dealerVisits}',
                    ),
                    DashboardMetricCard(
                      label: 'Collections',
                      value: currency.format(data.collectionAmount),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.lg),
                const PgSectionHeader(title: 'Modules'),
                const SizedBox(height: AppSpacing.sm),
                ModuleTile(
                  icon: Icons.people_outline,
                  label: 'Employee Performance',
                  subtitle: '${data.employeePerformance.length} employees',
                  onTap: () => context.push(
                    '/director/employees',
                    extra: data.employeePerformance,
                  ),
                ),
                ModuleTile(
                  icon: Icons.shopping_cart_outlined,
                  label: 'All Orders',
                  onTap: () => context.push('/director/orders'),
                ),
                ModuleTile(
                  icon: Icons.receipt_long_outlined,
                  label: 'All TA/DA Claims',
                  onTap: () => context.push('/director/ta-da-claims'),
                ),
              ],
            );
          },
        ),
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
