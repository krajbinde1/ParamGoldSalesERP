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
import '../api/collection_api.dart';
import '../models/collection_detail.dart';

class CollectionDetailScreen extends StatefulWidget {
  const CollectionDetailScreen({
    super.key,
    required this.collectionId,
    required this.auth,
  });

  final int collectionId;
  final AuthController auth;

  @override
  State<CollectionDetailScreen> createState() => _CollectionDetailScreenState();
}

class _CollectionDetailScreenState extends State<CollectionDetailScreen> {
  late Future<CollectionDetail> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<CollectionDetail> _load() => CollectionApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  ).getCollection(widget.collectionId);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  PgStatusTone _statusTone(String status) {
    return switch (status) {
      'received' => PgStatusTone.paid,
      'not_received' => PgStatusTone.rejected,
      _ => PgStatusTone.pending,
    };
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 2,
    );

    return PgPageScaffold(
      title: 'Collection Details',
      showBack: true,
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<CollectionDetail>(
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
                    message: 'Unable to load collection details.',
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final detail = snapshot.data!;
            final showAdminRemark =
                detail.adminRemark != null &&
                detail.adminRemark!.trim().isNotEmpty;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgDetailHeader(
                  title: detail.dealerName,
                  subtitle: DateFormat('d MMM yyyy').format(
                    detail.collectionDate,
                  ),
                  badgeLabel: detail.statusLabel,
                  badgeTone: _statusTone(detail.status),
                ),
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Collection Info',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      PgInvoiceRow(
                        label: 'Amount',
                        value: currency.format(detail.amount),
                        emphasize: true,
                      ),
                      if (detail.employeeRemarks != null &&
                          detail.employeeRemarks!.trim().isNotEmpty)
                        PgInvoiceRow(
                          label: 'Employee Remarks',
                          value: detail.employeeRemarks!,
                        ),
                      if (showAdminRemark)
                        PgInvoiceRow(
                          label: 'Admin Remark',
                          value: detail.adminRemark!,
                        ),
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
