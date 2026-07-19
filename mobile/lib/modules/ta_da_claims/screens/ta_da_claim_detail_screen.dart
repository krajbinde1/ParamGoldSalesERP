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
import '../api/ta_da_claim_api.dart';
import '../models/ta_da_claim_detail.dart';

class TaDaClaimDetailScreen extends StatefulWidget {
  const TaDaClaimDetailScreen({
    super.key,
    required this.claimId,
    required this.auth,
  });

  final int claimId;
  final AuthController auth;

  @override
  State<TaDaClaimDetailScreen> createState() => _TaDaClaimDetailScreenState();
}

class _TaDaClaimDetailScreenState extends State<TaDaClaimDetailScreen> {
  late Future<TaDaClaimDetail> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<TaDaClaimDetail> _load() => TaDaClaimApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  ).getClaim(widget.claimId);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 2,
    );

    return PgPageScaffold(
      title: 'Claim Details',
      showBack: true,
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<TaDaClaimDetail>(
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
                    message: 'Unable to load claim details.',
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
                  title: DateFormat('d MMM yyyy').format(detail.claimDate),
                  subtitle: '${detail.fromLocation} → ${detail.toLocation}',
                  badgeLabel: detail.statusLabel,
                  badgeTone: PgStatusRules.claimTone(detail.status),
                ),
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Claim Info',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      if (detail.employeeName != null)
                        PgInvoiceRow(
                          label: 'Employee',
                          value: detail.employeeName!,
                        ),
                      PgInvoiceRow(
                        label: 'From Location',
                        value: detail.fromLocation,
                      ),
                      PgInvoiceRow(
                        label: 'To Location',
                        value: detail.toLocation,
                      ),
                      PgInvoiceRow(
                        label: 'Travel KM',
                        value: detail.travelKm.toStringAsFixed(2),
                      ),
                      PgInvoiceRow(
                        label: 'Per KM Rate',
                        value: currency.format(detail.perKmRate),
                      ),
                      PgInvoiceRow(
                        label: 'Travel Amount',
                        value: currency.format(detail.travelAmount),
                      ),
                      PgInvoiceRow(
                        label: 'DA Amount',
                        value: currency.format(detail.daAmount),
                      ),
                      PgInvoiceRow(
                        label: 'Other Amount',
                        value: currency.format(detail.otherExpense),
                      ),
                      const Divider(height: AppSpacing.lg),
                      PgInvoiceRow(
                        label: 'Total Amount',
                        value: currency.format(detail.totalAmount),
                        isTotal: true,
                      ),
                      if (detail.employeeRemarks != null &&
                          detail.employeeRemarks!.isNotEmpty)
                        PgInvoiceRow(
                          label: 'Employee Remarks',
                          value: detail.employeeRemarks!,
                        ),
                      if (detail.adminRemark != null &&
                          detail.adminRemark!.isNotEmpty)
                        PgInvoiceRow(
                          label: 'Admin Remark',
                          value: detail.adminRemark!,
                        ),
                    ],
                  ),
                ),
                if (detail.billPhotoUrl != null) ...[
                  const SizedBox(height: AppSpacing.md),
                  PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Bill Photo',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        ClipRRect(
                          borderRadius: BorderRadius.circular(12),
                          child: CachedNetworkImage(
                            imageUrl: detail.billPhotoUrl!,
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
