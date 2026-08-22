import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/dealer_visit_api.dart';
import '../models/dealer_visit_detail.dart';

class DealerVisitDetailScreen extends StatefulWidget {
  const DealerVisitDetailScreen({
    super.key,
    required this.visitId,
    required this.auth,
  });

  final int visitId;
  final AuthController auth;

  @override
  State<DealerVisitDetailScreen> createState() =>
      _DealerVisitDetailScreenState();
}

class _DealerVisitDetailScreenState extends State<DealerVisitDetailScreen> {
  late Future<DealerVisitDetail> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<DealerVisitDetail> _load() => DealerVisitApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  ).getVisit(widget.visitId);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _openInMap(DealerVisitDetail detail) async {
    final url =
        detail.mapsUrl ??
        'https://www.google.com/maps?q=${detail.latitude},${detail.longitude}';

    await Clipboard.setData(ClipboardData(text: url));
    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Map link copied. Paste it in your browser to open.'),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      title: 'Dealer Visit Details',
      showBack: true,
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<DealerVisitDetail>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting &&
                !snapshot.hasData) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: const [PgLoadingState()],
              );
            }

            if (snapshot.hasError) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgErrorState(
                    message: 'Unable to load dealer visit details.',
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final detail = snapshot.data!;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgDetailHeader(
                  title: detail.dealerName,
                  subtitle: DateFormat('d MMM yyyy').format(detail.visitDate),
                  badgeLabel: detail.statusLabel,
                  badgeTone: PgStatusTone.approved,
                ),
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Visit Info',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      if (detail.ownerName != null)
                        PgInvoiceRow(
                          label: 'Owner Name',
                          value: detail.ownerName!,
                        ),
                      if (detail.village != null)
                        PgInvoiceRow(label: 'Village', value: detail.village!),
                      PgInvoiceRow(
                        label: 'Visit Time',
                        value: detail.visitTime,
                      ),
                      if (detail.employeeName != null)
                        PgInvoiceRow(
                          label: 'Employee Name',
                          value: detail.employeeName!,
                        ),
                      PgInvoiceRow(
                        label: 'Latitude',
                        value: detail.latitude.toStringAsFixed(7),
                      ),
                      PgInvoiceRow(
                        label: 'Longitude',
                        value: detail.longitude.toStringAsFixed(7),
                      ),
                      PgInvoiceRow(
                        label: 'Accuracy',
                        value: '${detail.accuracy.toStringAsFixed(1)} m',
                      ),
                      if (detail.locationCapturedAt != null)
                        PgInvoiceRow(
                          label: 'Captured At',
                          value: DateFormat('d MMM yyyy, h:mm a').format(
                            detail.locationCapturedAt!.toLocal(),
                          ),
                        ),
                      const SizedBox(height: AppSpacing.sm),
                      Align(
                        alignment: Alignment.centerLeft,
                        child: FittedBox(
                          fit: BoxFit.scaleDown,
                          alignment: Alignment.centerLeft,
                          child: OutlinedButton.icon(
                            onPressed: () => _openInMap(detail),
                            icon: const Icon(Icons.map_outlined),
                            label: const Text('Open in Map'),
                          ),
                        ),
                      ),
                      if (detail.dealerId != null && detail.dealerId! > 0) ...[
                        const SizedBox(height: AppSpacing.sm),
                        Align(
                          alignment: Alignment.centerLeft,
                          child: FilledButton.tonalIcon(
                            onPressed: () =>
                                context.push('/dealers/${detail.dealerId}'),
                            icon: const Icon(Icons.menu_book_outlined),
                            label: const Text('Account / Ledger'),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                if (detail.photoUrl != null) ...[
                  const SizedBox(height: AppSpacing.md),
                  PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Photo',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        ClipRRect(
                          borderRadius: BorderRadius.circular(12),
                          child: AspectRatio(
                            aspectRatio: 16 / 9,
                            child: CachedNetworkImage(
                              imageUrl: detail.photoUrl!,
                              width: double.infinity,
                              fit: BoxFit.cover,
                              errorWidget: (_, _, _) =>
                                  const Icon(Icons.broken_image, size: 48),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            );
          },
        ),
      ),
    );
  }
}
