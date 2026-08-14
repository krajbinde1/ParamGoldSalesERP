import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_progress_bar.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/manager_api.dart';

class ManagerEmployeePerformanceScreen extends StatefulWidget {
  const ManagerEmployeePerformanceScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<ManagerEmployeePerformanceScreen> createState() =>
      _ManagerEmployeePerformanceScreenState();
}

class _ManagerEmployeePerformanceScreenState
    extends State<ManagerEmployeePerformanceScreen> {
  String _period = 'month';
  String? _startDate;
  String? _endDate;
  String _search = '';
  late Future<ManagerEmployeePerformanceListResult> _future;
  final _searchController = TextEditingController();

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _reload();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  void _reload() {
    setState(() {
      _future = _api.listEmployeePerformance(
        period: _period,
        startDate: _startDate,
        endDate: _endDate,
        search: _search,
      );
    });
  }

  Future<void> _refresh() async {
    _reload();
    await _future;
  }

  Future<void> _pickCustomRange() async {
    final now = DateTime.now();
    final range = await showDateRangePicker(
      context: context,
      firstDate: DateTime(now.year - 2),
      lastDate: now,
      initialDateRange: _startDate != null && _endDate != null
          ? DateTimeRange(
              start: DateTime.parse(_startDate!),
              end: DateTime.parse(_endDate!),
            )
          : DateTimeRange(
              start: now.subtract(const Duration(days: 6)),
              end: now,
            ),
    );
    if (range == null || !mounted) return;

    setState(() {
      _period = 'custom';
      _startDate = DateFormat('yyyy-MM-dd').format(range.start);
      _endDate = DateFormat('yyyy-MM-dd').format(range.end);
    });
    _reload();
  }

  void _setPeriod(String period) {
    setState(() {
      _period = period;
      if (period != 'custom') {
        _startDate = null;
        _endDate = null;
      }
    });
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(locale: 'en_IN', symbol: '₹');

    return Scaffold(
      appBar: RoleAppBar(title: 'Employee Performance', auth: widget.auth),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _PeriodSelector(
                  selected: _period,
                  onSelected: _setPeriod,
                  onCustom: _pickCustomRange,
                ),
                if (_period == 'custom' &&
                    _startDate != null &&
                    _endDate != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    '$_startDate → $_endDate',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AppColors.textSecondary,
                        ),
                  ),
                ],
                const SizedBox(height: 8),
                TextField(
                  controller: _searchController,
                  decoration: InputDecoration(
                    hintText: 'Search employee',
                    prefixIcon: const Icon(Icons.search),
                    suffixIcon: _search.isEmpty
                        ? null
                        : IconButton(
                            icon: const Icon(Icons.clear),
                            onPressed: () {
                              _searchController.clear();
                              _search = '';
                              _reload();
                            },
                          ),
                    isDense: true,
                    border: const OutlineInputBorder(),
                  ),
                  onSubmitted: (value) {
                    _search = value.trim();
                    _reload();
                  },
                ),
              ],
            ),
          ),
          Expanded(
            child: FutureBuilder<ManagerEmployeePerformanceListResult>(
              future: _future,
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting &&
                    !snapshot.hasData) {
                  return const PgLoadingState();
                }

                if (snapshot.hasError) {
                  return RefreshIndicator(
                    onRefresh: _refresh,
                    child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.all(AppSpacing.screenPadding),
                      children: [
                        PgErrorState(message: errorMessage(snapshot.error)),
                      ],
                    ),
                  );
                }

                final result = snapshot.data!;
                final employees = result.employees;

                if (employees.isEmpty) {
                  return RefreshIndicator(
                    onRefresh: _refresh,
                    child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      children: [
                        SizedBox(
                          height: MediaQuery.sizeOf(context).height * 0.4,
                          child: const PgEmptyState(
                            message: 'No employees found',
                            icon: const Icon(Icons.people_outline),
                          ),
                        ),
                      ],
                    ),
                  );
                }

                return RefreshIndicator(
                  onRefresh: _refresh,
                  child: ListView.builder(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(
                      AppSpacing.screenPadding,
                      0,
                      AppSpacing.screenPadding,
                      AppSpacing.screenPadding,
                    ),
                    itemCount: employees.length,
                    itemBuilder: (context, index) {
                      final employee = employees[index];
                      return PgCard(
                        onTap: () async {
                          await context.push(
                            '/manager/employees/${employee['employee_id']}',
                            extra: {
                              'period': _period,
                              'startDate': _startDate,
                              'endDate': _endDate,
                            },
                          );
                          if (!mounted) return;
                          _refresh();
                        },
                        margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  employee['employee_name']?.toString() ?? '-',
                                  style: Theme.of(context).textTheme.titleMedium,
                                ),
                                const SizedBox(height: 8),
                                _MetricRow(
                                  'Sales Target',
                                  currency.format(
                                    _toDouble(employee['sales_target']),
                                  ),
                                ),
                                _MetricRow(
                                  'Sales Achieved',
                                  currency.format(
                                    _toDouble(employee['sales_achieved']),
                                  ),
                                ),
                                _MetricRow(
                                  'Sales %',
                                  '${employee['sales_percentage'] ?? 0}%',
                                ),
                                const SizedBox(height: 4),
                                _MetricRow(
                                  'Collection Target',
                                  currency.format(
                                    _toDouble(employee['collection_target']),
                                  ),
                                ),
                                _MetricRow(
                                  'Collection Achieved',
                                  currency.format(
                                    _toDouble(employee['collection_achieved']),
                                  ),
                                ),
                                _MetricRow(
                                  'Collection %',
                                  '${employee['collection_percentage'] ?? 0}%',
                                ),
                                const SizedBox(height: 4),
                                _MetricRow(
                                  'Total Orders',
                                  currency.format(
                                    _toDouble(employee['total_order_amount']),
                                  ),
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

  double _toDouble(Object? value) =>
      double.tryParse('$value') ?? 0;
}

/// Responsive period pills for Employee Performance (UI only).
class _PeriodSelector extends StatelessWidget {
  const _PeriodSelector({
    required this.selected,
    required this.onSelected,
    required this.onCustom,
  });

  final String selected;
  final ValueChanged<String> onSelected;
  final VoidCallback onCustom;

  static const _options = <(String label, String value)>[
    ('Today', 'today'),
    ('This Week', 'week'),
    ('This Month', 'month'),
    ('Custom', 'custom'),
  ];

  static const double _height = 40;
  static const double _gap = 8;
  static const double _radius = 20;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        // Approximate natural widths so we know when to switch to scroll mode.
        const approxWidths = [72.0, 96.0, 104.0, 80.0];
        final needed = approxWidths.reduce((a, b) => a + b) +
            (_gap * (_options.length - 1));
        final useScroll = constraints.maxWidth < needed;

        Widget pill(
          String label,
          String value, {
          bool compact = false,
        }) {
          final isSelected = selected == value;
          return Material(
            color: isSelected
                ? AppColors.primary.withValues(alpha: 0.12)
                : AppColors.surface,
            borderRadius: BorderRadius.circular(_radius),
            child: InkWell(
              onTap: () {
                if (value == 'custom') {
                  onCustom();
                } else {
                  onSelected(value);
                }
              },
              borderRadius: BorderRadius.circular(_radius),
              child: Container(
                height: _height,
                alignment: Alignment.center,
                padding: EdgeInsets.symmetric(horizontal: compact ? 8 : 12),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(_radius),
                  border: Border.all(
                    color: isSelected ? AppColors.primary : AppColors.border,
                    width: isSelected ? 1.5 : 1,
                  ),
                ),
                child: Text(
                  label,
                  maxLines: 1,
                  softWrap: false,
                  overflow: compact
                      ? TextOverflow.ellipsis
                      : TextOverflow.visible,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    fontSize: compact ? 12 : 13,
                    height: 1.1,
                    fontWeight:
                        isSelected ? FontWeight.w700 : FontWeight.w500,
                    color: isSelected
                        ? AppColors.primary
                        : AppColors.textSecondary,
                  ),
                ),
              ),
            ),
          );
        }

        if (!useScroll) {
          return Row(
            children: [
              for (var i = 0; i < _options.length; i++) ...[
                if (i > 0) const SizedBox(width: _gap),
                Expanded(
                  child: pill(_options[i].$1, _options[i].$2, compact: true),
                ),
              ],
            ],
          );
        }

        return SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          physics: const BouncingScrollPhysics(),
          clipBehavior: Clip.hardEdge,
          child: Row(
            children: [
              for (var i = 0; i < _options.length; i++) ...[
                if (i > 0) const SizedBox(width: _gap),
                pill(_options[i].$1, _options[i].$2),
              ],
            ],
          ),
        );
      },
    );
  }
}

class ManagerEmployeeDetailScreen extends StatefulWidget {
  const ManagerEmployeeDetailScreen({
    super.key,
    required this.auth,
    required this.employeeId,
    required this.period,
    this.startDate,
    this.endDate,
  });

  final AuthController auth;
  final int employeeId;
  final String period;
  final String? startDate;
  final String? endDate;

  @override
  State<ManagerEmployeeDetailScreen> createState() =>
      _ManagerEmployeeDetailScreenState();
}

class _ManagerEmployeeDetailScreenState extends State<ManagerEmployeeDetailScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  late Future<ManagerEmployeePerformanceDetail> _future;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _reload();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  void _reload() {
    setState(() {
      _future = _api.getEmployeePerformance(
        widget.employeeId,
        period: widget.period,
        startDate: widget.startDate,
        endDate: widget.endDate,
      );
    });
  }

  Future<void> _refresh() async {
    _reload();
    await _future;
  }

  Future<void> _openOrder(int orderId) async {
    final changed = await context.push<bool>('/manager/orders/$orderId');
    if (changed == true && mounted) {
      _refresh();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Employee Performance',
        auth: widget.auth,
        bottom: TabBar(
          controller: _tabController,
          tabs: const [
            Tab(text: 'Performance'),
            Tab(text: 'Orders'),
          ],
        ),
      ),
      body: FutureBuilder<ManagerEmployeePerformanceDetail>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              !snapshot.hasData) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(message: errorMessage(snapshot.error));
          }

          final detail = snapshot.data!;
          final employee = detail.performance;

          return TabBarView(
            controller: _tabController,
            children: [
              RefreshIndicator(
                onRefresh: _refresh,
                child: _PerformanceSummary(
                  employee: employee,
                  period: detail.period,
                ),
              ),
              RefreshIndicator(
                onRefresh: _refresh,
                child: _EmployeeOrdersTab(
                  orders: detail.orders,
                  summary: detail.orderSummary,
                  onTapOrder: _openOrder,
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _PerformanceSummary extends StatelessWidget {
  const _PerformanceSummary({
    required this.employee,
    required this.period,
  });

  final Map<String, dynamic> employee;
  final String period;

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(locale: 'en_IN', symbol: '₹');

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(AppSpacing.screenPadding),
      children: [
        PgDetailHeader(
          title: employee['employee_name']?.toString() ?? '-',
          subtitle: period,
        ),
        const SizedBox(height: AppSpacing.md),
        PgCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              PgInvoiceRow(
                label: 'Employee Code',
                value: employee['employee_code']?.toString() ?? '-',
              ),
              PgInvoiceRow(
                label: 'Mobile',
                value: employee['mobile']?.toString() ?? '-',
              ),
              PgInvoiceRow(
                label: 'Base Location',
                value: employee['base_location']?.toString() ?? '-',
              ),
              PgInvoiceRow(
                label: 'Reporting Manager',
                value: employee['reporting_manager']?.toString() ?? '-',
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        const PgSectionHeader(title: 'Sales Performance'),
        const SizedBox(height: AppSpacing.sm),
        _PerformanceBlock(
          targetLabel: 'Sales Target Given',
          targetValue: currency.format(_toDouble(employee['sales_target'])),
          achievedLabel: 'Sales Achieved',
          achievedValue: currency.format(_toDouble(employee['sales_achieved'])),
          remainingLabel: 'Remaining Sales Target',
          remainingValue: currency.format(_toDouble(employee['sales_remaining'])),
          percentage: _toDouble(employee['sales_percentage']),
        ),
        const SizedBox(height: AppSpacing.md),
        const PgSectionHeader(title: 'Collection Performance'),
        const SizedBox(height: AppSpacing.sm),
        _PerformanceBlock(
          targetLabel: 'Collection Target Given',
          targetValue: currency.format(_toDouble(employee['collection_target'])),
          achievedLabel: 'Collection Achieved',
          achievedValue:
              currency.format(_toDouble(employee['collection_achieved'])),
          remainingLabel: 'Remaining Collection Target',
          remainingValue:
              currency.format(_toDouble(employee['collection_remaining'])),
          percentage: _toDouble(employee['collection_percentage']),
        ),
      ],
    );
  }

  double _toDouble(Object? value) => double.tryParse('$value') ?? 0;
}

class _PerformanceBlock extends StatelessWidget {
  const _PerformanceBlock({
    required this.targetLabel,
    required this.targetValue,
    required this.achievedLabel,
    required this.achievedValue,
    required this.remainingLabel,
    required this.remainingValue,
    required this.percentage,
  });

  final String targetLabel;
  final String targetValue;
  final String achievedLabel;
  final String achievedValue;
  final String remainingLabel;
  final String remainingValue;
  final double percentage;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          PgInvoiceRow(label: targetLabel, value: targetValue),
          PgInvoiceRow(label: achievedLabel, value: achievedValue),
          PgInvoiceRow(label: remainingLabel, value: remainingValue),
          const SizedBox(height: AppSpacing.sm),
          PgProgressBar(
            label: 'Achievement',
            percentage: percentage,
            currentLabel: achievedValue,
            targetLabel: targetValue,
          ),
        ],
      ),
    );
  }
}

class _EmployeeOrdersTab extends StatelessWidget {
  const _EmployeeOrdersTab({
    required this.orders,
    required this.summary,
    required this.onTapOrder,
  });

  final List<Map<String, dynamic>> orders;
  final Map<String, dynamic> summary;
  final void Function(int orderId) onTapOrder;

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(locale: 'en_IN', symbol: '₹');

    if (orders.isEmpty) {
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          _OrderSummaryCard(summary: summary, currency: currency),
          const SizedBox(height: 24),
          const Center(
            child: PgEmptyState(
              message: 'No orders in this period.',
              icon: const Icon(Icons.shopping_cart_outlined),
            ),
          ),
        ],
      );
    }

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(AppSpacing.screenPadding),
      children: [
        _OrderSummaryCard(summary: summary, currency: currency),
        const SizedBox(height: AppSpacing.sm),
        const PgSectionHeader(title: 'Orders'),
        const SizedBox(height: AppSpacing.sm),
        ...orders.map(
          (order) {
            final status = order['status']?.toString() ?? '';
            return PgCard(
              onTap: () =>
                  onTapOrder(int.tryParse('${order['id'] ?? 0}') ?? 0),
              margin: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          order['order_no']?.toString() ?? '-',
                          style: Theme.of(context).textTheme.titleSmall,
                        ),
                        Text(
                          '${order['dealer_name'] ?? '-'} • ${order['order_date'] ?? '-'}',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ],
                    ),
                  ),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(
                        currency.format(_toDouble(order['grand_total'])),
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                      const SizedBox(height: 4),
                      PgStatusBadge(
                        label: order['status_label']?.toString() ?? '-',
                        tone: PgStatusRules.orderTone(status),
                      ),
                    ],
                  ),
                ],
              ),
            );
          },
        ),
      ],
    );
  }

  double _toDouble(Object? value) => double.tryParse('$value') ?? 0;
}

class _OrderSummaryCard extends StatelessWidget {
  const _OrderSummaryCard({
    required this.summary,
    required this.currency,
  });

  final Map<String, dynamic> summary;
  final NumberFormat currency;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Order Summary', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: AppSpacing.sm),
          PgInvoiceRow(label: 'Total Orders', value: '${summary['total_orders'] ?? 0}'),
          PgInvoiceRow(label: 'Pending', value: '${summary['pending_orders'] ?? 0}'),
          PgInvoiceRow(label: 'Approved', value: '${summary['approved_orders'] ?? 0}'),
          PgInvoiceRow(label: 'Dispatched', value: '${summary['dispatched_orders'] ?? 0}'),
          PgInvoiceRow(label: 'Rejected', value: '${summary['rejected_orders'] ?? 0}'),
          PgInvoiceRow(
            label: 'Total Amount',
            value: currency.format(_toDouble(summary['total_order_amount'])),
            emphasize: true,
          ),
        ],
      ),
    );
  }

  double _toDouble(Object? value) => double.tryParse('$value') ?? 0;
}

class _MetricRow extends StatelessWidget {
  const _MetricRow(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        children: [
          Expanded(
            child: Text(label, style: Theme.of(context).textTheme.bodySmall),
          ),
          Text(value, style: Theme.of(context).textTheme.bodySmall),
        ],
      ),
    );
  }
}
