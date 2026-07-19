import 'ta_da_claim_item.dart';
import 'ta_da_claim_summary.dart';

class TaDaClaimDashboardData {
  const TaDaClaimDashboardData({
    required this.summary,
    required this.recentClaims,
  });

  final TaDaClaimSummary summary;
  final List<TaDaClaimItem> recentClaims;

  factory TaDaClaimDashboardData.fromJson(Map<String, dynamic> json) {
    final summaryJson = json['summary'] is Map
        ? Map<String, dynamic>.from(json['summary'] as Map)
        : <String, dynamic>{};
    final rawItems = json['recent_claims'] ?? const [];

    return TaDaClaimDashboardData(
      summary: TaDaClaimSummary.fromJson(summaryJson),
      recentClaims: rawItems is List
          ? rawItems
                .map(
                  (item) => TaDaClaimItem.fromJson(
                    Map<String, dynamic>.from(item as Map),
                  ),
                )
                .toList()
          : const [],
    );
  }

  static const empty = TaDaClaimDashboardData(
    summary: TaDaClaimSummary.empty,
    recentClaims: [],
  );
}
