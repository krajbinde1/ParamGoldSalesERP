class TaDaClaimSummary {
  const TaDaClaimSummary({
    required this.totalClaims,
    required this.monthClaims,
    required this.pendingClaims,
    required this.approvedClaims,
  });

  final int totalClaims;
  final int monthClaims;
  final int pendingClaims;
  final int approvedClaims;

  factory TaDaClaimSummary.fromJson(Map<String, dynamic> json) =>
      TaDaClaimSummary(
        totalClaims: int.tryParse('${json['total_claims'] ?? 0}') ?? 0,
        monthClaims: int.tryParse('${json['month_claims'] ?? 0}') ?? 0,
        pendingClaims: int.tryParse('${json['pending_claims'] ?? 0}') ?? 0,
        approvedClaims: int.tryParse('${json['approved_claims'] ?? 0}') ?? 0,
      );

  static const empty = TaDaClaimSummary(
    totalClaims: 0,
    monthClaims: 0,
    pendingClaims: 0,
    approvedClaims: 0,
  );
}
