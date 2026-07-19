import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/role_shell_widgets.dart';
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
  String _period = 'month';

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<ManagerDashboardData> _load() => _api.loadDashboard(period: _period);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    final employee = widget.auth.session!.employee;
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 0,
    );

    return Scaffold(
      appBar: RoleAppBar(title: 'Manager Dashboard', auth: widget.auth),
      body: RefreshIndicator(
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
                  'Welcome, ${employee.fullName}',
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
                Text(
                  'Manager • ${data.period}',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
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
                      value: currency.format(data.salesAchieved),
                    ),
                    DashboardMetricCard(
                      label: 'Collection Target',
                      value: currency.format(data.collectionTarget),
                    ),
                    DashboardMetricCard(
                      label: 'Collection Achieved',
                      value: currency.format(data.collectionAchieved),
                    ),
                    DashboardMetricCard(
                      label: 'Pending Orders',
                      value: '${data.pendingOrders}',
                    ),
                    DashboardMetricCard(
                      label: 'Pending TA/DA',
                      value: '${data.pendingClaims}',
                    ),
                    DashboardMetricCard(
                      label: 'Present Today',
                      value: '${data.presentToday}',
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
                  onTap: () => context.push('/manager/employees'),
                ),
                ModuleTile(
                  icon: Icons.shopping_cart_checkout_outlined,
                  label: 'Order Approvals',
                  subtitle: '${data.pendingOrders} pending approval',
                  onTap: () async {
                    await context.push('/manager/orders');
                    if (!mounted) return;
                    _reload();
                  },
                ),
                ModuleTile(
                  icon: Icons.receipt_long_outlined,
                  label: 'TA/DA Approvals',
                  subtitle: '${data.pendingClaims} pending',
                  onTap: () => context.push('/manager/ta-da-claims'),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}
