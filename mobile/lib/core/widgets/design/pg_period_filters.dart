import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

class TargetPeriodOption {
  const TargetPeriodOption(this.label, this.value);

  final String label;
  final String value;
}

class PgPeriodFilters extends StatelessWidget {
  const PgPeriodFilters({
    super.key,
    required this.selected,
    required this.onSelected,
    required this.onCustom,
  });

  final String selected;
  final ValueChanged<String> onSelected;
  final VoidCallback onCustom;

  static const options = <TargetPeriodOption>[
    TargetPeriodOption('Last Week', 'last_week'),
    TargetPeriodOption('This Week', 'week'),
    TargetPeriodOption('Last Month', 'last_month'),
    TargetPeriodOption('This Month', 'month'),
    TargetPeriodOption('Custom Date Range', 'custom'),
  ];

  static String labelFor(String period) => switch (period) {
        'last_week' => 'Last Week',
        'week' => 'This Week',
        'last_month' => 'Last Month',
        'month' => 'This Month',
        'custom' => 'Custom Date Range',
        _ => period,
      };

  static String formatRange(String? startDate, String? endDate) {
    if (startDate == null || startDate.isEmpty || endDate == null || endDate.isEmpty) {
      return '';
    }
    final start = DateTime.tryParse(startDate);
    final end = DateTime.tryParse(endDate);
    if (start == null || end == null) {
      return '$startDate – $endDate';
    }
    final format = DateFormat('dd MMM yyyy');
    return '${format.format(start)} – ${format.format(end)}';
  }

  static String percentLabel(double? percentage, {required double target}) {
    if (target <= 0 || percentage == null) return 'N/A';
    if (percentage == percentage.roundToDouble()) {
      return '${percentage.toInt()}%';
    }
    return '${percentage.toStringAsFixed(1)}%';
  }

  static double? percentValue(Object? raw, {required double target}) {
    if (target <= 0 || raw == null) return null;
    if (raw is num) return raw.toDouble();
    return double.tryParse('$raw');
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          for (final option in options)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: ChoiceChip(
                label: Text(option.label),
                selected: selected == option.value,
                onSelected: (_) {
                  if (option.value == 'custom') {
                    onCustom();
                  } else {
                    onSelected(option.value);
                  }
                },
              ),
            ),
        ],
      ),
    );
  }
}
