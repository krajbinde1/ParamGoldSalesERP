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
    required this.weeklySalesTarget,
    required this.weeklySalesAchieved,
    required this.weeklySalesPercentage,
    required this.weeklyCollectionTarget,
    required this.weeklyCollectionAchieved,
    required this.weeklyCollectionPercentage,
  });

  final String attendanceStatus;
  final String? attendancePunchIn;
  final String? attendancePunchOut;
  final String? attendanceWorkingHours;
  final int todayFieldActivities;
  final int todayDealerVisits;
  final int todayPlanningPending;
  final int todayPlanningCompleted;
  final double weeklySalesTarget;
  final double weeklySalesAchieved;
  final double weeklySalesPercentage;
  final double weeklyCollectionTarget;
  final double weeklyCollectionAchieved;
  final double weeklyCollectionPercentage;

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
      weeklySalesTarget: readAmount(const [
        'sales_target',
        'weekly_sales_target',
      ]),
      weeklySalesAchieved: readAmount(const [
        'sales_achieved',
        'weekly_sales_achieved',
      ]),
      weeklySalesPercentage: readAmount(const [
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
      weeklyCollectionPercentage: readAmount(const [
        'collection_percentage',
        'weekly_collection_percentage',
      ]),
    );
  }

  static int? _asInt(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse('$value');
  }

  static double? _asDouble(Object? value) {
    if (value is double) return value;
    if (value is num) return value.toDouble();
    return double.tryParse('$value');
  }
}
