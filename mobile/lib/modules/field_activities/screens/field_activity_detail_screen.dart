import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
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
import '../../manager/widgets/view_captured_location_button.dart';
import '../api/field_activity_api.dart';
import '../models/field_activity_detail.dart';

class FieldActivityDetailScreen extends StatefulWidget {
  const FieldActivityDetailScreen({
    super.key,
    required this.activityId,
    required this.auth,
    this.loadActivity,
    this.title = 'Field Activity Details',
    this.dateLabel = 'Activity Date',
    this.timeLabel = 'Activity Time',
  });

  final int activityId;
  final AuthController auth;
  final Future<FieldActivityDetail> Function()? loadActivity;
  final String title;
  final String dateLabel;
  final String timeLabel;

  @override
  State<FieldActivityDetailScreen> createState() =>
      _FieldActivityDetailScreenState();
}

class _FieldActivityDetailScreenState extends State<FieldActivityDetailScreen> {
  late Future<FieldActivityDetail> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<FieldActivityDetail> _load() {
    if (widget.loadActivity != null) {
      return widget.loadActivity!();
    }

    return FieldActivityApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    ).getActivity(widget.activityId);
  }

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      title: widget.title,
      showBack: true,
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<FieldActivityDetail>(
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
                    message: 'Unable to load field activity details.',
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
                  title: detail.farmerName,
                  subtitle: [
                    if ((detail.district ?? '').isNotEmpty) detail.district!,
                    detail.village,
                    detail.taluka,
                  ].where((part) => part.isNotEmpty).join(', '),
                  badgeLabel: detail.statusLabel,
                  badgeTone: PgStatusTone.approved,
                ),
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Activity Info',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      PgInvoiceRow(
                        label: widget.dateLabel,
                        value: DateFormat('d MMM yyyy').format(
                          detail.activityDate,
                        ),
                      ),
                      PgInvoiceRow(
                        label: widget.timeLabel,
                        value: detail.activityTime,
                      ),
                      if ((detail.employeeName ?? '').isNotEmpty)
                        PgInvoiceRow(
                          label: 'Employee Name',
                          value: detail.employeeName!,
                        ),
                      if ((detail.employeeCode ?? '').isNotEmpty)
                        PgInvoiceRow(
                          label: 'Employee Code',
                          value: detail.employeeCode!,
                        ),
                      PgInvoiceRow(
                        label: 'Farmer Mobile',
                        value: (detail.farmerMobile ?? '').isEmpty
                            ? '-'
                            : detail.farmerMobile!,
                      ),
                      if ((detail.district ?? '').isNotEmpty)
                        PgInvoiceRow(
                          label: 'District',
                          value: detail.district!,
                        ),
                      PgInvoiceRow(
                        label: 'Village',
                        value: detail.village,
                      ),
                      PgInvoiceRow(
                        label: 'Taluka',
                        value: detail.taluka,
                      ),
                      if ((detail.cropName ?? '').isNotEmpty)
                        PgInvoiceRow(
                          label: 'Crop',
                          value: detail.cropName!,
                        ),
                      if ((detail.remark ?? '').isNotEmpty)
                        PgInvoiceRow(
                          label: 'Remark',
                          value: detail.remark!,
                        ),
                      if (detail.latitude != null && detail.longitude != null)
                        PgInvoiceRow(
                          label: 'Location',
                          value:
                              'Lat ${detail.latitude!.toStringAsFixed(7)}, '
                              'Lng ${detail.longitude!.toStringAsFixed(7)}',
                        ),
                      const SizedBox(height: AppSpacing.sm),
                      ViewCapturedLocationButton(
                        mapsUrl: detail.mapsUrl,
                        latitude: detail.latitude,
                        longitude: detail.longitude,
                      ),
                    ],
                  ),
                ),
                if (detail.recommendations.isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.md),
                  PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Product Recommendations',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        for (final rec in detail.recommendations)
                          Padding(
                            padding: const EdgeInsets.only(bottom: 8),
                            child: Text(
                              [
                                rec.productName,
                                if ((rec.dosage ?? '').isNotEmpty) rec.dosage,
                                if ((rec.remark ?? '').isNotEmpty) rec.remark,
                              ].join(' • '),
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
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
                          child: CachedNetworkImage(
                            imageUrl: detail.photoUrl!,
                            height: 220,
                            width: double.infinity,
                            fit: BoxFit.cover,
                            errorWidget: (_, _, _) =>
                                const Icon(Icons.broken_image, size: 48),
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
