import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../models/attendance.dart';
import '../models/attendance_format.dart';
import '../providers/attendance_provider.dart';

class PunchOutCorrectionScreen extends ConsumerStatefulWidget {
  const PunchOutCorrectionScreen({super.key});

  @override
  ConsumerState<PunchOutCorrectionScreen> createState() =>
      _PunchOutCorrectionScreenState();
}

class _PunchOutCorrectionScreenState
    extends ConsumerState<PunchOutCorrectionScreen> {
  DateTime? _date;
  TimeOfDay? _time;
  String? _reason;
  final _note = TextEditingController();
  bool _busy = false;

  bool _seeded = false;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  void _seedFrom(Attendance? attendance) {
    if (_seeded) return;
    final punchIn = attendance?.openPunchIn ?? attendance?.punchIn;
    if (punchIn == null) return;
    _seeded = true;
    _date = DateTime(punchIn.year, punchIn.month, punchIn.day);
    final now = AttendanceFormat.istNow();
    var suggested = punchIn.add(const Duration(hours: 9));
    if (suggested.isAfter(now)) suggested = now;
    if (suggested.isAfter(punchIn)) {
      _time = TimeOfDay(hour: suggested.hour, minute: suggested.minute);
    }
  }

  List<Map<String, String>> _reasons(Attendance? attendance) {
    if (attendance?.latePunchOutReasons.isNotEmpty == true) {
      return attendance!.latePunchOutReasons;
    }
    return const [
      {'value': 'forgot_to_punch_out', 'label': 'Forgot to Punch Out'},
      {'value': 'travelling', 'label': 'Travelling'},
      {'value': 'other', 'label': 'Other'},
    ];
  }

  Future<void> _pickDate() async {
    final now = AttendanceFormat.istNow();
    final punchIn = ref.read(todayAttendanceProvider).asData?.value?.openPunchIn;
    final first = punchIn == null
        ? DateTime(now.year - 1)
        : DateTime(punchIn.year, punchIn.month, punchIn.day);
    final initial = _date ?? first;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial.isAfter(now) ? now : initial,
      firstDate: first.isAfter(now) ? now : first,
      lastDate: now,
    );
    if (picked != null) setState(() => _date = picked);
  }

  Future<void> _pickTime() async {
    final picked = await showTimePicker(
      context: context,
      initialTime: _time ?? TimeOfDay.now(),
    );
    if (picked != null) setState(() => _time = picked);
  }

  Future<void> _submit() async {
    if (_busy) return;
    if (_date == null || _time == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Select actual punch out date and time.')),
      );
      return;
    }
    if (_reason == null || _reason!.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Select a reason.')),
      );
      return;
    }
    if (_reason == 'other' && _note.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter a reason.')),
      );
      return;
    }

    final attendance = ref.read(todayAttendanceProvider).asData?.value;
    final punchIn = attendance?.openPunchIn ?? attendance?.punchIn;
    final actual = DateTime(
      _date!.year,
      _date!.month,
      _date!.day,
      _time!.hour,
      _time!.minute,
    );
    final now = AttendanceFormat.istNow();
    if (punchIn != null && !actual.isAfter(punchIn)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Actual punch out must be after punch in.')),
      );
      return;
    }
    if (actual.isAfter(now.add(const Duration(minutes: 1)))) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Actual punch out cannot be in the future.')),
      );
      return;
    }

    setState(() => _busy = true);
    try {
      await ref.read(todayAttendanceProvider.notifier).submitPunchOutCorrection(
            actualPunchOut: actual,
            reason: _reason!,
            reasonNote: _note.text.trim().isEmpty ? null : _note.text.trim(),
          );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Punch out correction submitted for approval.')),
      );
      context.pop();
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$error')));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final attendance = ref.watch(todayAttendanceProvider).asData?.value;
    _seedFrom(attendance);
    final reasons = _reasons(attendance);
    final punchIn = attendance?.openPunchIn ?? attendance?.punchIn;
    final attendanceDate = attendance?.openAttendanceDate ?? attendance?.date;
    final bottom = MediaQuery.paddingOf(context).bottom;

    return PgPageScaffold(
      title: 'Request Punch Out Correction',
      showBack: true,
      body: SafeArea(
        top: false,
        child: ListView(
        padding: EdgeInsets.fromLTRB(
          AppSpacing.screenPadding,
          AppSpacing.screenPadding,
          AppSpacing.screenPadding,
          AppSpacing.screenPadding + bottom,
        ),
        children: [
          PgCard(
            child: Text(
              'This punch in is more than 24 hours old. Enter the actual previous punch out date and time. A reason is required. The request is sent for manager/admin approval and does not overwrite attendance.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          PgCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Previous attendance', style: Theme.of(context).textTheme.titleSmall),
                const SizedBox(height: AppSpacing.sm),
                Text('Attendance Date: ${attendanceDate == null ? '—' : '${attendanceDate.day.toString().padLeft(2, '0')}-${attendanceDate.month.toString().padLeft(2, '0')}-${attendanceDate.year}'}'),
                const SizedBox(height: 4),
                Text('Punch In: ${AttendanceFormat.dateTime(punchIn)}'),
                const SizedBox(height: 4),
                Text(
                  'Requested Punch Out: ${_date == null || _time == null ? 'Select date and time' : '${_date!.day.toString().padLeft(2, '0')}-${_date!.month.toString().padLeft(2, '0')}-${_date!.year} ${_time!.format(context)}'}',
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Requested Punch Out Date'),
            subtitle: Text(
              _date == null
                  ? 'Select date'
                  : '${_date!.day.toString().padLeft(2, '0')}-${_date!.month.toString().padLeft(2, '0')}-${_date!.year}',
            ),
            trailing: const Icon(Icons.calendar_today_outlined),
            onTap: _pickDate,
          ),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Requested Punch Out Time'),
            subtitle: Text(_time == null ? 'Select time' : _time!.format(context)),
            trailing: const Icon(Icons.schedule_outlined),
            onTap: _pickTime,
          ),
          const SizedBox(height: AppSpacing.sm),
          Text('Reason (required)', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: AppSpacing.xs),
          ...reasons.map(
            (reason) => RadioListTile<String>(
              contentPadding: EdgeInsets.zero,
              value: reason['value']!,
              groupValue: _reason,
              title: Text(reason['label'] ?? reason['value']!),
              onChanged: (value) => setState(() => _reason = value),
            ),
          ),
          if (_reason == 'other') ...[
            TextField(
              controller: _note,
              maxLength: 500,
              maxLines: 3,
              decoration: const InputDecoration(
                labelText: 'Reason details',
                hintText: 'Enter the reason',
              ),
            ),
          ],
          const SizedBox(height: AppSpacing.lg),
          SizedBox(
            width: double.infinity,
            height: 56,
            child: FilledButton(
              onPressed: _busy ? null : _submit,
              child: Text(_busy ? 'SUBMITTING…' : 'SUBMIT FOR APPROVAL'),
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            'New Punch In stays blocked until this correction is approved. If it is rejected, you can submit again.',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
              color: AppColors.textMuted,
            ),
          ),
        ],
        ),
      ),
    );
  }
}
