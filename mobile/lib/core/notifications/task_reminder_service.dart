import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:timezone/data/latest_all.dart' as tz_data;
import 'package:timezone/timezone.dart' as tz;

import '../../modules/planning/models/employee_task.dart';
import 'push_notification_service.dart';

/// Local-only task reminders for Employee Today's Planning.
/// Intentionally separate from Order FCM / device-token registration.
class TaskReminderService {
  TaskReminderService._();
  static final TaskReminderService instance = TaskReminderService._();

  static const AndroidNotificationChannel channel = AndroidNotificationChannel(
    'task_reminders',
    'Task Reminders',
    description: 'Local reminders for Today\'s Planning tasks',
    importance: Importance.high,
    playSound: true,
  );

  bool _tzReady = false;

  Future<void> _ensureReady() async {
    if (kIsWeb) return;
    try {
      await PushNotificationService.instance.ensureLocalInitialized();
      final android = PushNotificationService.instance.localPlugin
          .resolvePlatformSpecificImplementation<
              AndroidFlutterLocalNotificationsPlugin>();
      await android?.createNotificationChannel(channel);
      if (Platform.isAndroid) {
        await android?.requestNotificationsPermission();
      }
      if (!_tzReady) {
        tz_data.initializeTimeZones();
        tz.setLocalLocation(tz.getLocation('Asia/Kolkata'));
        _tzReady = true;
      }
    } catch (error, stack) {
      debugPrint('TaskReminderService init failed (non-blocking): $error\n$stack');
    }
  }

  /// Notification id derived from task id (stable + unique in app range).
  int notificationIdFor(int taskId) => 700000 + (taskId % 100000);

  Future<void> syncReminder(EmployeeTask task) async {
    try {
      await cancel(task.id);
      final when = task.reminderAt;
      if (task.isCompleted || when == null) return;
      if (!when.isAfter(DateTime.now())) return;

      await _ensureReady();
      final plugin = PushNotificationService.instance.localPlugin;
      final scheduled = tz.TZDateTime.from(when.toLocal(), tz.local);

      await plugin.zonedSchedule(
        id: notificationIdFor(task.id),
        title: 'Reminder',
        body: task.title,
        scheduledDate: scheduled,
        notificationDetails: NotificationDetails(
          android: AndroidNotificationDetails(
            channel.id,
            channel.name,
            channelDescription: channel.description,
            importance: Importance.high,
            priority: Priority.high,
            icon: '@mipmap/ic_launcher',
          ),
          iOS: const DarwinNotificationDetails(),
        ),
        androidScheduleMode: AndroidScheduleMode.inexactAllowWhileIdle,
        payload: '{"route":"/planning","task_id":${task.id}}',
      );
    } catch (error, stack) {
      debugPrint('Task reminder schedule failed (non-blocking): $error\n$stack');
    }
  }

  Future<void> cancel(int taskId) async {
    try {
      await _ensureReady();
      await PushNotificationService.instance.localPlugin
          .cancel(id: notificationIdFor(taskId));
    } catch (error) {
      debugPrint('Task reminder cancel failed (non-blocking): $error');
    }
  }
}
