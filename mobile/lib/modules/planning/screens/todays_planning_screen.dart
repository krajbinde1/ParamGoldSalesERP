import 'dart:async';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/notifications/task_reminder_service.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/employee_task_api.dart';
import '../models/employee_task.dart';

enum _TaskFilter { today, upcoming, overdue, completed }

class TodaysPlanningScreen extends StatefulWidget {
  const TodaysPlanningScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<TodaysPlanningScreen> createState() => _TodaysPlanningScreenState();
}

class _TodaysPlanningScreenState extends State<TodaysPlanningScreen> {
  late final EmployeeTaskApi _api;
  final _quickAdd = TextEditingController();
  final _quickFocus = FocusNode();

  _TaskFilter _filter = _TaskFilter.today;
  bool _loading = true;
  bool _adding = false;
  bool _completedExpanded = true;
  String? _error;
  EmployeeTaskListResult? _data;
  int _todayCount = 0;
  int _upcomingCount = 0;
  int _overdueCount = 0;
  int _completedCount = 0;

  @override
  void initState() {
    super.initState();
    _api = EmployeeTaskApi(
      ApiClient(
        SessionStore(),
        onUnauthorized: widget.auth.sessionExpired,
      ).dio,
    );
    _reload();
  }

  @override
  void dispose() {
    _quickAdd.dispose();
    _quickFocus.dispose();
    super.dispose();
  }

  Future<void> _reload() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      // Load every filter list so tab badges match the same rows each tab shows.
      final results = await Future.wait([
        _api.list(filter: 'today'),
        _api.list(filter: 'upcoming'),
        _api.list(filter: 'overdue'),
        _api.list(filter: 'completed'),
      ]);
      if (!mounted) return;

      final today = results[0];
      final upcoming = results[1];
      final overdue = results[2];
      final completed = results[3];
      final result = switch (_filter) {
        _TaskFilter.today => today,
        _TaskFilter.upcoming => upcoming,
        _TaskFilter.overdue => overdue,
        _TaskFilter.completed => completed,
      };

      setState(() {
        _todayCount = today.pending.length;
        _upcomingCount = upcoming.tasks.length;
        _overdueCount = overdue.tasks.length;
        _completedCount = completed.tasks.length;
        _data = result;
        _loading = false;
      });
      // Best-effort local reminder sync (never blocks UI).
      for (final task in [
        ...today.pending,
        ...today.overdue,
        ...upcoming.tasks,
        ...overdue.tasks,
        ...completed.tasks,
      ]) {
        unawaited(TaskReminderService.instance.syncReminder(task));
      }
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = errorMessage(error);
        _loading = false;
      });
    }
  }

  int _countFor(_TaskFilter filter) => switch (filter) {
        _TaskFilter.today => _todayCount,
        _TaskFilter.upcoming => _upcomingCount,
        _TaskFilter.overdue => _overdueCount,
        _TaskFilter.completed => _completedCount,
      };

  bool _isOverdueTask(EmployeeTask task) {
    if (task.isCompleted) return false;
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final due = DateTime(
      task.dueDate.year,
      task.dueDate.month,
      task.dueDate.day,
    );
    return due.isBefore(today);
  }

  String? _dueDateLine(EmployeeTask task) {
    final due = task.dueDate;
    // Guard against empty/invalid dates.
    if (due.year < 1970) return null;

    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final dueDay = DateTime(due.year, due.month, due.day);
    final datePart =
        dueDay == today ? 'Today' : DateFormat('EEE, d MMM yyyy').format(due);

    final timeRaw = task.dueTime?.trim();
    if (timeRaw == null || timeRaw.isEmpty) return datePart;
    final parts = timeRaw.split(':');
    if (parts.length < 2) return datePart;
    final hour = int.tryParse(parts[0]);
    final minute = int.tryParse(parts[1]);
    if (hour == null || minute == null) return datePart;
    final timePart = DateFormat('h:mm a').format(
      DateTime(due.year, due.month, due.day, hour, minute),
    );
    return '$datePart • $timePart';
  }

  Future<void> _quickAddTask() async {
    final title = _quickAdd.text.trim();
    if (title.isEmpty || _adding) return;
    setState(() => _adding = true);
    try {
      final task = await _api.create(title: title);
      _quickAdd.clear();
      unawaited(TaskReminderService.instance.syncReminder(task));
      await _reload();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    } finally {
      if (mounted) setState(() => _adding = false);
    }
  }

  Future<void> _toggleComplete(EmployeeTask task) async {
    try {
      final updated = task.isCompleted
          ? await _api.incomplete(task.id)
          : await _api.complete(task.id);
      unawaited(TaskReminderService.instance.syncReminder(updated));
      await _reload();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    }
  }

  Future<void> _deleteTask(EmployeeTask task) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delete task?'),
        content: Text('Remove "${task.title}" permanently?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await _api.delete(task.id);
      unawaited(TaskReminderService.instance.cancel(task.id));
      await _reload();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    }
  }

  Future<void> _moveToTomorrow(EmployeeTask task) async {
    try {
      final updated = await _api.moveToTomorrow(task.id);
      unawaited(TaskReminderService.instance.syncReminder(updated));
      await _reload();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Moved to tomorrow.')),
      );
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    }
  }

  Future<void> _openEditor(EmployeeTask? task) async {
    final changed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _TaskEditorSheet(
        api: _api,
        task: task,
      ),
    );
    if (changed == true) await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final dateLabel = DateFormat('EEEE, d MMM yyyy').format(DateTime.now());
    final data = _data;

    return PgPageScaffold(
      auth: widget.auth,
      title: "Today's Planning",
      showBack: true,
      body: RefreshIndicator(
        color: AppColors.primary,
        onRefresh: _reload,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.screenPadding),
          children: [
            Text(
              dateLabel,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: AppColors.textSecondary,
                    fontWeight: FontWeight.w600,
                  ),
            ),
            if (data != null) ...[
              const SizedBox(height: 4),
              Text(
                '${data.todayPending} Pending • ${data.todayCompleted} Done'
                '${data.overdueCount > 0 ? ' • ${data.overdueCount} Overdue' : ''}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: AppColors.textMuted,
                    ),
              ),
            ],
            const SizedBox(height: AppSpacing.md),
            PgCard(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
              child: Row(
                children: [
                  IconButton(
                    onPressed: _adding ? null : _quickAddTask,
                    icon: _adding
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.add_rounded),
                    color: AppColors.primary,
                  ),
                  Expanded(
                    child: TextField(
                      controller: _quickAdd,
                      focusNode: _quickFocus,
                      textInputAction: TextInputAction.done,
                      decoration: const InputDecoration(
                        hintText: 'Add a task',
                        border: InputBorder.none,
                        isDense: true,
                      ),
                      onSubmitted: (_) => _quickAddTask(),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: _TaskFilter.values.map((filter) {
                  final selected = _filter == filter;
                  final label = switch (filter) {
                    _TaskFilter.today => 'Today',
                    _TaskFilter.upcoming => 'Upcoming',
                    _TaskFilter.overdue => 'Overdue',
                    _TaskFilter.completed => 'Completed',
                  };
                  final count = _countFor(filter);
                  return Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(label),
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 6,
                              vertical: 1,
                            ),
                            decoration: BoxDecoration(
                              color: selected
                                  ? AppColors.primary.withValues(alpha: 0.2)
                                  : AppColors.textMuted.withValues(alpha: 0.12),
                              borderRadius: BorderRadius.circular(999),
                            ),
                            child: Text(
                              '$count',
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w700,
                                color: selected
                                    ? AppColors.primary
                                    : AppColors.textSecondary,
                              ),
                            ),
                          ),
                        ],
                      ),
                      selected: selected,
                      onSelected: (_) {
                        if (_filter == filter) return;
                        setState(() => _filter = filter);
                        _reload();
                      },
                      selectedColor: AppColors.primary.withValues(alpha: 0.15),
                      labelStyle: TextStyle(
                        color: selected
                            ? AppColors.primary
                            : AppColors.textSecondary,
                        fontWeight: FontWeight.w700,
                        fontSize: 13,
                      ),
                    ),
                  );
                }).toList(),
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            if (_loading && data == null)
              const PgLoadingState()
            else if (_error != null && data == null)
              PgErrorState(message: _error!, onRetry: _reload)
            else if (data == null)
              const PgEmptyState(
                message: 'No tasks yet. Add your first task above.',
                icon: Icon(Icons.checklist_rounded),
              )
            else
              ..._buildSections(data),
          ],
        ),
      ),
    );
  }

  List<Widget> _buildSections(EmployeeTaskListResult data) {
    if (_filter == _TaskFilter.today) {
      return [
        if (data.overdue.isNotEmpty) ...[
          _SectionHeader(
            title: 'Overdue',
            count: data.overdue.length,
            accent: AppColors.error,
          ),
          ...data.overdue.map(_taskTile),
          const SizedBox(height: AppSpacing.md),
        ],
        _SectionHeader(
          title: 'Pending',
          count: data.pending.length,
        ),
        if (data.pending.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 12),
            child: Text(
              'No pending tasks for today.',
              style: TextStyle(color: AppColors.textMuted),
            ),
          )
        else
          ...data.pending.map(_taskTile),
        const SizedBox(height: AppSpacing.md),
        InkWell(
          onTap: () =>
              setState(() => _completedExpanded = !_completedExpanded),
          child: _SectionHeader(
            title: 'Completed',
            count: data.completed.length,
            trailing: Icon(
              _completedExpanded
                  ? Icons.expand_less_rounded
                  : Icons.expand_more_rounded,
              color: AppColors.textMuted,
            ),
          ),
        ),
        if (_completedExpanded)
          if (data.completed.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text(
                'No completed tasks yet.',
                style: TextStyle(color: AppColors.textMuted),
              ),
            )
          else
            ...data.completed.map(_taskTile),
      ];
    }

    final tasks = data.tasks;
    if (tasks.isEmpty) {
      return [
        PgEmptyState(
          message: switch (_filter) {
            _TaskFilter.upcoming => 'No upcoming tasks.',
            _TaskFilter.overdue => 'No overdue tasks.',
            _TaskFilter.completed => 'No completed tasks.',
            _TaskFilter.today => 'No tasks for today.',
          },
          icon: const Icon(Icons.checklist_rounded),
        ),
      ];
    }
    return tasks.map(_taskTile).toList();
  }

  Widget _taskTile(EmployeeTask task) {
    return Dismissible(
      key: ValueKey('task-${task.id}-${task.isCompleted}'),
      background: Container(
        alignment: Alignment.centerLeft,
        padding: const EdgeInsets.only(left: 20),
        color: AppColors.success.withValues(alpha: 0.15),
        child: const Icon(Icons.check_circle_outline, color: AppColors.success),
      ),
      secondaryBackground: Container(
        alignment: Alignment.centerRight,
        padding: const EdgeInsets.only(right: 20),
        color: AppColors.error.withValues(alpha: 0.12),
        child: const Icon(Icons.delete_outline, color: AppColors.error),
      ),
      confirmDismiss: (direction) async {
        if (direction == DismissDirection.startToEnd) {
          await _toggleComplete(task);
          return false;
        }
        await _deleteTask(task);
        return false;
      },
      child: PgCard(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        onTap: () => _openEditor(task),
        child: Row(
          children: [
            IconButton(
              onPressed: () => _toggleComplete(task),
              icon: Icon(
                task.isCompleted
                    ? Icons.check_circle_rounded
                    : Icons.circle_outlined,
                color: task.isCompleted
                    ? AppColors.success
                    : AppColors.textMuted,
              ),
            ),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      if (task.isImportant) ...[
                        const Icon(
                          Icons.star_rounded,
                          size: 16,
                          color: AppColors.accent,
                        ),
                        const SizedBox(width: 4),
                      ],
                      Expanded(
                        child: Text(
                          task.title,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            fontWeight: FontWeight.w700,
                            decoration: task.isCompleted
                                ? TextDecoration.lineThrough
                                : null,
                            color: task.isCompleted
                                ? AppColors.textMuted
                                : AppColors.textPrimary,
                          ),
                        ),
                      ),
                    ],
                  ),
                  if (_dueDateLine(task) case final dueLine?) ...[
                    const SizedBox(height: 2),
                    Text(
                      dueLine,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w500,
                        color: _isOverdueTask(task)
                            ? AppColors.error
                            : AppColors.textMuted,
                      ),
                    ),
                  ],
                  if ((task.note ?? '').isNotEmpty ||
                      task.reminderAt != null) ...[
                    const SizedBox(height: 2),
                    Text(
                      [
                        if ((task.note ?? '').isNotEmpty) task.note!,
                        if (task.reminderAt != null)
                          'Reminder ${DateFormat('hh:mm a').format(task.reminderAt!.toLocal())}',
                      ].join(' • '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 12,
                        color: AppColors.textMuted,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            if (!task.isCompleted)
              PopupMenuButton<String>(
                onSelected: (value) {
                  switch (value) {
                    case 'tomorrow':
                      _moveToTomorrow(task);
                    case 'edit':
                      _openEditor(task);
                    case 'delete':
                      _deleteTask(task);
                  }
                },
                itemBuilder: (_) => const [
                  PopupMenuItem(
                    value: 'tomorrow',
                    child: Text('Move to Tomorrow'),
                  ),
                  PopupMenuItem(value: 'edit', child: Text('Edit')),
                  PopupMenuItem(value: 'delete', child: Text('Delete')),
                ],
              ),
          ],
        ),
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader({
    required this.title,
    required this.count,
    this.accent,
    this.trailing,
  });

  final String title;
  final int count;
  final Color? accent;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8, top: 4),
      child: Row(
        children: [
          Text(
            title,
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: accent ?? AppColors.textPrimary,
                ),
          ),
          const SizedBox(width: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
            decoration: BoxDecoration(
              color: (accent ?? AppColors.primary).withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(999),
            ),
            child: Text(
              '$count',
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: accent ?? AppColors.primary,
              ),
            ),
          ),
          const Spacer(),
          if (trailing != null) trailing!,
        ],
      ),
    );
  }
}

class _TaskEditorSheet extends StatefulWidget {
  const _TaskEditorSheet({required this.api, this.task});

  final EmployeeTaskApi api;
  final EmployeeTask? task;

  @override
  State<_TaskEditorSheet> createState() => _TaskEditorSheetState();
}

class _TaskEditorSheetState extends State<_TaskEditorSheet> {
  late final TextEditingController _title;
  late final TextEditingController _note;
  late DateTime _dueDate;
  TimeOfDay? _dueTime;
  DateTime? _reminderAt;
  late bool _important;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    final task = widget.task;
    _title = TextEditingController(text: task?.title ?? '');
    _note = TextEditingController(text: task?.note ?? '');
    _dueDate = task?.dueDate ?? DateTime.now();
    _important = task?.isImportant ?? false;
    _reminderAt = task?.reminderAt?.toLocal();
    if (task?.dueTime != null) {
      final parts = task!.dueTime!.split(':');
      if (parts.length >= 2) {
        _dueTime = TimeOfDay(
          hour: int.tryParse(parts[0]) ?? 0,
          minute: int.tryParse(parts[1]) ?? 0,
        );
      }
    }
  }

  @override
  void dispose() {
    _title.dispose();
    _note.dispose();
    super.dispose();
  }

  String _dateApi(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  String? _timeApi(TimeOfDay? t) {
    if (t == null) return null;
    return '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';
  }

  Future<void> _save() async {
    final title = _title.text.trim();
    if (title.isEmpty || _saving) return;
    setState(() => _saving = true);
    try {
      final reminderIso = _reminderAt?.toUtc().toIso8601String();
      final EmployeeTask saved;
      if (widget.task == null) {
        saved = await widget.api.create(
          title: title,
          note: _note.text.trim().isEmpty ? null : _note.text.trim(),
          dueDate: _dateApi(_dueDate),
          dueTime: _timeApi(_dueTime),
          isImportant: _important,
          reminderAt: reminderIso,
        );
      } else {
        saved = await widget.api.update(
          widget.task!.id,
          title: title,
          note: _note.text.trim(),
          clearNote: _note.text.trim().isEmpty,
          dueDate: _dateApi(_dueDate),
          dueTime: _timeApi(_dueTime),
          clearDueTime: _dueTime == null,
          isImportant: _important,
          reminderAt: reminderIso,
          clearReminder: _reminderAt == null,
        );
      }
      await TaskReminderService.instance.syncReminder(saved);
      if (!mounted) return;
      Navigator.pop(context, true);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
      setState(() => _saving = false);
    }
  }

  Future<void> _pickDueDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _dueDate,
      firstDate: DateTime.now().subtract(const Duration(days: 1)),
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );
    if (picked != null) setState(() => _dueDate = picked);
  }

  Future<void> _pickDueTime() async {
    final picked = await showTimePicker(
      context: context,
      initialTime: _dueTime ?? TimeOfDay.now(),
    );
    if (picked != null) setState(() => _dueTime = picked);
  }

  Future<void> _pickReminder() async {
    final date = await showDatePicker(
      context: context,
      initialDate: _reminderAt ?? _dueDate,
      firstDate: DateTime.now(),
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );
    if (date == null || !mounted) return;
    final time = await showTimePicker(
      context: context,
      initialTime: _reminderAt != null
          ? TimeOfDay.fromDateTime(_reminderAt!)
          : TimeOfDay.now(),
    );
    if (time == null) return;
    setState(() {
      _reminderAt = DateTime(
        date.year,
        date.month,
        date.day,
        time.hour,
        time.minute,
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.viewInsetsOf(context).bottom;
    return Padding(
      padding: EdgeInsets.only(bottom: bottom),
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                widget.task == null ? 'New Task' : 'Edit Task',
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _title,
                autofocus: widget.task == null,
                decoration: const InputDecoration(
                  labelText: 'Task title',
                  border: OutlineInputBorder(),
                ),
                textInputAction: TextInputAction.next,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _note,
                minLines: 2,
                maxLines: 4,
                decoration: const InputDecoration(
                  labelText: 'Note (optional)',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              SwitchListTile.adaptive(
                contentPadding: EdgeInsets.zero,
                value: _important,
                onChanged: (v) => setState(() => _important = v),
                title: const Text('Important'),
                secondary: Icon(
                  _important ? Icons.star_rounded : Icons.star_outline_rounded,
                  color: AppColors.accent,
                ),
              ),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.event_outlined),
                title: const Text('Due date'),
                subtitle: Text(DateFormat('EEE, d MMM yyyy').format(_dueDate)),
                trailing: const Icon(Icons.chevron_right_rounded),
                onTap: _pickDueDate,
              ),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.schedule_outlined),
                title: const Text('Due time'),
                subtitle: Text(
                  _dueTime == null ? 'Optional' : _dueTime!.format(context),
                ),
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (_dueTime != null)
                      IconButton(
                        onPressed: () => setState(() => _dueTime = null),
                        icon: const Icon(Icons.clear),
                      ),
                    const Icon(Icons.chevron_right_rounded),
                  ],
                ),
                onTap: _pickDueTime,
              ),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.notifications_outlined),
                title: const Text('Reminder'),
                subtitle: Text(
                  _reminderAt == null
                      ? 'Optional'
                      : DateFormat('EEE, d MMM • hh:mm a').format(_reminderAt!),
                ),
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (_reminderAt != null)
                      IconButton(
                        onPressed: () => setState(() => _reminderAt = null),
                        icon: const Icon(Icons.clear),
                      ),
                    const Icon(Icons.chevron_right_rounded),
                  ],
                ),
                onTap: _pickReminder,
              ),
              const SizedBox(height: 8),
              FilledButton(
                onPressed: _saving ? null : _save,
                child: _saving
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Text(widget.task == null ? 'Add Task' : 'Save Changes'),
              ),
              if (widget.task != null) ...[
                const SizedBox(height: 8),
                TextButton(
                  onPressed: _saving
                      ? null
                      : () async {
                          final ok = await showDialog<bool>(
                            context: context,
                            builder: (context) => AlertDialog(
                              title: const Text('Delete task?'),
                              actions: [
                                TextButton(
                                  onPressed: () =>
                                      Navigator.pop(context, false),
                                  child: const Text('Cancel'),
                                ),
                                FilledButton(
                                  onPressed: () =>
                                      Navigator.pop(context, true),
                                  child: const Text('Delete'),
                                ),
                              ],
                            ),
                          );
                          if (ok != true) return;
                          await widget.api.delete(widget.task!.id);
                          await TaskReminderService.instance
                              .cancel(widget.task!.id);
                          if (!context.mounted) return;
                          Navigator.pop(context, true);
                        },
                  child: const Text(
                    'Delete Task',
                    style: TextStyle(color: AppColors.error),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
