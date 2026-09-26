import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../models/attendance.dart';
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

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
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
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _date ?? now,
      firstDate: DateTime(now.year - 1),
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
    if (_reason == null) {
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

    setState(() => _busy = true);
    try {
      final actual = DateTime(
        _date!.year,
        _date!.month,
        _date!.day,
        _time!.hour,
        _time!.minute,
      );
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
    final reasons = _reasons(attendance);

    return PgPageScaffold(
      title: 'Request Punch Out Correction',
      showBack: true,
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          PgCard(
            child: Text(
              'Punch in is more than 24 hours old. Enter the actual punch out date and time. This will be sent for manager/admin approval.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Actual Punch Out Date'),
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
            title: const Text('Actual Punch Out Time'),
            subtitle: Text(_time == null ? 'Select time' : _time!.format(context)),
            trailing: const Icon(Icons.schedule_outlined),
            onTap: _pickTime,
          ),
          const SizedBox(height: AppSpacing.sm),
          Text('Reason', style: Theme.of(context).textTheme.titleSmall),
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
            'New Punch In stays blocked until this is approved or you submit again after rejection.',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
              color: AppColors.textMuted,
            ),
          ),
        ],
      ),
    );
  }
}
