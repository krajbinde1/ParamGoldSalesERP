import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/manager_api.dart';

class ManagerCollectionDetailScreen extends StatefulWidget {
  const ManagerCollectionDetailScreen({
    super.key,
    required this.auth,
    required this.collectionId,
    this.loadCollection,
  });

  final AuthController auth;
  final int collectionId;
  final Future<Map<String, dynamic>> Function()? loadCollection;

  @override
  State<ManagerCollectionDetailScreen> createState() =>
      _ManagerCollectionDetailScreenState();
}

class _ManagerCollectionDetailScreenState
    extends State<ManagerCollectionDetailScreen> {
  late Future<Map<String, dynamic>> _future;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = (widget.loadCollection ??
            () => _api.getCollection(widget.collectionId))();
  }

  Future<void> _reload() async {
    setState(() => _future = (widget.loadCollection ??
            () => _api.getCollection(widget.collectionId))());
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

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: RoleAppBar(
        title: 'Collection Details',
        auth: widget.auth,
        showBack: true,
        onBack: () => Navigator.of(context).maybePop(),
      ),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<Map<String, dynamic>>(
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
                    message: errorMessage(snapshot.error),
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final detail = snapshot.data ?? const {};
            final status = detail['status']?.toString() ?? 'pending';
            final date = DateTime.tryParse(
              detail['collection_date']?.toString() ?? '',
            );
            final remarks = (detail['remarks'] ?? detail['employee_remarks'])
                ?.toString()
                .trim();
            final adminRemark = detail['admin_remark']?.toString().trim();
            final photoUrl = detail['photo_url']?.toString();
            final dealer = detail['dealer'] is Map
                ? Map<String, dynamic>.from(detail['dealer'] as Map)
                : const <String, dynamic>{};
            final submittedAt = _formatDateTime(
              detail['submitted_at']?.toString() ??
                  detail['created_at']?.toString(),
            );
            final employeeCode = detail['employee_code']?.toString().trim();
            final receiptNo = detail['receipt_no']?.toString().trim();

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgDetailHeader(
                  title: detail['dealer_name']?.toString() ?? '-',
                  subtitle: date == null
                      ? '-'
                      : DateFormat('d MMM yyyy').format(date),
                  badgeLabel: detail['status_label']?.toString() ?? status,
                  badgeTone: _statusTone(status),
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
                        label: 'Employee',
                        value: [
                          detail['employee_name']?.toString() ?? '-',
                          if (employeeCode != null && employeeCode.isNotEmpty)
                            '($employeeCode)',
                        ].join(' '),
                      ),
                      PgInvoiceRow(
                        label: 'Dealer',
                        value: detail['dealer_name']?.toString() ?? '-',
                      ),
                      if ((dealer['owner_name']?.toString().trim().isNotEmpty ??
                          false))
                        PgInvoiceRow(
                          label: 'Owner',
                          value: dealer['owner_name'].toString(),
                        ),
                      if ((dealer['mobile']?.toString().trim().isNotEmpty ??
                          false))
                        PgInvoiceRow(
                          label: 'Dealer Mobile',
                          value: dealer['mobile'].toString(),
                        ),
                      if ((dealer['village']?.toString().trim().isNotEmpty ??
                          false))
                        PgInvoiceRow(
                          label: 'Village',
                          value: dealer['village'].toString(),
                        ),
                      PgInvoiceRow(
                        label: 'Amount',
                        value: currency.format(
                          double.tryParse('${detail['amount'] ?? 0}') ?? 0,
                        ),
                        emphasize: true,
                      ),
                      PgInvoiceRow(
                        label: 'Date',
                        value: date == null
                            ? '-'
                            : DateFormat('d MMM yyyy').format(date),
                      ),
                      if (receiptNo != null && receiptNo.isNotEmpty)
                        PgInvoiceRow(label: 'Receipt No', value: receiptNo),
                      PgInvoiceRow(
                        label: 'Status',
                        value: detail['status_label']?.toString() ?? status,
                      ),
                      if (submittedAt != null)
                        PgInvoiceRow(
                          label: 'Submitted',
                          value: submittedAt,
                        ),
                      if (remarks != null && remarks.isNotEmpty)
                        PgInvoiceRow(label: 'Remark', value: remarks),
                      if (adminRemark != null && adminRemark.isNotEmpty)
                        PgInvoiceRow(
                          label: 'Admin Remark',
                          value: adminRemark,
                        ),
                    ],
                  ),
                ),
                if (photoUrl != null && photoUrl.isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.md),
                  PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Collection Photo',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        ClipRRect(
                          borderRadius: BorderRadius.circular(12),
                          child: CachedNetworkImage(
                            imageUrl: photoUrl,
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

String? _formatDateTime(String? raw) {
  if (raw == null || raw.trim().isEmpty) return null;
  final parsed = DateTime.tryParse(raw);
  if (parsed == null) return raw;
  return DateFormat('d MMM yyyy, h:mm a').format(parsed.toLocal());
}
