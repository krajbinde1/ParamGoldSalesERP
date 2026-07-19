import 'package:intl/intl.dart';

class AttendanceFormat {
  static const Duration istOffset = Duration(hours: 5, minutes: 30);

  static String date(DateTime value) => DateFormat('dd MMM yyyy').format(value);

  static String time(DateTime? value) =>
      value == null ? '—' : DateFormat('hh:mm a').format(value);

  static DateTime istNow() => toIstWallClock(DateTime.now().toUtc());

  static DateTime toIstWallClock(DateTime value) {
    final ist = value.toUtc().add(istOffset);

    return DateTime(
      ist.year,
      ist.month,
      ist.day,
      ist.hour,
      ist.minute,
      ist.second,
    );
  }

  static DateTime parseDate(dynamic value) {
    final text = '${value ?? ''}';
    final parts = text.split('-');
    if (parts.length >= 3) {
      return DateTime(
        int.parse(parts[0]),
        int.parse(parts[1]),
        int.parse(parts[2].split('T').first),
      );
    }

    return toIstWallClock(DateTime.tryParse(text) ?? DateTime.now().toUtc());
  }

  static DateTime? parseIstDateTime(DateTime date, dynamic value) {
    if (value == null || '$value'.isEmpty) {
      return null;
    }

    final text = '$value'.trim();

    if (RegExp(r'^\d{4}-\d{2}-\d{2}').hasMatch(text)) {
      final normalized = text.contains('T')
          ? text
          : text.replaceFirst(' ', 'T');
      final parsed = DateTime.tryParse(normalized);
      if (parsed != null) {
        return toIstWallClock(parsed);
      }
    } else if (text.contains('T')) {
      final parsed = DateTime.tryParse(text);
      if (parsed != null) {
        return toIstWallClock(parsed);
      }
    }

    if (RegExp(r'[AP]M', caseSensitive: false).hasMatch(text)) {
      final parsed = DateFormat('h:mm a').parse(text);
      return DateTime(
        date.year,
        date.month,
        date.day,
        parsed.hour,
        parsed.minute,
      );
    }

    final parts = text.split(':');
    if (parts.length < 2) {
      return null;
    }

    final hourPart = parts[0].trim();
    final hour = int.tryParse(hourPart);
    if (hour == null) {
      return null;
    }

    return DateTime(
      date.year,
      date.month,
      date.day,
      hour,
      int.parse(parts[1]),
      parts.length > 2 ? int.parse(parts[2].split('.').first) : 0,
    );
  }
}
