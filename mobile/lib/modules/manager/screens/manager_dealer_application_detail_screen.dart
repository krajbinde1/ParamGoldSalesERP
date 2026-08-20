import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/utils/secure_document.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/prompt_dialog.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/manager_api.dart';
import '../widgets/view_captured_location_button.dart';

class ManagerDealerApplicationDetailScreen extends StatefulWidget {
  const ManagerDealerApplicationDetailScreen({
    super.key,
    required this.auth,
    required this.applicationId,
  });

  final AuthController auth;
  final int applicationId;

  @override
  State<ManagerDealerApplicationDetailScreen> createState() =>
      _ManagerDealerApplicationDetailScreenState();
}

class _ManagerDealerApplicationDetailScreenState
    extends State<ManagerDealerApplicationDetailScreen> {
  late Future<Map<String, dynamic>> _future;
  late final ManagerApi _api;
  late final _client = ApiClient(
    SessionStore(),
    onUnauthorized: widget.auth.sessionExpired,
  );
  bool _acting = false;

  @override
  void initState() {
    super.initState();
    _api = ManagerApi(_client.dio);
    _future = _api.getDealerApplication(widget.applicationId);
  }

  Future<void> _reload() async {
    setState(() => _future = _api.getDealerApplication(widget.applicationId));
    await _future;
  }

  Future<void> _approve() async {
    final confirmed = await confirmAction(
      context,
      title: 'Approve Dealer',
      message: 'Send this dealer application to Admin for final approval?',
    );
    if (!confirmed || !mounted) return;
    setState(() => _acting = true);
    try {
      await _api.approveDealerApplication(widget.applicationId);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Approved. Waiting for Admin.')),
      );
      await _reload();
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(errorMessage(error))),
        );
      }
    } finally {
      if (mounted) setState(() => _acting = false);
    }
  }

  Future<void> _reject() async {
    final remark = await promptRemarkDialog(
      context,
      title: 'Reject Dealer Application',
    );
    if (remark == null || !mounted) return;
    setState(() => _acting = true);
    try {
      await _api.rejectDealerApplication(widget.applicationId, remark: remark);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Application rejected.')),
      );
      await _reload();
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(errorMessage(error))),
        );
      }
    } finally {
      if (mounted) setState(() => _acting = false);
    }
  }

  Future<void> _sendBack() async {
    final remark = await promptRemarkDialog(
      context,
      title: 'Send Back for Correction',
    );
    if (remark == null || !mounted) return;
    setState(() => _acting = true);
    try {
      await _api.sendBackDealerApplication(widget.applicationId, remark: remark);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Sent back for correction.')),
      );
      await _reload();
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(errorMessage(error))),
        );
      }
    } finally {
      if (mounted) setState(() => _acting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      auth: widget.auth,
      title: 'Dealer Approval',
      showBack: true,
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              !snapshot.hasData) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(
              message: errorMessage(snapshot.error),
              onRetry: _reload,
            );
          }
          final data = snapshot.data!;
          final pending = data['status']?.toString() == 'pending_manager_approval';
          final documents = (data['documents'] as List?)
                  ?.map((item) => Map<String, dynamic>.from(item as Map))
                  .toList() ??
              const <Map<String, dynamic>>[];
          final timeline = (data['timeline'] as List?)
                  ?.map((item) => Map<String, dynamic>.from(item as Map))
                  .toList() ??
              const <Map<String, dynamic>>[];

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      data['firm_name']?.toString() ?? '-',
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                  ),
                  PgStatusBadge(
                    label: data['status_label']?.toString() ?? '-',
                    tone: PgStatusRules.orderTone(data['status']?.toString() ?? ''),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _line('Owner', data['owner_name']),
                    _line('Mobile', data['mobile']),
                    _line('GST', data['gst_no']),
                    _line('State', data['state']),
                    _line('District', data['district']),
                    _line('Taluka', data['taluka']),
                    _line('Location', data['location']),
                    _line('Employee', data['employee_name']),
                    const SizedBox(height: 8),
                    Text(
                      'Shop Location',
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                    const SizedBox(height: 6),
                    if (data['latitude'] != null && data['longitude'] != null) ...[
                      Text('Latitude: ${data['latitude']}'),
                      Text('Longitude: ${data['longitude']}'),
                      const SizedBox(height: 6),
                    ],
                    ViewCapturedLocationButton(
                      latitude: data['latitude'],
                      longitude: data['longitude'],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              Text('Supporting Documents', style: Theme.of(context).textTheme.titleSmall),
              const SizedBox(height: AppSpacing.sm),
                for (final doc in documents)
                  PgCard(
                    margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(doc['document_name']?.toString() ?? '-'),
                              Text(
                                doc['uploaded'] == true
                                    ? [
                                        'Uploaded',
                                        if ((doc['original_filename']
                                                    ?.toString()
                                                    .isNotEmpty ??
                                                false))
                                          doc['original_filename'].toString(),
                                      ].join(' • ')
                                    : 'Not Uploaded',
                                style: Theme.of(context).textTheme.bodySmall,
                              ),
                            ],
                          ),
                        ),
                        if (doc['uploaded'] == true)
                          TextButton(
                            onPressed: () => openSecureDocument(
                              context,
                              dio: _client.dio,
                              title: doc['document_name']?.toString() ?? 'Document',
                              mimeType: doc['mime_type']?.toString(),
                              viewPath: doc['view_path']?.toString(),
                              documentId: int.tryParse('${doc['id'] ?? ''}'),
                            ),
                            child: const Text('View'),
                          ),
                      ],
                    ),
                  ),
              const SizedBox(height: AppSpacing.md),
              Text('Timeline', style: Theme.of(context).textTheme.titleSmall),
              const SizedBox(height: AppSpacing.sm),
              PgCard(
                child: Column(
                  children: [
                    for (var i = 0; i < timeline.length; i++)
                      PgTimelineStep(
                        title: timeline[i]['label']?.toString() ?? '-',
                        subtitle: [
                          timeline[i]['actor_name']?.toString(),
                          _formatWhen(timeline[i]['occurred_at']?.toString()),
                          if ((timeline[i]['remark']?.toString() ?? '').isNotEmpty)
                            'Remark: ${timeline[i]['remark']}',
                        ].whereType<String>().where((part) => part.isNotEmpty).join('\n'),
                        isCompleted: true,
                        isActive: i == timeline.length - 1,
                        isLast: i == timeline.length - 1,
                        isRejected:
                            (timeline[i]['event_type']?.toString() ?? '').contains('reject'),
                      ),
                  ],
                ),
              ),
              if (pending) ...[
                const SizedBox(height: AppSpacing.lg),
                FilledButton(
                  onPressed: _acting ? null : _approve,
                  child: const Text('Approve'),
                ),
                const SizedBox(height: AppSpacing.sm),
                OutlinedButton(
                  onPressed: _acting ? null : _sendBack,
                  child: const Text('Send Back for Correction'),
                ),
                const SizedBox(height: AppSpacing.sm),
                OutlinedButton(
                  onPressed: _acting ? null : _reject,
                  child: const Text('Reject'),
                ),
              ],
              const SizedBox(height: AppSpacing.xxl),
            ],
          );
        },
      ),
    );
  }

  Widget _line(String label, Object? value) {
    final text = value?.toString().trim() ?? '';
    if (text.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Text('$label: $text'),
    );
  }
}

String _formatWhen(String? raw) {
  if (raw == null || raw.isEmpty) return '';
  final parsed = DateTime.tryParse(raw);
  if (parsed == null) return raw;
  return DateFormat('d MMM yyyy, h:mm a').format(parsed.toLocal());
}
