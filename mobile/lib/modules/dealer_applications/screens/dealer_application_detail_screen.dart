import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
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
import '../../auth/providers/auth_controller.dart';
import '../../manager/widgets/view_captured_location_button.dart';
import '../api/dealer_application_api.dart';

class DealerApplicationDetailScreen extends StatefulWidget {
  const DealerApplicationDetailScreen({
    super.key,
    required this.auth,
    required this.applicationId,
  });

  final AuthController auth;
  final int applicationId;

  @override
  State<DealerApplicationDetailScreen> createState() =>
      _DealerApplicationDetailScreenState();
}

class _DealerApplicationDetailScreenState
    extends State<DealerApplicationDetailScreen> {
  late Future<Map<String, dynamic>> _future;
  late final DealerApplicationApi _api;
  late final _client = ApiClient(
    SessionStore(),
    onUnauthorized: widget.auth.sessionExpired,
  );

  @override
  void initState() {
    super.initState();
    _api = DealerApplicationApi(_client.dio);
    _future = _api.getById(widget.applicationId);
  }

  Future<void> _reload() async {
    setState(() => _future = _api.getById(widget.applicationId));
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      auth: widget.auth,
      title: 'Dealer Application',
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
          final canEdit = data['can_edit'] == true;
          final documents = (data['documents'] as List?)
                  ?.map((item) => Map<String, dynamic>.from(item as Map))
                  .toList() ??
              const <Map<String, dynamic>>[];
          final timeline = (data['timeline'] as List?)
                  ?.map((item) => Map<String, dynamic>.from(item as Map))
                  .toList() ??
              const <Map<String, dynamic>>[];

          return RefreshIndicator(
            onRefresh: _reload,
            child: ListView(
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
                      tone: PgStatusRules.orderTone(
                        data['status']?.toString() ?? '',
                      ),
                    ),
                  ],
                ),
                if (data['dealer_code'] != null) ...[
                  const SizedBox(height: 8),
                  Text('Dealer Code: ${data['dealer_code']}'),
                ],
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _line('Owner', data['owner_name']),
                      _line('Mobile', data['mobile']),
                      _line('GST', data['gst_no']),
                      _line('Location', data['location']),
                      _line('Address', data['address']),
                      const SizedBox(height: 8),
                      ViewCapturedLocationButton(
                        latitude: data['latitude'],
                        longitude: data['longitude'],
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                Text('Documents', style: Theme.of(context).textTheme.titleSmall),
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
                                    ? 'Uploaded'
                                    : 'Not Uploaded',
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
                            if ((timeline[i]['remark']?.toString() ?? '')
                                .isNotEmpty)
                              'Remark: ${timeline[i]['remark']}',
                            if (timeline[i]['payload'] is Map &&
                                (timeline[i]['payload'] as Map)['dealer_code'] !=
                                    null)
                              'Code: ${(timeline[i]['payload'] as Map)['dealer_code']}',
                          ].whereType<String>().where((part) => part.isNotEmpty).join('\n'),
                          isCompleted: true,
                          isActive: i == timeline.length - 1,
                          isLast: i == timeline.length - 1,
                          isRejected: (timeline[i]['event_type']?.toString() ?? '')
                              .contains('reject'),
                        ),
                    ],
                  ),
                ),
                if (canEdit) ...[
                  const SizedBox(height: AppSpacing.lg),
                  FilledButton(
                    onPressed: () async {
                      await context.push(
                        '/dealer-applications/${widget.applicationId}/edit',
                      );
                      if (!mounted) return;
                      _reload();
                    },
                    child: const Text('Edit & Resubmit'),
                  ),
                ],
                const SizedBox(height: AppSpacing.xxl),
              ],
            ),
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
