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
import '../../../core/widgets/design/pg_progress_bar.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/manager_api.dart';

class ManagerTargetsScreen extends StatefulWidget {
  const ManagerTargetsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<ManagerTargetsScreen> createState() => _ManagerTargetsScreenState();
}

class _ManagerTargetsScreenState extends State<ManagerTargetsScreen> {
  String _period = 'month';
  String? _startDate;
  String? _endDate;
  late Future<ManagerTargetsResult> _future;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _reload();
  }

  void _reload() {
    setState(() {
      _future = _api.loadTargets(
        period: _period,
        startDate: _startDate,
        endDate: _endDate,
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
      appBar: RoleAppBar(title: 'Targets', auth: widget.auth),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: [
                      for (final period in ['today', 'week', 'month'])
                        Padding(
                          padding: const EdgeInsets.only(right: 8),
                          child: ChoiceChip(
                            label: Text(_periodLabel(period)),
                            selected: _period == period,
                            onSelected: (selected) {
                              if (selected) _setPeriod(period);
                            },
                          ),
                        ),
                      ChoiceChip(
                        label: const Text('Custom'),
                        selected: _period == 'custom',
                        onSelected: (_) => _pickCustomRange(),
                      ),
                    ],
                  ),
                ),
                if (_period == 'custom' &&
                    _startDate != null &&
                    _endDate != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    '$_startDate → $_endDate',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ],
            ),
          ),
          Expanded(
            child: FutureBuilder<ManagerTargetsResult>(
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
                        PgErrorState(
                          message: errorMessage(snapshot.error),
                          onRetry: _refresh,
                        ),
                      ],
                    ),
                  );
                }

                final result = snapshot.data!;
                final summary = result.summary;
                final employees = result.employees;

                return RefreshIndicator(
                  onRefresh: _refresh,
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(
                      AppSpacing.screenPadding,
                      0,
                      AppSpacing.screenPadding,
                      AppSpacing.screenPadding,
                    ),
                    children: [
                      Text(
                        result.period,
                        style: Theme.of(context).textTheme.titleMedium
                            ?.copyWith(fontWeight: FontWeight.w700),
                      ),
                      const SizedBox(height: AppSpacing.md),
                      LayoutBuilder(
                        builder: (context, constraints) {
                          final aspectRatio =
                              constraints.maxWidth >= 700 ? 2.2 : 1.55;
                          return GridView.count(
                            crossAxisCount: 2,
                            shrinkWrap: true,
                            physics: const NeverScrollableScrollPhysics(),
                            childAspectRatio: aspectRatio,
                            mainAxisSpacing: AppSpacing.sm,
                            crossAxisSpacing: AppSpacing.sm,
                            children: [
                              _SummaryMetricCard(
                                title: 'Total Sales Target',
                                value: currency.format(summary.salesTarget),
                                icon: Icons.flag_outlined,
                                colors: AppColors.tealGradient,
                              ),
                              _SummaryMetricCard(
                                title: 'Sales Achieved',
                                value: currency.format(summary.salesAchieved),
                                icon: Icons.trending_up_rounded,
                                colors: AppColors.greenGradient,
                              ),
                              _SummaryMetricCard(
                                title: 'Collection Target',
                                value:
                                    currency.format(summary.collectionTarget),
                                icon: Icons.account_balance_wallet_outlined,
                                colors: AppColors.amberGradient,
                              ),
                              _SummaryMetricCard(
                                title: 'Collection Achieved',
                                value: currency
                                    .format(summary.collectionAchieved),
                                icon: Icons.payments_outlined,
                                colors: AppColors.blueGradient,
                              ),
                            ],
                          );
                        },
                      ),
                      const SizedBox(height: AppSpacing.md),
                      PgCard(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const PgSectionHeader(title: 'Sales Progress'),
                            const SizedBox(height: AppSpacing.sm),
                            PgProgressBar(
                              label: 'Sales Achievement',
                              percentage: summary.salesPercentage,
                              currentLabel:
                                  currency.format(summary.salesAchieved),
                              targetLabel:
                                  currency.format(summary.salesTarget),
                              color: AppColors.primary,
                            ),
                            const SizedBox(height: AppSpacing.sm),
                            _MetricRow(
                              'Sales Pending Target',
                              currency.format(summary.salesPending),
                            ),
                            _MetricRow(
                              'Sales Achievement %',
                              '${_formatPercent(summary.salesPercentage)}%',
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: AppSpacing.md),
                      PgCard(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const PgSectionHeader(title: 'Collection Progress'),
                            const SizedBox(height: AppSpacing.sm),
                            PgProgressBar(
                              label: 'Collection Achievement',
                              percentage: summary.collectionPercentage,
                              currentLabel: currency
                                  .format(summary.collectionAchieved),
                              targetLabel:
                                  currency.format(summary.collectionTarget),
                              color: AppColors.accent,
                            ),
                            const SizedBox(height: AppSpacing.sm),
                            _MetricRow(
                              'Collection Pending Target',
                              currency.format(summary.collectionPending),
                            ),
                            _MetricRow(
                              'Collection Achievement %',
                              '${_formatPercent(summary.collectionPercentage)}%',
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: AppSpacing.md),
                      PgCard(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const PgSectionHeader(
                              title: 'Field Activity Progress',
                            ),
                            const SizedBox(height: AppSpacing.sm),
                            PgProgressBar(
                              label: 'Field Activity Achievement',
                              percentage: summary.fieldActivityPercentage,
                              currentLabel:
                                  '${summary.fieldActivityAchieved.round()}',
                              targetLabel:
                                  '${summary.fieldActivityTarget.round()}',
                              color: AppColors.info,
                            ),
                            const SizedBox(height: AppSpacing.sm),
                            _MetricRow(
                              'Field Activity Remaining',
                              '${summary.fieldActivityPending.round()}',
                            ),
                            _MetricRow(
                              'Field Activity Achievement %',
                              '${_formatPercent(summary.fieldActivityPercentage)}%',
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: AppSpacing.lg),
                      const PgSectionHeader(title: 'Team-wise Breakdown'),
                      const SizedBox(height: AppSpacing.sm),
                      if (employees.isEmpty)
                        const PgEmptyState(
                          message: 'No reporting employees found',
                          icon: Icon(Icons.people_outline),
                        )
                      else
                        for (final employee in employees)
                          PgCard(
                            margin:
                                const EdgeInsets.only(bottom: AppSpacing.sm),
                            onTap: () {
                              final id = int.tryParse(
                                '${employee['employee_id']}',
                              );
                              if (id == null) return;
                              context.push(
                                '/manager/employees/$id',
                                extra: {
                                  'period': _period,
                                  'startDate': _startDate,
                                  'endDate': _endDate,
                                },
                              );
                            },
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  employee['employee_name']?.toString() ?? '-',
                                  style: Theme.of(context)
                                      .textTheme
                                      .titleMedium
                                      ?.copyWith(fontWeight: FontWeight.w700),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  employee['employee_code']?.toString() ?? '-',
                                  style: Theme.of(context)
                                      .textTheme
                                      .bodySmall
                                      ?.copyWith(color: AppColors.textSecondary),
                                ),
                                const SizedBox(height: AppSpacing.sm),
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
                                  'Sales Achievement %',
                                  '${_formatPercent(_toDouble(employee['sales_percentage']))}%',
                                ),
                                const SizedBox(height: 4),
                                PgProgressBar(
                                  label: 'Sales',
                                  percentage:
                                      _toDouble(employee['sales_percentage']),
                                  color: AppColors.primary,
                                ),
                                const SizedBox(height: AppSpacing.sm),
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
                                  'Collection Achievement %',
                                  '${_formatPercent(_toDouble(employee['collection_percentage']))}%',
                                ),
                                const SizedBox(height: 4),
                                PgProgressBar(
                                  label: 'Collection',
                                  percentage: _toDouble(
                                    employee['collection_percentage'],
                                  ),
                                  color: AppColors.accent,
                                ),
                                const SizedBox(height: AppSpacing.sm),
                                _MetricRow(
                                  'Field Activity Target',
                                  '${_toDouble(employee['field_activity_target']).round()}',
                                ),
                                _MetricRow(
                                  'Field Activity Achieved',
                                  '${_toDouble(employee['field_activity_achieved']).round()}',
                                ),
                                _MetricRow(
                                  'Field Activity Remaining',
                                  '${_toDouble(employee['field_activity_remaining']).round()}',
                                ),
                                _MetricRow(
                                  'Field Activity Achievement %',
                                  '${_formatPercent(_toDouble(employee['field_activity_percentage']))}%',
                                ),
                                const SizedBox(height: 4),
                                PgProgressBar(
                                  label: 'Field Activity',
                                  percentage: _toDouble(
                                    employee['field_activity_percentage'],
                                  ),
                                  color: AppColors.info,
                                ),
                              ],
                            ),
                          ),
                    ],
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  String _periodLabel(String period) => switch (period) {
        'today' => 'Today',
        'week' => 'This Week',
        'month' => 'This Month',
        _ => period,
      };

  double _toDouble(Object? value) => double.tryParse('$value') ?? 0;

  String _formatPercent(double value) =>
      value == value.roundToDouble()
          ? value.toInt().toString()
          : value.toStringAsFixed(1);
}

class _SummaryMetricCard extends StatelessWidget {
  const _SummaryMetricCard({
    required this.title,
    required this.value,
    required this.icon,
    required this.colors,
  });

  final String title;
  final String value;
  final IconData icon;
  final List<Color> colors;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      gradient: LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: colors,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: Colors.white.withValues(alpha: 0.9), size: 22),
          const Spacer(),
          Text(
            title,
            style: Theme.of(context).textTheme.labelMedium?.copyWith(
                  color: Colors.white.withValues(alpha: 0.9),
                ),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
          ),
          const SizedBox(height: 4),
          Text(
            value,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ],
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
      padding: const EdgeInsets.only(bottom: 4),
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
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
          ),
        ],
      ),
    );
  }
}
