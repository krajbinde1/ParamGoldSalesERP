class DashboardData {
  const DashboardData({
    required this.attendanceStatus,
    required this.todayFieldActivities,
    required this.todayDealerVisits,
    required this.weeklySalesTarget,
    required this.weeklySalesAchieved,
    required this.weeklySalesPercentage,
    required this.weeklyCollectionTarget,
    required this.weeklyCollectionAchieved,
    required this.weeklyCollectionPercentage,
  });

  final String attendanceStatus;
  final int todayFieldActivities;
  final int todayDealerVisits;
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

    double readAmount(String rootKey, String summaryKey) =>
        _asDouble(json[rootKey]) ?? _asDouble(summary[summaryKey]) ?? 0;

    return DashboardData(
      attendanceStatus: attendance['status']?.toString() ?? 'absent',
      todayFieldActivities:
          _asInt(json['today_field_activities']) ??
          _asInt(summary['today_field_activities']) ??
          0,
      todayDealerVisits:
          _asInt(json['today_dealer_visits']) ??
          _asInt(summary['today_dealer_visits']) ??
          0,
      weeklySalesTarget: readAmount(
        'weekly_sales_target',
        'weekly_sales_target',
      ),
      weeklySalesAchieved: readAmount(
        'weekly_sales_achieved',
        'weekly_sales_achieved',
      ),
      weeklySalesPercentage: readAmount(
        'weekly_sales_percentage',
        'weekly_sales_percentage',
      ),
      weeklyCollectionTarget: readAmount(
        'weekly_collection_target',
        'weekly_collection_target',
      ),
      weeklyCollectionAchieved: readAmount(
        'weekly_collection_achieved',
        'weekly_collection_achieved',
      ),
      weeklyCollectionPercentage: readAmount(
        'weekly_collection_percentage',
        'weekly_collection_percentage',
      ),
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
