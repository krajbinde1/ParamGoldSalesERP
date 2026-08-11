/// Date helpers for TA/DA claim flows (new claim date picker, etc.).
/// Calendar UI was removed from the employee TA/DA dashboard.
class TaDaClaimCalendar {
  const TaDaClaimCalendar._();

  static DateTime dateOnly(DateTime date) =>
      DateTime(date.year, date.month, date.day);

  static DateTime get today => dateOnly(DateTime.now());
}
