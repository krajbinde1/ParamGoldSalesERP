import 'package:dio/dio.dart';

import '../models/employee_task.dart';

class EmployeeTaskApi {
  const EmployeeTaskApi(this._dio);
  final Dio _dio;

  Future<EmployeeTaskListResult> list({String filter = 'today'}) async {
    final response = await _dio.get(
      '/employee/tasks',
      queryParameters: {'filter': filter},
    );
    return EmployeeTaskListResult.fromJson(
      Map<String, dynamic>.from(response.data as Map),
    );
  }

  Future<EmployeeTask> create({
    required String title,
    String? note,
    String? dueDate,
    String? dueTime,
    bool isImportant = false,
    String? reminderAt,
  }) async {
    final response = await _dio.post(
      '/employee/tasks',
      data: {
        'title': title.trim(),
        if (note != null) 'note': note,
        if (dueDate != null) 'due_date': dueDate,
        if (dueTime != null) 'due_time': dueTime,
        'is_important': isImportant,
        if (reminderAt != null) 'reminder_at': reminderAt,
      },
    );
    return _taskFromBody(response.data);
  }

  Future<EmployeeTask> update(
    int id, {
    String? title,
    String? note,
    String? dueDate,
    String? dueTime,
    bool? isImportant,
    bool? isCompleted,
    String? reminderAt,
    bool clearNote = false,
    bool clearDueTime = false,
    bool clearReminder = false,
  }) async {
    final data = <String, dynamic>{
      if (title != null) 'title': title.trim(),
      if (clearNote) 'note': null else if (note != null) 'note': note,
      if (dueDate != null) 'due_date': dueDate,
      if (clearDueTime)
        'due_time': null
      else if (dueTime != null)
        'due_time': dueTime,
      if (isImportant != null) 'is_important': isImportant,
      if (isCompleted != null) 'is_completed': isCompleted,
      if (clearReminder)
        'reminder_at': null
      else if (reminderAt != null)
        'reminder_at': reminderAt,
    };
    final response = await _dio.put('/employee/tasks/$id', data: data);
    return _taskFromBody(response.data);
  }

  Future<EmployeeTask> complete(int id) async {
    final response = await _dio.post('/employee/tasks/$id/complete');
    return _taskFromBody(response.data);
  }

  Future<EmployeeTask> incomplete(int id) async {
    final response = await _dio.post('/employee/tasks/$id/incomplete');
    return _taskFromBody(response.data);
  }

  Future<EmployeeTask> moveToTomorrow(int id) async {
    final response = await _dio.post('/employee/tasks/$id/move-to-tomorrow');
    return _taskFromBody(response.data);
  }

  Future<void> delete(int id) async {
    await _dio.delete('/employee/tasks/$id');
  }

  EmployeeTask _taskFromBody(Object? body) {
    if (body is! Map) {
      throw DioException(
        requestOptions: RequestOptions(path: '/employee/tasks'),
        message: 'Invalid task response.',
      );
    }
    final root = Map<String, dynamic>.from(body);
    final data = root['data'] is Map
        ? Map<String, dynamic>.from(root['data'] as Map)
        : root;
    return EmployeeTask.fromJson(data);
  }
}
