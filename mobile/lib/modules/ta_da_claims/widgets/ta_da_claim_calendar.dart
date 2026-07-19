import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../models/ta_da_claim_calendar_data.dart';

class TaDaClaimCalendar extends StatelessWidget {
  const TaDaClaimCalendar({
    super.key,
    required this.month,
    required this.year,
    required this.claimsByDate,
    required this.onPreviousMonth,
    required this.onNextMonth,
    required this.canGoNextMonth,
    required this.onDateTap,
    this.loading = false,
  });

  final int month;
  final int year;
  final Map<DateTime, TaDaClaimCalendarEntry> claimsByDate;
  final VoidCallback onPreviousMonth;
  final VoidCallback? onNextMonth;
  final bool canGoNextMonth;
  final void Function(DateTime date, TaDaClaimCalendarEntry? claim) onDateTap;
  final bool loading;

  static DateTime dateOnly(DateTime date) =>
      DateTime(date.year, date.month, date.day);

  static DateTime get today => dateOnly(DateTime.now());

  @override
  Widget build(BuildContext context) {
    final monthLabel = DateFormat('MMMM yyyy').format(DateTime(year, month, 1));
    final firstDay = DateTime(year, month, 1);
    final daysInMonth = DateTime(year, month + 1, 0).day;
    final leadingEmpty = firstDay.weekday % 7;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                IconButton(
                  onPressed: loading ? null : onPreviousMonth,
                  icon: const Icon(Icons.chevron_left),
                ),
                Expanded(
                  child: Text(
                    monthLabel,
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                IconButton(
                  onPressed: loading || !canGoNextMonth ? null : onNextMonth,
                  icon: const Icon(Icons.chevron_right),
                ),
              ],
            ),
            if (loading)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 24),
                child: Center(child: CircularProgressIndicator()),
              )
            else ...[
              Row(
                children: const [
                  Expanded(child: Center(child: Text('S'))),
                  Expanded(child: Center(child: Text('M'))),
                  Expanded(child: Center(child: Text('T'))),
                  Expanded(child: Center(child: Text('W'))),
                  Expanded(child: Center(child: Text('T'))),
                  Expanded(child: Center(child: Text('F'))),
                  Expanded(child: Center(child: Text('S'))),
                ],
              ),
              const SizedBox(height: 8),
              GridView.builder(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 7,
                  mainAxisSpacing: 6,
                  crossAxisSpacing: 6,
                ),
                itemCount: leadingEmpty + daysInMonth,
                itemBuilder: (context, index) {
                  if (index < leadingEmpty) return const SizedBox.shrink();

                  final day = index - leadingEmpty + 1;
                  final date = dateOnly(DateTime(year, month, day));
                  final claim = claimsByDate[date];
                  final isToday = date == today;
                  final isFuture = date.isAfter(today);
                  final status = claim?.status;

                  Color? fillColor;
                  Color borderColor = Colors.transparent;
                  double borderWidth = 1;

                  if (isToday) {
                    borderColor = Theme.of(context).colorScheme.primary;
                    borderWidth = 2;
                  }

                  if (status == 'pending') {
                    fillColor = Colors.orange.shade100;
                  } else if (status == 'approved') {
                    fillColor = Colors.green.shade100;
                  } else if (status == 'rejected') {
                    fillColor = Colors.red.shade100;
                  } else if (status == 'paid') {
                    fillColor = Colors.blue.shade100;
                  }

                  return Material(
                    color: fillColor ?? Theme.of(context).colorScheme.surface,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(8),
                      side: BorderSide(color: borderColor, width: borderWidth),
                    ),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(8),
                      onTap: isFuture && claim == null
                          ? null
                          : () => onDateTap(date, claim),
                      child: Center(
                        child: Text(
                          '$day',
                          style: Theme.of(context).textTheme.bodyMedium
                              ?.copyWith(
                                color: isFuture && claim == null
                                    ? Theme.of(context).disabledColor
                                    : null,
                                fontWeight: isToday
                                    ? FontWeight.bold
                                    : FontWeight.normal,
                              ),
                        ),
                      ),
                    ),
                  );
                },
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 12,
                runSpacing: 4,
                children: const [
                  _LegendDot(color: Colors.orange, label: 'Pending'),
                  _LegendDot(color: Colors.green, label: 'Approved'),
                  _LegendDot(color: Colors.blue, label: 'Paid'),
                  _LegendDot(color: Colors.red, label: 'Rejected'),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _LegendDot extends StatelessWidget {
  const _LegendDot({required this.color, required this.label});

  final Color color;
  final String label;

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Container(
        width: 10,
        height: 10,
        decoration: BoxDecoration(color: color, shape: BoxShape.circle),
      ),
      const SizedBox(width: 4),
      Text(label, style: Theme.of(context).textTheme.bodySmall),
    ],
  );
}
