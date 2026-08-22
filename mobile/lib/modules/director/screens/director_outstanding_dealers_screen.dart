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
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/director_api.dart';

final _inr = NumberFormat.currency(
  locale: 'en_IN',
  symbol: '₹',
  decimalDigits: 0,
);

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

class DirectorOutstandingDealersScreen extends StatefulWidget {
  const DirectorOutstandingDealersScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<DirectorOutstandingDealersScreen> createState() =>
      _DirectorOutstandingDealersScreenState();
}

class _DirectorOutstandingDealersScreenState
    extends State<DirectorOutstandingDealersScreen> {
  late Future<Map<String, dynamic>> _future;
  int? _employeeId;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<Map<String, dynamic>> _load() =>
      _api.listOutstandingDealers(employeeId: _employeeId);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  void _selectEmployee(int? employeeId) {
    setState(() {
      _employeeId = employeeId;
      _future = _load();
    });
  }

  Future<void> _openDealer(Map<String, dynamic> dealer) async {
    final dealerId = int.tryParse(
          '${dealer['dealer_id'] ?? dealer['id'] ?? 0}',
        ) ??
        0;
    if (dealerId <= 0) return;
    await context.push('/dealers/$dealerId/ledger');
    if (!mounted) return;
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final canPop = context.canPop();
    return PopScope(
      canPop: canPop,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        smartBack(context);
      },
      child: Scaffold(
        appBar: RoleAppBar(
          title: 'Dealer Outstanding',
          auth: widget.auth,
          showBack: true,
          onBack: () => smartBack(context),
        ),
        body: RefreshIndicator(
          color: AppColors.primary,
          onRefresh: _reload,
          child: FutureBuilder<Map<String, dynamic>>(
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
                      message: errorMessage(snapshot.error),
                      onRetry: _reload,
                    ),
                  ],
                );
              }

              final payload = snapshot.data ?? const <String, dynamic>{};
              final dealers = (payload['data'] as List? ?? const [])
                  .whereType<Map>()
                  .map((item) => Map<String, dynamic>.from(item))
                  .toList();
              final employees = (payload['employees'] as List? ?? const [])
                  .whereType<Map>()
                  .map((item) => Map<String, dynamic>.from(item))
                  .toList();
              final total = double.tryParse(
                    '${payload['total_outstanding'] ?? 0}',
                  ) ??
                  0;
              final highDealers = dealers.take(5).toList();

              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgCard(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Total Outstanding',
                          style: Theme.of(context).textTheme.labelLarge
                              ?.copyWith(
                                color: Theme.of(
                                  context,
                                ).colorScheme.onSurfaceVariant,
                                fontWeight: FontWeight.w700,
                              ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          _compactInr(total),
                          style: Theme.of(context).textTheme.headlineSmall
                              ?.copyWith(
                                fontWeight: FontWeight.w800,
                                color: AppColors.error,
                              ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.md),
                  _EmployeeFilter(
                    employeeId: _employeeId,
                    employees: employees,
                    onSelect: _selectEmployee,
                  ),
                  if (highDealers.isNotEmpty) ...[
                    const SizedBox(height: AppSpacing.lg),
                    const PgSectionHeader(title: 'High Outstanding Dealers'),
                    PgCard(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 4,
                      ),
                      child: Column(
                        children: [
                          for (var i = 0; i < highDealers.length; i++) ...[
                            if (i > 0) const Divider(height: 1),
                            _HighOutstandingRow(
                              dealer: highDealers[i],
                              onTap: () => _openDealer(highDealers[i]),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ],
                  const SizedBox(height: AppSpacing.lg),
                  const PgSectionHeader(title: 'Dealers'),
                  if (dealers.isEmpty)
                    const PgEmptyState(
                      message: 'No dealers with outstanding balance.',
                    )
                  else
                    ...dealers.map(
                      (dealer) => Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: _DealerOutstandingCard(
                          dealer: dealer,
                          onTap: () => _openDealer(dealer),
                        ),
                      ),
                    ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _EmployeeFilter extends StatelessWidget {
  const _EmployeeFilter({
    required this.employeeId,
    required this.employees,
    required this.onSelect,
  });

  final int? employeeId;
  final List<Map<String, dynamic>> employees;
  final void Function(int? employeeId) onSelect;

  @override
  Widget build(BuildContext context) {
    final matches = employees.where((row) {
      return int.tryParse('${row['employee_id'] ?? 0}') == employeeId;
    });
    final selectedEmployee = matches.isEmpty ? null : matches.first;
    final employeeLabel = selectedEmployee == null
        ? 'All Employees'
        : (selectedEmployee['employee_name']?.toString() ?? 'All Employees');

    return PopupMenuButton<int>(
      onSelected: (value) => onSelect(value == 0 ? null : value),
      itemBuilder: (context) => [
        const PopupMenuItem(value: 0, child: Text('All Employees')),
        ...employees.map((row) {
          final id = int.tryParse('${row['employee_id'] ?? 0}') ?? 0;
          return PopupMenuItem(
            value: id,
            child: Text(row['employee_name']?.toString() ?? '-'),
          );
        }),
      ],
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          border: Border.all(color: AppColors.border),
          borderRadius: BorderRadius.circular(999),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Flexible(
              child: Text(
                '$employeeLabel ▼',
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.labelLarge?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _HighOutstandingRow extends StatelessWidget {
  const _HighOutstandingRow({
    required this.dealer,
    required this.onTap,
  });

  final Map<String, dynamic> dealer;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final outstanding =
        double.tryParse('${dealer['current_outstanding'] ?? 0}') ?? 0;
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    dealer['dealer_name']?.toString() ?? '-',
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    dealer['employee_name']?.toString() ?? '-',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AppColors.textSecondary,
                        ),
                  ),
                ],
              ),
            ),
            Text(
              _inr.format(outstanding),
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                    color: AppColors.error,
                    fontWeight: FontWeight.w800,
                  ),
            ),
          ],
        ),
      ),
    );
  }
}

class _DealerOutstandingCard extends StatelessWidget {
  const _DealerOutstandingCard({
    required this.dealer,
    required this.onTap,
  });

  final Map<String, dynamic> dealer;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final outstanding =
        double.tryParse('${dealer['current_outstanding'] ?? 0}') ?? 0;
    return PgCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  dealer['dealer_name']?.toString() ?? '-',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                ),
              ),
              Text(
                _inr.format(outstanding),
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      color: AppColors.error,
                      fontWeight: FontWeight.w800,
                    ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            dealer['dealer_code']?.toString() ?? '-',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          if ((dealer['village']?.toString() ?? '').trim().isNotEmpty)
            Text(
              dealer['village'].toString(),
              style: Theme.of(context).textTheme.bodySmall,
            ),
          Text(
            dealer['employee_name']?.toString() ?? '-',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
          ),
        ],
      ),
    );
  }
}
