import 'package:cached_network_image/cached_network_image.dart';
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
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/manager_api.dart';

class ManagerCollectionsScreen extends StatefulWidget {
  const ManagerCollectionsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<ManagerCollectionsScreen> createState() =>
      _ManagerCollectionsScreenState();
}

class _ManagerCollectionsScreenState extends State<ManagerCollectionsScreen> {
  String _period = 'month';
  int? _employeeId;
  DateTime? _dateFrom;
  DateTime? _dateTo;
  late Future<ManagerCollectionListResult> _future;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  static final _currency = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 0,
  );

  @override
  void initState() {
    super.initState();
    _reload();
  }

  void _reload() {
    setState(() {
      _future = _api.listCollections(
        period: _period,
        dateFrom: _period == 'custom' && _dateFrom != null
            ? DateFormat('yyyy-MM-dd').format(_dateFrom!)
            : null,
        dateTo: _period == 'custom' && _dateTo != null
            ? DateFormat('yyyy-MM-dd').format(_dateTo!)
            : null,
        employeeId: _employeeId,
      );
    });
  }

  Future<void> _refresh() async {
    _reload();
    await _future;
  }

  Future<void> _setPeriod(String period) async {
    if (period == 'custom') {
      final from = await showDatePicker(
        context: context,
        initialDate: _dateFrom ?? DateTime.now(),
        firstDate: DateTime(2020),
        lastDate: DateTime.now(),
      );
      if (!mounted || from == null) return;
      final to = await showDatePicker(
        context: context,
        initialDate: _dateTo ?? from,
        firstDate: from,
        lastDate: DateTime.now(),
      );
      if (!mounted || to == null) return;
      setState(() {
        _period = 'custom';
        _dateFrom = from;
        _dateTo = to;
      });
      _reload();
      return;
    }

    setState(() {
      _period = period;
      _dateFrom = null;
      _dateTo = null;
    });
    _reload();
  }

  PgStatusTone _statusTone(String status) {
    return switch (status) {
      'received' => PgStatusTone.paid,
      'not_received' => PgStatusTone.rejected,
      _ => PgStatusTone.pending,
    };
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: RoleAppBar(title: 'Collections', auth: widget.auth),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<ManagerCollectionListResult>(
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
                    onRetry: _refresh,
                  ),
                ],
              );
            }

            final result = snapshot.data!;
            final employees = result.employees;
            final summary = result.summary;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                LayoutBuilder(
                  builder: (context, constraints) {
                    final aspect = constraints.maxWidth >= 400 ? 1.85 : 1.45;
                    return GridView.count(
                      crossAxisCount: 2,
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      mainAxisSpacing: AppSpacing.sm,
                      crossAxisSpacing: AppSpacing.sm,
                      childAspectRatio: aspect,
                      children: [
                        _SummaryTile(
                          label: 'Total Collection',
                          value: _currency.format(summary.totalCollection),
                          icon: Icons.payments_rounded,
                          accent: AppColors.primary,
                        ),
                        _SummaryTile(
                          label: 'Today Collection',
                          value: _currency.format(summary.todayCollection),
                          icon: Icons.today_rounded,
                          accent: AppColors.info,
                        ),
                        _SummaryTile(
                          label: 'This Month Collection',
                          value: _currency.format(summary.monthCollection),
                          icon: Icons.calendar_month_rounded,
                          accent: AppColors.secondary,
                        ),
                        _SummaryTile(
                          label: 'Pending Collection Entries',
                          value: '${summary.pendingEntries}',
                          icon: Icons.hourglass_empty_rounded,
                          accent: AppColors.warning,
                        ),
                      ],
                    );
                  },
                ),
                const SizedBox(height: AppSpacing.md),
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: [
                      for (final entry in const [
                        ('today', 'Today'),
                        ('week', 'This Week'),
                        ('month', 'This Month'),
                        ('custom', 'Custom Date'),
                      ])
                        Padding(
                          padding: const EdgeInsets.only(right: 8),
                          child: ChoiceChip(
                            label: Text(
                              entry.$1 == 'custom' &&
                                      _period == 'custom' &&
                                      _dateFrom != null &&
                                      _dateTo != null
                                  ? '${DateFormat('d MMM').format(_dateFrom!)} – ${DateFormat('d MMM').format(_dateTo!)}'
                                  : entry.$2,
                            ),
                            selected: _period == entry.$1,
                            onSelected: (selected) {
                              if (!selected) return;
                              _setPeriod(entry.$1);
                            },
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                InputDecorator(
                  decoration: const InputDecoration(
                    labelText: 'Employee',
                    border: OutlineInputBorder(),
                  ),
                  child: DropdownButtonHideUnderline(
                    child: DropdownButton<int?>(
                      value: _employeeId,
                      isExpanded: true,
                      items: [
                        const DropdownMenuItem<int?>(
                          value: null,
                          child: Text('All employees'),
                        ),
                        ...employees.map(
                          (employee) => DropdownMenuItem<int?>(
                            value: int.tryParse('${employee['id']}'),
                            child: Text(
                              [
                                employee['full_name']?.toString() ?? '-',
                                if ((employee['employee_code']
                                        ?.toString()
                                        .isNotEmpty ??
                                    false))
                                  '(${employee['employee_code']})',
                              ].join(' '),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ),
                      ],
                      onChanged: (value) {
                        setState(() => _employeeId = value);
                        _reload();
                      },
                    ),
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                const PgSectionHeader(title: 'Team Collections'),
                if (result.rows.isEmpty)
                  const PgEmptyState(
                    message: 'No collections found for the selected filters.',
                    icon: Icon(Icons.payments_outlined),
                  )
                else
                  for (final row in result.rows)
                    PgCard(
                      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                      onTap: () => context.push(
                        '/manager/collections/${row['id']}',
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  row['dealer_name']?.toString() ?? '-',
                                  style: Theme.of(context)
                                      .textTheme
                                      .titleMedium
                                      ?.copyWith(fontWeight: FontWeight.w700),
                                ),
                              ),
                              PgStatusBadge(
                                label: row['status_label']?.toString() ??
                                    row['status']?.toString() ??
                                    'Pending',
                                tone: _statusTone(
                                  row['status']?.toString() ?? 'pending',
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          _InfoLine(
                            'Employee',
                            row['employee_name']?.toString() ?? '-',
                          ),
                          _InfoLine(
                            'Amount',
                            NumberFormat.currency(
                              locale: 'en_IN',
                              symbol: '₹',
                              decimalDigits: 2,
                            ).format(
                              double.tryParse('${row['amount'] ?? 0}') ?? 0,
                            ),
                          ),
                          _InfoLine(
                            'Date',
                            _formatDate(row['collection_date']?.toString()),
                          ),
                          if ((row['remarks']?.toString().trim().isNotEmpty ??
                              false))
                            _InfoLine('Remark', row['remarks'].toString()),
                          if ((row['photo_url']?.toString().isNotEmpty ??
                              false)) ...[
                            const SizedBox(height: 8),
                            ClipRRect(
                              borderRadius: BorderRadius.circular(10),
                              child: CachedNetworkImage(
                                imageUrl: row['photo_url'].toString(),
                                height: 120,
                                width: double.infinity,
                                fit: BoxFit.cover,
                                errorWidget: (_, _, _) => Container(
                                  height: 80,
                                  color: AppColors.border,
                                  alignment: Alignment.center,
                                  child: const Icon(Icons.broken_image_outlined),
                                ),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
              ],
            );
          },
        ),
      ),
    );
  }

  String _formatDate(String? raw) {
    if (raw == null || raw.isEmpty) return '-';
    final parsed = DateTime.tryParse(raw);
    if (parsed == null) return raw;
    return DateFormat('d MMM yyyy').format(parsed);
  }
}

class _SummaryTile extends StatelessWidget {
  const _SummaryTile({
    required this.label,
    required this.value,
    required this.icon,
    required this.accent,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return PgCard(
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 34,
            height: 34,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, size: 18, color: accent),
          ),
          const Spacer(),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              value,
              maxLines: 1,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
            ),
          ),
          const SizedBox(height: 2),
          Text(
            label,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.labelMedium?.copyWith(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w600,
                ),
          ),
        ],
      ),
    );
  }
}

class _InfoLine extends StatelessWidget {
  const _InfoLine(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 88,
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textMuted,
                    fontWeight: FontWeight.w600,
                  ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}
