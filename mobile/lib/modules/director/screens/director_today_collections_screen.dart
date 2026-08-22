import 'package:cached_network_image/cached_network_image.dart';
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
import '../api/director_api.dart';

final _inr = NumberFormat.currency(
  locale: 'en_IN',
  symbol: '₹',
  decimalDigits: 0,
);

class DirectorTodayCollectionsScreen extends StatefulWidget {
  const DirectorTodayCollectionsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<DirectorTodayCollectionsScreen> createState() =>
      _DirectorTodayCollectionsScreenState();
}

class _DirectorTodayCollectionsScreenState
    extends State<DirectorTodayCollectionsScreen> {
  late Future<Map<String, dynamic>> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.loadTodayCollectionDealers();
  }

  Future<void> _reload() async {
    setState(() => _future = _api.loadTodayCollectionDealers());
    await _future;
  }

  Future<void> _openDealer(Map<String, dynamic> dealer) async {
    final dealerId = int.tryParse('${dealer['dealer_id'] ?? 0}') ?? 0;
    if (dealerId <= 0) return;
    await context.push('/director/today-collections/$dealerId', extra: dealer);
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
          title: "Today's Collection – Dealers",
          auth: widget.auth,
          showBack: true,
          onBack: () => smartBack(context),
        ),
        body: RefreshIndicator(
          color: AppColors.primary,
          onRefresh: _reload,
          child: FutureBuilder<Map<String, dynamic>>(
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
                      message: 'Unable to load today\'s collections',
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

              final dealers = (snapshot.data?['dealers'] as List?)
                      ?.whereType<Map>()
                      .map((item) => Map<String, dynamic>.from(item))
                      .toList() ??
                  const <Map<String, dynamic>>[];

              if (dealers.isEmpty) {
                return ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: [
                    SizedBox(height: MediaQuery.sizeOf(context).height * 0.25),
                    const PgEmptyState(
                      message: 'No collections recorded today',
                    ),
                  ],
                );
              }

              return ListView.builder(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                itemCount: dealers.length,
                itemBuilder: (context, index) {
                  final dealer = dealers[index];
                  final code = dealer['dealer_code']?.toString().trim() ?? '';
                  final village = dealer['village']?.toString().trim() ?? '';
                  final locationLine = [
                    if (code.isNotEmpty) code,
                    if (village.isNotEmpty) village,
                  ].join(' • ');
                  final employee =
                      dealer['employee_name']?.toString().trim().isNotEmpty ==
                              true
                          ? dealer['employee_name'].toString()
                          : (dealer['assigned_employee_name']?.toString() ??
                              '-');
                  final amount =
                      double.tryParse('${dealer['total_amount'] ?? 0}') ?? 0;
                  final entries =
                      int.tryParse('${dealer['entries_count'] ?? 0}') ?? 0;

                  return PgCard(
                    onTap: () => _openDealer(dealer),
                    margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          dealer['dealer_name']?.toString() ?? '-',
                          style: Theme.of(context)
                              .textTheme
                              .titleSmall
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        if (locationLine.isNotEmpty)
                          Text(
                            locationLine,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        const SizedBox(height: 4),
                        Text(
                          employee,
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        const SizedBox(height: 8),
                        Text(
                          "Today's Collection: ${_inr.format(amount)}",
                          style: Theme.of(context)
                              .textTheme
                              .titleSmall
                              ?.copyWith(
                                color: AppColors.primary,
                                fontWeight: FontWeight.w800,
                              ),
                        ),
                        Text(
                          '$entries ${entries == 1 ? 'Entry' : 'Entries'}',
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                color: AppColors.textSecondary,
                              ),
                        ),
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

class DirectorTodayCollectionDetailsScreen extends StatefulWidget {
  const DirectorTodayCollectionDetailsScreen({
    super.key,
    required this.auth,
    required this.dealerId,
    this.dealer,
  });

  final AuthController auth;
  final int dealerId;
  final Map<String, dynamic>? dealer;

  @override
  State<DirectorTodayCollectionDetailsScreen> createState() =>
      _DirectorTodayCollectionDetailsScreenState();
}

class _DirectorTodayCollectionDetailsScreenState
    extends State<DirectorTodayCollectionDetailsScreen> {
  late Future<Map<String, dynamic>> _future;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.listTodayDealerCollections(dealerId: widget.dealerId);
  }

  Future<void> _reload() async {
    setState(() {
      _future = _api.listTodayDealerCollections(dealerId: widget.dealerId);
    });
    await _future;
  }

  String _formatTime(Object? value) {
    if (value == null) return '—';
    final raw = value.toString().trim();
    if (raw.isEmpty) return '—';
    final parsed = DateTime.tryParse(raw) ??
        DateTime.tryParse('1970-01-01 $raw');
    if (parsed == null) return raw;
    return DateFormat('hh:mm a').format(parsed.toLocal());
  }

  PgStatusTone _statusTone(String status) {
    return switch (status.toLowerCase()) {
      'received' => PgStatusTone.paid,
      'not_received' => PgStatusTone.rejected,
      _ => PgStatusTone.pending,
    };
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
          title: "Today's Collection Details",
          auth: widget.auth,
          showBack: true,
          onBack: () => smartBack(context),
        ),
        body: RefreshIndicator(
          color: AppColors.primary,
          onRefresh: _reload,
          child: FutureBuilder<Map<String, dynamic>>(
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
                      message: 'Unable to load collection details',
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

              final body = snapshot.data ?? const <String, dynamic>{};
              final dealer = Map<String, dynamic>.from(
                body['dealer'] as Map? ?? widget.dealer ?? const {},
              );
              final entries = (body['data'] as List?)
                      ?.whereType<Map>()
                      .map((item) => Map<String, dynamic>.from(item))
                      .toList() ??
                  const <Map<String, dynamic>>[];
              final dealerName =
                  dealer['dealer_name']?.toString() ?? widget.dealer?['dealer_name']?.toString();

              if (entries.isEmpty) {
                return ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: [
                    SizedBox(height: MediaQuery.sizeOf(context).height * 0.25),
                    PgEmptyState(
                      message: dealerName == null
                          ? 'No collection entries for this dealer today'
                          : 'No collection entries for $dealerName today',
                    ),
                  ],
                );
              }

              return ListView.builder(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                itemCount: entries.length + (dealerName == null ? 0 : 1),
                itemBuilder: (context, index) {
                  if (dealerName != null && index == 0) {
                    final code = dealer['dealer_code']?.toString().trim() ?? '';
                    final village = dealer['village']?.toString().trim() ?? '';
                    final locationLine = [
                      if (code.isNotEmpty) code,
                      if (village.isNotEmpty) village,
                    ].join(' • ');
                    return Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.md),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            dealerName,
                            style: Theme.of(context)
                                .textTheme
                                .titleMedium
                                ?.copyWith(fontWeight: FontWeight.w800),
                          ),
                          if (locationLine.isNotEmpty)
                            Text(
                              locationLine,
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                        ],
                      ),
                    );
                  }

                  final entry = entries[dealerName == null ? index : index - 1];
                  final amount =
                      double.tryParse('${entry['amount'] ?? 0}') ?? 0;
                  final time = _formatTime(
                    entry['collected_at'] ?? entry['collection_time'],
                  );
                  final employee =
                      entry['employee_name']?.toString().trim() ?? '-';
                  final paymentMode =
                      entry['payment_mode']?.toString().trim() ?? '';
                  final remark = entry['remarks']?.toString().trim() ?? '';
                  final status = entry['status']?.toString() ?? '';
                  final statusLabel =
                      entry['status_label']?.toString() ?? status;
                  final photoUrl = entry['photo_url']?.toString() ?? '';

                  return PgCard(
                    margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _inr.format(amount),
                          style: Theme.of(context)
                              .textTheme
                              .titleMedium
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          time,
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        Text(
                          'Collected by $employee',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        if (paymentMode.isNotEmpty)
                          Text(
                            paymentMode,
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
                            label: 'Status: $statusLabel',
                            tone: _statusTone(status),
                          ),
                        ],
                        if (photoUrl.isNotEmpty) ...[
                          const SizedBox(height: 10),
                          ClipRRect(
                            borderRadius: BorderRadius.circular(12),
                            child: AspectRatio(
                              aspectRatio: 16 / 9,
                              child: CachedNetworkImage(
                                imageUrl: photoUrl,
                                fit: BoxFit.cover,
                                errorWidget: (_, _, _) => Container(
                                  color: AppColors.border,
                                  alignment: Alignment.center,
                                  child: const Icon(Icons.broken_image_outlined),
                                ),
                              ),
                            ),
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
