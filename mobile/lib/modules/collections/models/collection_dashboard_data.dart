import 'collection_item.dart';
import 'collection_summary.dart';

class CollectionDashboardData {
  const CollectionDashboardData({
    required this.summary,
    required this.recentCollections,
  });

  final CollectionSummary summary;
  final List<CollectionItem> recentCollections;

  factory CollectionDashboardData.fromJson(Map<String, dynamic> json) {
    final summaryJson = json['summary'] is Map
        ? Map<String, dynamic>.from(json['summary'] as Map)
        : <String, dynamic>{};
    final rawItems = json['recent_collections'] ?? const [];

    return CollectionDashboardData(
      summary: CollectionSummary.fromJson(summaryJson),
      recentCollections: rawItems is List
          ? rawItems
                .map(
                  (item) => CollectionItem.fromJson(
                    Map<String, dynamic>.from(item as Map),
                  ),
                )
                .toList()
          : const [],
    );
  }

  static const empty = CollectionDashboardData(
    summary: CollectionSummary.empty,
    recentCollections: [],
  );
}
