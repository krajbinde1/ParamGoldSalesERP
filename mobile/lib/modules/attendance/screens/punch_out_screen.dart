import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../models/attendance_format.dart';
import '../providers/attendance_provider.dart';
import '../route_tracking/route_tracking_provider.dart';
import 'punch_in_screen.dart';

class PunchOutScreen extends ConsumerStatefulWidget {
  const PunchOutScreen({super.key});
  @override
  ConsumerState<PunchOutScreen> createState() => _PunchOutScreenState();
}

class _PunchOutScreenState extends ConsumerState<PunchOutScreen> {
  bool busy = false;
  String? _reason;
  final _note = TextEditingController();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (busy) return;
    final today = ref.read(todayAttendanceProvider).asData?.value;
    final reasonRequired = today?.latePunchOutReasonRequired == true;
    if (reasonRequired && (_reason == null || _reason!.isEmpty)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Select a late punch-out reason.')),
      );
      return;
    }
    if (reasonRequired && _reason == 'other' && _note.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter a reason.')),
      );
      return;
    }
    setState(() => busy = true);
    try {
      final attendance = await ref.read(todayAttendanceProvider.notifier).punch(
        'punch-out',
        latePunchOutReason: reasonRequired ? _reason : null,
        latePunchOutReasonNote: reasonRequired && _note.text.trim().isNotEmpty
            ? _note.text.trim()
            : null,
      );
      if (mounted) {
        refreshRouteTrackingStatus(ref);
        final punchInTime = attendance?.punchIn == null
            ? '—'
            : AttendanceFormat.time(attendance!.punchIn);
        final punchOutTime = attendance?.punchOut == null
            ? '—'
            : AttendanceFormat.time(attendance!.punchOut);
        final working = attendance?.workingHours ?? '—';
        final summary = attendance == null
            ? 'Punch out recorded. You can punch in for today.'
            : 'Punch Out Successful\n'
                'Punch In: $punchInTime\n'
                'Punch Out: $punchOutTime\n'
                'Working Hours: $working\n'
                'Status: ${attendance.status}';
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(summary)),
        );
        context.pop();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final today = ref.watch(todayAttendanceProvider).asData?.value;
    final reasonRequired = today?.latePunchOutReasonRequired == true;
    final reasons = today?.latePunchOutReasons.isNotEmpty == true
        ? today!.latePunchOutReasons
        : const [
            {'value': 'forgot_to_punch_out', 'label': 'Forgot to Punch Out'},
            {'value': 'travelling', 'label': 'Travelling'},
            {'value': 'other', 'label': 'Other'},
          ];

    if (!reasonRequired) {
      return PunchScreen(
        title: 'Punch Out',
        icon: const Icon(Icons.logout_rounded),
        message:
            'We will capture your location and selfie again, then calculate your working hours.',
        busy: busy,
        onPressed: submit,
      );
    }

    return PunchScreen(
      title: 'Punch Out',
      icon: const Icon(Icons.logout_rounded),
      message:
          'Previous day punch out is pending. Select a reason, then we will capture GPS and selfie.',
      busy: busy,
      onPressed: submit,
      extra: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Late Punch Out Reason',
            style: Theme.of(context).textTheme.titleSmall,
          ),
          const SizedBox(height: AppSpacing.xs),
          ...reasons.map(
            (reason) => RadioListTile<String>(
              contentPadding: EdgeInsets.zero,
              value: reason['value']!,
              groupValue: _reason,
              title: Text(reason['label'] ?? reason['value']!),
              onChanged: busy
                  ? null
                  : (value) => setState(() => _reason = value),
            ),
          ),
          if (_reason == 'other')
            TextField(
              controller: _note,
              enabled: !busy,
              maxLength: 500,
              maxLines: 2,
              decoration: const InputDecoration(
                labelText: 'Reason details',
              ),
            ),
          Text(
            'This will be marked as Late Punch Out.',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
              color: AppColors.textMuted,
            ),
          ),
          const SizedBox(height: AppSpacing.md),
        ],
      ),
    );
  }
}
