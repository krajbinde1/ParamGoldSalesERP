class DealerVisitSummary {
  const DealerVisitSummary({
    required this.totalVisits,
    required this.weekVisits,
    required this.todayVisits,
  });

  final int totalVisits;
  final int weekVisits;
  final int todayVisits;

  factory DealerVisitSummary.fromJson(Map<String, dynamic> json) =>
      DealerVisitSummary(
        totalVisits: int.tryParse('${json['total_visits'] ?? 0}') ?? 0,
        weekVisits: int.tryParse('${json['week_visits'] ?? 0}') ?? 0,
        todayVisits: int.tryParse('${json['today_visits'] ?? 0}') ?? 0,
      );

  static const empty = DealerVisitSummary(
    totalVisits: 0,
    weekVisits: 0,
    todayVisits: 0,
  );
}
