class EmployeeTask {
  const EmployeeTask({
    required this.id,
    required this.title,
    this.note,
    required this.dueDate,
    this.dueTime,
    required this.isImportant,
    required this.isCompleted,
    this.completedAt,
    this.reminderAt,
    this.createdAt,
    this.updatedAt,
  });

  final int id;
  final String title;
  final String? note;
  final DateTime dueDate;
  final String? dueTime;
  final bool isImportant;
  final bool isCompleted;
  final DateTime? completedAt;
  final DateTime? reminderAt;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  factory EmployeeTask.fromJson(Map<String, dynamic> json) {
    return EmployeeTask(
      id: int.tryParse('${json['id']}') ?? 0,
      title: json['title']?.toString() ?? '',
      note: _nullableString(json['note']),
      dueDate: _parseDate(json['due_date']) ?? DateTime.now(),
      dueTime: _nullableString(json['due_time']),
      isImportant: json['is_important'] == true,
      isCompleted: json['is_completed'] == true,
      completedAt: _parseDateTime(json['completed_at']),
      reminderAt: _parseDateTime(json['reminder_at']),
      createdAt: _parseDateTime(json['created_at']),
      updatedAt: _parseDateTime(json['updated_at']),
    );
  }

  EmployeeTask copyWith({
    String? title,
    String? note,
    DateTime? dueDate,
    String? dueTime,
    bool? isImportant,
    bool? isCompleted,
    DateTime? completedAt,
    DateTime? reminderAt,
    bool clearNote = false,
    bool clearDueTime = false,
    bool clearReminder = false,
    bool clearCompletedAt = false,
  }) {
    return EmployeeTask(
      id: id,
      title: title ?? this.title,
      note: clearNote ? null : (note ?? this.note),
      dueDate: dueDate ?? this.dueDate,
      dueTime: clearDueTime ? null : (dueTime ?? this.dueTime),
      isImportant: isImportant ?? this.isImportant,
      isCompleted: isCompleted ?? this.isCompleted,
      completedAt:
          clearCompletedAt ? null : (completedAt ?? this.completedAt),
      reminderAt: clearReminder ? null : (reminderAt ?? this.reminderAt),
      createdAt: createdAt,
      updatedAt: updatedAt,
    );
  }

  static String? _nullableString(Object? value) {
    if (value == null) return null;
    final text = '$value'.trim();
    return text.isEmpty ? null : text;
  }

  static DateTime? _parseDate(Object? value) {
    if (value == null) return null;
    final text = '$value'.trim();
    if (text.isEmpty) return null;
    final parts = text.split('-');
    if (parts.length >= 3) {
      return DateTime(
        int.tryParse(parts[0]) ?? 0,
        int.tryParse(parts[1]) ?? 1,
        int.tryParse(parts[2].split('T').first) ?? 1,
      );
    }
    return DateTime.tryParse(text);
  }

  static DateTime? _parseDateTime(Object? value) {
    if (value == null) return null;
    final text = '$value'.trim();
    if (text.isEmpty) return null;
    return DateTime.tryParse(text);
  }
}

class EmployeeTaskListResult {
  const EmployeeTaskListResult({
    required this.filter,
    required this.todayPending,
    required this.todayCompleted,
    required this.overdueCount,
    this.pending = const [],
    this.completed = const [],
    this.overdue = const [],
    this.tasks = const [],
  });

  final String filter;
  final int todayPending;
  final int todayCompleted;
  final int overdueCount;
  final List<EmployeeTask> pending;
  final List<EmployeeTask> completed;
  final List<EmployeeTask> overdue;
  final List<EmployeeTask> tasks;

  factory EmployeeTaskListResult.fromJson(Map<String, dynamic> json) {
    final counts = Map<String, dynamic>.from(json['counts'] as Map? ?? const {});
    List<EmployeeTask> parseList(Object? raw) {
      if (raw is! List) return const [];
      return raw
          .whereType<Map>()
          .map((item) => EmployeeTask.fromJson(Map<String, dynamic>.from(item)))
          .toList();
    }

    return EmployeeTaskListResult(
      filter: json['filter']?.toString() ?? 'today',
      todayPending: int.tryParse('${counts['today_pending'] ?? 0}') ?? 0,
      todayCompleted: int.tryParse('${counts['today_completed'] ?? 0}') ?? 0,
      overdueCount: int.tryParse('${counts['overdue_count'] ?? 0}') ?? 0,
      pending: parseList(json['pending']),
      completed: parseList(json['completed']),
      overdue: parseList(json['overdue']),
      tasks: parseList(json['tasks']),
    );
  }
}
