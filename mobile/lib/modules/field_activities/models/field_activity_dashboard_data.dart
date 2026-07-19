import 'field_activity_item.dart';
import 'field_activity_summary.dart';

class FieldActivityDashboardData {
  const FieldActivityDashboardData({
    required this.summary,
    required this.recentActivities,
  });

  final FieldActivitySummary summary;
  final List<FieldActivityItem> recentActivities;

  factory FieldActivityDashboardData.fromJson(Map<String, dynamic> json) {
    final summaryJson = json['summary'] is Map
        ? Map<String, dynamic>.from(json['summary'] as Map)
        : <String, dynamic>{};
    final rawItems = json['recent_activities'] ?? const [];

    return FieldActivityDashboardData(
      summary: FieldActivitySummary.fromJson(summaryJson),
      recentActivities: rawItems is List
          ? rawItems
                .map(
                  (item) => FieldActivityItem.fromJson(
                    Map<String, dynamic>.from(item as Map),
                  ),
                )
                .toList()
          : const [],
    );
  }

  static const empty = FieldActivityDashboardData(
    summary: FieldActivitySummary.empty,
    recentActivities: [],
  );
}
