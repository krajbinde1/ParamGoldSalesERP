import 'dealer_visit_item.dart';
import 'dealer_visit_summary.dart';

class DealerVisitDashboardData {
  const DealerVisitDashboardData({
    required this.summary,
    required this.recentVisits,
  });

  final DealerVisitSummary summary;
  final List<DealerVisitItem> recentVisits;

  factory DealerVisitDashboardData.fromJson(Map<String, dynamic> json) {
    final summaryJson = json['summary'] is Map
        ? Map<String, dynamic>.from(json['summary'] as Map)
        : <String, dynamic>{};
    final rawItems = json['recent_visits'] ?? const [];

    return DealerVisitDashboardData(
      summary: DealerVisitSummary.fromJson(summaryJson),
      recentVisits: rawItems is List
          ? rawItems
                .map(
                  (item) => DealerVisitItem.fromJson(
                    Map<String, dynamic>.from(item as Map),
                  ),
                )
                .toList()
          : const [],
    );
  }

  static const empty = DealerVisitDashboardData(
    summary: DealerVisitSummary.empty,
    recentVisits: [],
  );
}
