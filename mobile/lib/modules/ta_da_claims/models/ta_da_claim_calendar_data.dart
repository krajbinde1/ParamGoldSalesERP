import 'package:intl/intl.dart';

class TaDaClaimCalendarEntry {
  const TaDaClaimCalendarEntry({
    required this.id,
    required this.claimDate,
    required this.status,
  });

  final int id;
  final DateTime claimDate;
  final String status;

  factory TaDaClaimCalendarEntry.fromJson(Map<String, dynamic> json) =>
      TaDaClaimCalendarEntry(
        id: int.tryParse('${json['id'] ?? ''}') ?? 0,
        claimDate: DateFormat(
          'yyyy-MM-dd',
        ).parse(json['claim_date']?.toString() ?? DateTime.now().toString()),
        status: json['status']?.toString() ?? 'pending',
      );
}

class TaDaClaimCalendarData {
  const TaDaClaimCalendarData({
    required this.month,
    required this.year,
    required this.claims,
  });

  final int month;
  final int year;
  final List<TaDaClaimCalendarEntry> claims;

  factory TaDaClaimCalendarData.fromJson(Map<String, dynamic> json) {
    final rawClaims = json['claims'] ?? const [];

    return TaDaClaimCalendarData(
      month: int.tryParse('${json['month'] ?? 1}') ?? 1,
      year:
          int.tryParse('${json['year'] ?? DateTime.now().year}') ??
          DateTime.now().year,
      claims: rawClaims is List
          ? rawClaims
                .map(
                  (item) => TaDaClaimCalendarEntry.fromJson(
                    Map<String, dynamic>.from(item as Map),
                  ),
                )
                .toList()
          : const [],
    );
  }

  Map<DateTime, TaDaClaimCalendarEntry> get claimsByDate {
    final map = <DateTime, TaDaClaimCalendarEntry>{};
    for (final claim in claims) {
      map[_dateOnly(claim.claimDate)] = claim;
    }
    return map;
  }

  static DateTime _dateOnly(DateTime date) =>
      DateTime(date.year, date.month, date.day);
}
