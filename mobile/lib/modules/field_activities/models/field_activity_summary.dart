class FieldActivitySummary {
  const FieldActivitySummary({
    required this.totalActivities,
    required this.monthActivities,
    required this.weekActivities,
    required this.todayActivities,
  });

  final int totalActivities;
  final int monthActivities;
  final int weekActivities;
  final int todayActivities;

  factory FieldActivitySummary.fromJson(Map<String, dynamic> json) =>
      FieldActivitySummary(
        totalActivities: int.tryParse('${json['total_activities'] ?? ''}') ?? 0,
        monthActivities: int.tryParse('${json['month_activities'] ?? ''}') ?? 0,
        weekActivities: int.tryParse('${json['week_activities'] ?? ''}') ?? 0,
        todayActivities: int.tryParse('${json['today_activities'] ?? ''}') ?? 0,
      );

  static const empty = FieldActivitySummary(
    totalActivities: 0,
    monthActivities: 0,
    weekActivities: 0,
    todayActivities: 0,
  );
}
