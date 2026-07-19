class TaDaTravelSummary {
  const TaDaTravelSummary({
    required this.claimDate,
    required this.travelKm,
    required this.perKmRate,
    required this.travelAmount,
    required this.routeAvailable,
    this.attendanceId,
    this.validPointCount,
  });

  final DateTime claimDate;
  final double travelKm;
  final double perKmRate;
  final double travelAmount;
  final bool routeAvailable;
  final int? attendanceId;
  final int? validPointCount;

  factory TaDaTravelSummary.fromJson(Map<String, dynamic> json) =>
      TaDaTravelSummary(
        claimDate: DateTime.parse(
          json['claim_date']?.toString() ?? DateTime.now().toIso8601String(),
        ),
        travelKm: double.tryParse('${json['travel_km'] ?? ''}') ?? 0,
        perKmRate: double.tryParse('${json['per_km_rate'] ?? ''}') ?? 0,
        travelAmount: double.tryParse('${json['travel_amount'] ?? ''}') ?? 0,
        routeAvailable: json['route_available'] == true,
        attendanceId: int.tryParse('${json['attendance_id'] ?? ''}'),
        validPointCount: int.tryParse('${json['valid_point_count'] ?? ''}'),
      );
}
