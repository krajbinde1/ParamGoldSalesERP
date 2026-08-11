import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../models/attendance.dart';
import '../providers/attendance_provider.dart';

class AttendanceHistory extends ConsumerStatefulWidget {
  const AttendanceHistory({super.key});
  @override
  ConsumerState<AttendanceHistory> createState() => _AttendanceHistoryState();
}

class _AttendanceHistoryState extends ConsumerState<AttendanceHistory> {
  DateTime month = DateTime(DateTime.now().year, DateTime.now().month);
  void move(int n) =>
      setState(() => month = DateTime(month.year, month.month + n));
  @override
  Widget build(BuildContext context) {
    final state = ref.watch(attendanceMonthProvider(month));
    final summaryState = ref.watch(attendanceMonthlySummaryProvider(month));
    return PgPageScaffold(
      title: 'Attendance History',
      showBack: true,
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          Row(
            children: [
              IconButton(
                onPressed: () => move(-1),
                icon: const Icon(Icons.chevron_left_rounded),
              ),
              Expanded(
                child: Text(
                  DateFormat('MMMM yyyy').format(month),
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleLarge,
                ),
              ),
              IconButton(
                onPressed:
                    month.year == DateTime.now().year &&
                        month.month == DateTime.now().month
                    ? null
                    : () => move(1),
                icon: const Icon(Icons.chevron_right_rounded),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          summaryState.when(
            data: (summary) => PgCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Monthly Summary (IST)',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  Wrap(
                    spacing: AppSpacing.md,
                    runSpacing: AppSpacing.sm,
                    children: [
                      _SummaryChip('Present', '${summary.presentDays}'),
                      _SummaryChip('Half Day', '${summary.halfDays}'),
                      _SummaryChip('Absent', '${summary.absentDays}'),
                      _SummaryChip('Working', '${summary.workingDays}'),
                      _SummaryChip('Punch In', '${summary.punchInDays}'),
                      _SummaryChip('Punch Out', '${summary.punchOutDays}'),
                    ],
                  ),
                ],
              ),
            ),
            loading: () => const PgLoadingState(height: 80),
            error: (_, _) => const SizedBox.shrink(),
          ),
          const SizedBox(height: AppSpacing.md),
          state.when(
            data: (items) => _Calendar(
              month: month,
              items: items,
              onTap: (a) => context.push('/attendance/detail', extra: a),
            ),
            loading: () => const PgLoadingState(height: 280),
            error: (e, _) => PgErrorState(
              message: '$e',
              onRetry: () => ref.invalidate(attendanceMonthProvider(month)),
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          const Wrap(
            spacing: 14,
            runSpacing: 8,
            children: [
              _Legend('Present', AppColors.approvedFg),
              _Legend('Half Day', AppColors.pendingFg),
              _Legend('Punched In', AppColors.info),
              _Legend('Absent', AppColors.rejectedFg),
              _Legend('Holiday', AppColors.dispatchedFg),
            ],
          ),
        ],
      ),
    );
  }
}

class _Calendar extends StatelessWidget {
  const _Calendar({
    required this.month,
    required this.items,
    required this.onTap,
  });
  final DateTime month;
  final List<Attendance> items;
  final ValueChanged<Attendance> onTap;
  Color color(String s) {
    final lower = s.toLowerCase();
    if (lower.contains('holiday')) return AppColors.dispatchedFg;
    if (lower.contains('half')) return AppColors.pendingFg;
    if (lower.contains('punched')) return AppColors.info;
    if (lower.contains('present')) return AppColors.approvedFg;
    return AppColors.rejectedFg;
  }
  @override
  Widget build(BuildContext context) {
    final offset = DateTime(month.year, month.month, 1).weekday - 1,
        days = DateTime(month.year, month.month + 1, 0).day;
    return Column(
      children: [
        Row(
          children: [
            for (final d in const ['M', 'T', 'W', 'T', 'F', 'S', 'S'])
              Expanded(child: Text(d, textAlign: TextAlign.center)),
          ],
        ),
        const SizedBox(height: AppSpacing.sm),
        GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 7,
            mainAxisSpacing: 6,
            crossAxisSpacing: 6,
          ),
          itemCount: offset + days,
          itemBuilder: (context, i) {
            if (i < offset) return const SizedBox();
            final day = i - offset + 1;
            Attendance? a;
            for (final x in items) {
              if (x.date.day == day) {
                a = x;
                break;
              }
            }
            return InkWell(
              borderRadius: BorderRadius.circular(12),
              onTap: a == null ? null : () => onTap(a!),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 220),
                decoration: BoxDecoration(
                  color: a == null
                      ? Theme.of(context).colorScheme.surfaceContainerHighest
                      : color(a.status).withValues(alpha: .18),
                  borderRadius: BorderRadius.circular(12),
                  border: a == null ? null : Border.all(color: color(a.status)),
                ),
                child: Center(
                  child: Text(
                    '$day',
                    style: TextStyle(
                      fontWeight: a == null
                          ? FontWeight.normal
                          : FontWeight.bold,
                    ),
                  ),
                ),
              ),
            );
          },
        ),
      ],
    );
  }
}

class _Legend extends StatelessWidget {
  const _Legend(this.text, this.color);
  final String text;
  final Color color;
  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      CircleAvatar(radius: 5, backgroundColor: color),
      const SizedBox(width: 6),
      Text(text),
    ],
  );
}

class _SummaryChip extends StatelessWidget {
  const _SummaryChip(this.label, this.value);
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(value, style: Theme.of(context).textTheme.titleMedium),
      Text(label, style: Theme.of(context).textTheme.bodySmall),
    ],
  );
}
