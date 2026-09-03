class DashboardData {
  const DashboardData({
    required this.attendanceStatus,
    this.attendancePunchIn,
    this.attendancePunchOut,
    this.attendanceWorkingHours,
    required this.todayFieldActivities,
    required this.todayDealerVisits,
    this.todayPlanningPending = 0,
    this.todayPlanningCompleted = 0,
    this.periodLabel = 'This Week',
    this.periodKey = 'week',
    this.startDate,
    this.endDate,
    required this.weeklySalesTarget,
    required this.weeklySalesAchieved,
    this.weeklySalesPercentage,
    required this.weeklyCollectionTarget,
    required this.weeklyCollectionAchieved,
    this.weeklyCollectionPercentage,
    this.weeklySalesRemaining = 0,
    this.weeklyCollectionRemaining = 0,
    this.fieldActivityTarget = 0,
    this.fieldActivityAchieved = 0,
    this.fieldActivityRemaining = 0,
    this.fieldActivityPercentage,
  });

  final String attendanceStatus;
  final String? attendancePunchIn;
  final String? attendancePunchOut;
  final String? attendanceWorkingHours;
  final int todayFieldActivities;
  final int todayDealerVisits;
  final int todayPlanningPending;
  final int todayPlanningCompleted;
  final String periodLabel;
  final String periodKey;
  final String? startDate;
  final String? endDate;
  final double weeklySalesTarget;
  final double weeklySalesAchieved;
  final double? weeklySalesPercentage;
  final double weeklyCollectionTarget;
  final double weeklyCollectionAchieved;
  final double? weeklyCollectionPercentage;
  final double weeklySalesRemaining;
  final double weeklyCollectionRemaining;
  final double fieldActivityTarget;
  final double fieldActivityAchieved;
  final double fieldActivityRemaining;
  final double? fieldActivityPercentage;

  factory DashboardData.fromJson(Map<String, dynamic> json) {
    final attendance = Map<String, dynamic>.from(
      json['today_attendance'] as Map? ?? const {},
    );
    final summary = Map<String, dynamic>.from(
      json['summary'] as Map? ?? const {},
    );
    final planning = Map<String, dynamic>.from(
      json['today_planning'] as Map? ?? const {},
    );

    double readAmount(List<String> keys) {
      for (final key in keys) {
        final value = _asDouble(json[key]) ?? _asDouble(summary[key]);
        if (value != null) return value;
      }
      return 0;
    }

    double? readPercent(List<String> keys) {
      for (final key in keys) {
        if (json.containsKey(key)) return _asDouble(json[key]);
        if (summary.containsKey(key)) return _asDouble(summary[key]);
      }
      return null;
    }

    return DashboardData(
      attendanceStatus: attendance['status']?.toString() ?? 'absent',
      attendancePunchIn: attendance['punch_in']?.toString(),
      attendancePunchOut: attendance['punch_out']?.toString(),
      attendanceWorkingHours: attendance['working_hours']?.toString(),
      todayFieldActivities:
          _asInt(json['today_field_activities']) ??
          _asInt(summary['today_field_activities']) ??
          0,
      todayDealerVisits:
          _asInt(json['today_dealer_visits']) ??
          _asInt(summary['today_dealer_visits']) ??
          0,
      todayPlanningPending: _asInt(planning['pending']) ?? 0,
      todayPlanningCompleted: _asInt(planning['completed']) ?? 0,
      periodLabel: json['period']?.toString() ?? 'This Week',
      periodKey: json['period_key']?.toString() ?? 'week',
      startDate: json['start_date']?.toString(),
      endDate: json['end_date']?.toString(),
      weeklySalesTarget: readAmount(const [
        'sales_target',
        'weekly_sales_target',
      ]),
      weeklySalesAchieved: readAmount(const [
        'sales_achieved',
        'weekly_sales_achieved',
      ]),
      weeklySalesPercentage: readPercent(const [
        'sales_percentage',
        'weekly_sales_percentage',
      ]),
      weeklyCollectionTarget: readAmount(const [
        'collection_target',
        'weekly_collection_target',
      ]),
      weeklyCollectionAchieved: readAmount(const [
        'collection_achieved',
        'weekly_collection_achieved',
      ]),
      weeklyCollectionPercentage: readPercent(const [
        'collection_percentage',
        'weekly_collection_percentage',
      ]),
      weeklySalesRemaining: readAmount(const [
        'sales_remaining',
        'weekly_sales_remaining',
      ]),
      weeklyCollectionRemaining: readAmount(const [
        'collection_remaining',
        'weekly_collection_remaining',
      ]),
      fieldActivityTarget: readAmount(const [
        'field_activity_target',
        'weekly_field_activity_target',
      ]),
      fieldActivityAchieved: readAmount(const [
        'field_activity_achieved',
        'weekly_field_activity_achieved',
      ]),
      fieldActivityRemaining: readAmount(const [
        'field_activity_remaining',
        'weekly_field_activity_remaining',
      ]),
      fieldActivityPercentage: readPercent(const [
        'field_activity_percentage',
        'weekly_field_activity_percentage',
      ]),
    );
  }

  static int? _asInt(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse('$value');
  }

  static double? _asDouble(Object? value) {
    if (value == null) return null;
    if (value is double) return value;
    if (value is num) return value.toDouble();
    return double.tryParse('$value');
  }
}
