import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_period_filters.dart';
import '../../../core/widgets/design/pg_progress_bar.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/dashboard_api.dart';
import '../models/dashboard_data.dart';

class EmployeeTargetsScreen extends StatefulWidget {
  const EmployeeTargetsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<EmployeeTargetsScreen> createState() => _EmployeeTargetsScreenState();
}

class _EmployeeTargetsScreenState extends State<EmployeeTargetsScreen> {
  String _period = 'week';
  String? _startDate;
  String? _endDate;
  late Future<DashboardData> _future;

  DashboardApi get _api => DashboardApi(
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

    return PgPageScaffold(
      auth: widget.auth,
      title: 'My Targets',
      showBack: true,
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
            child: PgPeriodFilters(
              selected: _period,
              onSelected: _setPeriod,
              onCustom: _pickCustomRange,
            ),
          ),
          Expanded(
            child: FutureBuilder<DashboardData>(
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

                final data = snapshot.data!;
                final rangeLabel = PgPeriodFilters.formatRange(
                  data.startDate,
                  data.endDate,
                );

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
                        data.periodLabel,
                        style: Theme.of(context).textTheme.titleMedium
                            ?.copyWith(fontWeight: FontWeight.w700),
                      ),
                      if (rangeLabel.isNotEmpty) ...[
                        const SizedBox(height: 4),
                        Text(
                          'From Date – To Date: $rangeLabel',
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                color: AppColors.textSecondary,
                                fontWeight: FontWeight.w600,
                              ),
                        ),
                      ],
                      const SizedBox(height: AppSpacing.md),
                      const PgSectionHeader(title: 'Sales Target vs Achieved'),
                      _TargetBlock(
                        targetLabel: 'Sales Target',
                        targetValue: currency.format(data.weeklySalesTarget),
                        achievedLabel: 'Sales Achieved',
                        achievedValue: currency.format(data.weeklySalesAchieved),
                        remainingLabel: 'Remaining',
                        remainingValue: currency.format(data.weeklySalesRemaining),
                        percentage: PgPeriodFilters.percentValue(
                          data.weeklySalesPercentage,
                          target: data.weeklySalesTarget,
                        ),
                        color: AppColors.primary,
                      ),
                      const SizedBox(height: AppSpacing.md),
                      const PgSectionHeader(
                        title: 'Collection Target vs Achieved',
                      ),
                      _TargetBlock(
                        targetLabel: 'Collection Target',
                        targetValue: currency.format(data.weeklyCollectionTarget),
                        achievedLabel: 'Collection Achieved',
                        achievedValue:
                            currency.format(data.weeklyCollectionAchieved),
                        remainingLabel: 'Remaining',
                        remainingValue:
                            currency.format(data.weeklyCollectionRemaining),
                        percentage: PgPeriodFilters.percentValue(
                          data.weeklyCollectionPercentage,
                          target: data.weeklyCollectionTarget,
                        ),
                        color: AppColors.accent,
                      ),
                      const SizedBox(height: AppSpacing.md),
                      const PgSectionHeader(
                        title: 'Field Activity Target vs Achieved',
                      ),
                      _TargetBlock(
                        targetLabel: 'Field Activity Target',
                        targetValue: '${data.fieldActivityTarget.round()}',
                        achievedLabel: 'Field Activity Achieved',
                        achievedValue: '${data.fieldActivityAchieved.round()}',
                        remainingLabel: 'Remaining',
                        remainingValue:
                            '${data.fieldActivityRemaining.round()}',
                        percentage: PgPeriodFilters.percentValue(
                          data.fieldActivityPercentage,
                          target: data.fieldActivityTarget,
                        ),
                        color: AppColors.info,
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
}

class _TargetBlock extends StatelessWidget {
  const _TargetBlock({
    required this.targetLabel,
    required this.targetValue,
    required this.achievedLabel,
    required this.achievedValue,
    required this.remainingLabel,
    required this.remainingValue,
    required this.percentage,
    required this.color,
  });

  final String targetLabel;
  final String targetValue;
  final String achievedLabel;
  final String achievedValue;
  final String remainingLabel;
  final String remainingValue;
  final double? percentage;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _Row(targetLabel, targetValue),
          _Row(achievedLabel, achievedValue),
          _Row(remainingLabel, remainingValue),
          const SizedBox(height: AppSpacing.sm),
          PgProgressBar(
            label: 'Achievement %',
            percentage: percentage,
            currentLabel: achievedValue,
            targetLabel: targetValue,
            color: color,
          ),
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
