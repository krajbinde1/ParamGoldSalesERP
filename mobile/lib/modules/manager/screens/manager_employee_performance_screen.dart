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
    return Scaffold(
      backgroundColor: AppColors.background,
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
                      return _EmployeePerformanceCard(
                        employee: employee,
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

class _EmployeePerformanceCard extends StatelessWidget {
  const _EmployeePerformanceCard({
    required this.employee,
    required this.onTap,
  });

  final Map<String, dynamic> employee;
  final VoidCallback onTap;

  static final _ordersCurrency = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 0,
  );

  @override
  Widget build(BuildContext context) {
    final name = employee['employee_name']?.toString() ?? '-';
    final code = employee['employee_code']?.toString() ?? '-';
    final salesTarget = _num(employee['sales_target']);
    final salesAchieved = _num(employee['sales_achieved']);
    final salesPct = _num(employee['sales_percentage']);
    final collectionTarget = _num(employee['collection_target']);
    final collectionAchieved = _num(employee['collection_achieved']);
    final collectionPct = _num(employee['collection_percentage']);
    final fieldTarget = _num(employee['field_activity_target']);
    final fieldAchieved = _num(employee['field_activity_achieved']);
    final fieldRemaining = _num(employee['field_activity_remaining']);
    final fieldPct = _num(employee['field_activity_percentage']);
    final overallPct = (salesPct + collectionPct + fieldPct) / 3;

    return PgCard(
      onTap: onTap,
      margin: const EdgeInsets.only(bottom: AppSpacing.md),
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      name,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w800,
                            color: AppColors.textPrimary,
                          ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      'Employee Code: $code',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: AppColors.textSecondary,
                            fontWeight: FontWeight.w600,
                          ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              _OverallBadge(percentage: overallPct),
            ],
          ),
          const SizedBox(height: 14),
          _PerformanceSection(
            icon: Icons.trending_up_rounded,
            title: 'Sales',
            ratioLabel:
                '${_compactInr(salesAchieved)} / ${_compactInr(salesTarget)}',
            percentLabel: _percentLabel(salesPct),
            progress: _barValue(salesPct),
            color: AppColors.primary,
          ),
          const SizedBox(height: 12),
          _PerformanceSection(
            icon: Icons.payments_rounded,
            title: 'Collection',
            ratioLabel:
                '${_compactInr(collectionAchieved)} / ${_compactInr(collectionTarget)}',
            percentLabel: _percentLabel(collectionPct),
            progress: _barValue(collectionPct),
            color: AppColors.secondary,
          ),
          const SizedBox(height: 12),
          _PerformanceSection(
            icon: Icons.agriculture_rounded,
            title: 'Field Activity',
            ratioLabel:
                '${fieldAchieved.round()} / ${fieldTarget.round()}',
            percentLabel: _percentLabel(fieldPct),
            progress: _barValue(fieldPct),
            color: AppColors.info,
            footnote: '${fieldRemaining.round()} Remaining',
          ),
          const SizedBox(height: 12),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            decoration: BoxDecoration(
              color: AppColors.primary.withValues(alpha: 0.06),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    'Total Orders',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                  ),
                ),
                const SizedBox(width: 8),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(
                    _ordersCurrency.format(_num(employee['total_order_amount'])),
                    maxLines: 1,
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                          color: AppColors.primary,
                        ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  static double _num(Object? value) => double.tryParse('$value') ?? 0;

  static double _barValue(double percentage) =>
      (percentage / 100).clamp(0.0, 1.0);

  static String _percentLabel(double value) {
    if (value == value.roundToDouble()) return '${value.toInt()}%';
    return '${value.toStringAsFixed(1)}%';
  }

  static String _compactInr(double value) {
    if (value == 0) return '₹0';
    final abs = value.abs();
    final sign = value < 0 ? '-' : '';
    if (abs >= 10000000) {
      return '$sign₹${(abs / 10000000).toStringAsFixed(2)}Cr';
    }
    if (abs >= 100000) {
      return '$sign₹${(abs / 100000).toStringAsFixed(2)}L';
    }
    return NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 0,
    ).format(value);
  }
}

class _OverallBadge extends StatelessWidget {
  const _OverallBadge({required this.percentage});

  final double percentage;

  @override
  Widget build(BuildContext context) {
    final label = percentage == percentage.roundToDouble()
        ? '${percentage.toInt()}%'
        : '${percentage.toStringAsFixed(0)}%';

    return Container(
      width: 48,
      height: 48,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: AppColors.primary.withValues(alpha: 0.1),
        shape: BoxShape.circle,
      ),
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 6),
          child: Text(
            label,
            maxLines: 1,
            style: Theme.of(context).textTheme.labelLarge?.copyWith(
                  color: AppColors.primary,
                  fontWeight: FontWeight.w800,
                ),
          ),
        ),
      ),
    );
  }
}

class _PerformanceSection extends StatelessWidget {
  const _PerformanceSection({
    required this.icon,
    required this.title,
    required this.ratioLabel,
    required this.percentLabel,
    required this.progress,
    required this.color,
    this.footnote,
  });

  final IconData icon;
  final String title;
  final String ratioLabel;
  final String percentLabel;
  final double progress;
  final Color color;
  final String? footnote;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          children: [
            Icon(icon, size: 16, color: color),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                title.toUpperCase(),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.labelMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      letterSpacing: 0.6,
                      color: AppColors.textSecondary,
                    ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 6),
        Row(
          children: [
            Expanded(
              child: FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: Text(
                  ratioLabel,
                  maxLines: 1,
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                ),
              ),
            ),
            const SizedBox(width: 8),
            Text(
              percentLabel,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: color,
                  ),
            ),
          ],
        ),
        if (footnote != null) ...[
          const SizedBox(height: 2),
          Text(
            footnote!,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w600,
                ),
          ),
        ],
        const SizedBox(height: 6),
        ClipRRect(
          borderRadius: BorderRadius.circular(999),
          child: LinearProgressIndicator(
            value: progress,
            minHeight: 6,
            backgroundColor: const Color(0xFFE2E8F0),
            color: color,
          ),
        ),
      ],
    );
  }
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
        const SizedBox(height: AppSpacing.md),
        const PgSectionHeader(title: 'Field Activity Performance'),
        const SizedBox(height: AppSpacing.sm),
        _PerformanceBlock(
          targetLabel: 'Field Activity Target',
          targetValue:
              '${_toDouble(employee['field_activity_target']).round()}',
          achievedLabel: 'Field Activity Achieved',
          achievedValue:
              '${_toDouble(employee['field_activity_achieved']).round()}',
          remainingLabel: 'Remaining Field Activity Target',
          remainingValue:
              '${_toDouble(employee['field_activity_remaining']).round()}',
          percentage: _toDouble(employee['field_activity_percentage']),
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
