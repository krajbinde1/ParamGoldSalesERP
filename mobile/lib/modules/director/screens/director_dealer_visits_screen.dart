import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../../dealer_visits/models/dealer_visit_detail.dart';
import '../../dealer_visits/screens/dealer_visit_detail_screen.dart';
import '../api/director_api.dart';

class DirectorDealerVisitsScreen extends StatefulWidget {
  const DirectorDealerVisitsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<DirectorDealerVisitsScreen> createState() =>
      _DirectorDealerVisitsScreenState();
}

class _DirectorDealerVisitsScreenState extends State<DirectorDealerVisitsScreen> {
  late Future<List<Map<String, dynamic>>> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  String get _today => DateFormat('yyyy-MM-dd').format(DateTime.now());

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<Map<String, dynamic>>> _load() =>
      _api.listDealerVisits(date: _today);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  String _formatTime(Object? value) {
    if (value == null) return '—';
    final raw = value.toString().trim();
    if (raw.isEmpty) return '—';
    final parsed = DateTime.tryParse(raw) ??
        DateTime.tryParse('1970-01-01 $raw');
    if (parsed == null) return raw;
    return DateFormat('hh:mm a').format(parsed);
  }

  Future<void> _openVisit(int visitId) async {
    if (visitId <= 0) return;
    await context.push('/director/dealer-visits/$visitId');
    if (!mounted) return;
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final canPop = context.canPop();
    return PopScope(
      canPop: canPop,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        smartBack(context);
      },
      child: Scaffold(
        appBar: RoleAppBar(
          title: 'Dealer Visits Today',
          auth: widget.auth,
          showBack: true,
          onBack: () => smartBack(context),
        ),
        body: RefreshIndicator(
          color: AppColors.primary,
          onRefresh: _reload,
          child: FutureBuilder<List<Map<String, dynamic>>>(
            future: _future,
            builder: (context, snapshot) {
              if (snapshot.connectionState == ConnectionState.waiting &&
                  !snapshot.hasData) {
                return const PgLoadingState();
              }
              if (snapshot.hasError) {
                return ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(AppSpacing.screenPadding),
                  children: [
                    PgErrorState(
                      message: 'Unable to load dealer visits',
                      onRetry: _reload,
                    ),
                    const SizedBox(height: 8),
                    Text(
                      errorMessage(snapshot.error),
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AppColors.textSecondary,
                          ),
                    ),
                  ],
                );
              }

              final visits = snapshot.data ?? const [];
              if (visits.isEmpty) {
                return ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: [
                    SizedBox(height: MediaQuery.sizeOf(context).height * 0.25),
                    const PgEmptyState(
                      message: 'No dealer visits recorded today',
                    ),
                  ],
                );
              }

              return ListView.builder(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                itemCount: visits.length,
                itemBuilder: (context, index) {
                  final visit = visits[index];
                  final code = visit['dealer_code']?.toString() ?? '';
                  final village = visit['village']?.toString() ?? '';
                  final taluka = visit['taluka']?.toString() ?? '';
                  final district = visit['district']?.toString() ?? '';
                  final area = [
                    if (taluka.isNotEmpty) taluka,
                    if (district.isNotEmpty) district,
                  ].join(', ');
                  final remark = visit['remark']?.toString().trim() ?? '';
                  final statusLabel =
                      visit['status_label']?.toString() ??
                          visit['status']?.toString() ??
                          '';

                  return PgCard(
                    onTap: () =>
                        _openVisit(int.tryParse('${visit['id'] ?? 0}') ?? 0),
                    margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          visit['dealer_name']?.toString() ?? '-',
                          style: Theme.of(context)
                              .textTheme
                              .titleSmall
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        if (code.isNotEmpty)
                          Text(
                            code,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        if (village.isNotEmpty)
                          Text(
                            village,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        if (area.isNotEmpty)
                          Text(
                            area,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        const SizedBox(height: 6),
                        Text(
                          'Employee: ${visit['employee_name'] ?? '-'}',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        Text(
                          'Visit Time: ${_formatTime(visit['visit_time'])}',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        if (remark.isNotEmpty)
                          Text(
                            remark,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        if (statusLabel.isNotEmpty) ...[
                          const SizedBox(height: 8),
                          PgStatusBadge(
                            label: statusLabel,
                            tone: PgStatusTone.approved,
                          ),
                        ],
                      ],
                    ),
                  );
                },
              );
            },
          ),
        ),
      ),
    );
  }
}

class DirectorDealerVisitDetailScreen extends StatelessWidget {
  const DirectorDealerVisitDetailScreen({
    super.key,
    required this.auth,
    required this.visitId,
  });

  final AuthController auth;
  final int visitId;

  @override
  Widget build(BuildContext context) {
    final api = DirectorApi(
      ApiClient(SessionStore(), onUnauthorized: auth.sessionExpired).dio,
    );

    return DealerVisitDetailScreen(
      visitId: visitId,
      auth: auth,
      loadVisit: () async {
        final data = await api.getDealerVisit(visitId);
        return DealerVisitDetail.fromJson(data);
      },
    );
  }
}
