import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/director_api.dart';

class DirectorTaDaClaimDetailScreen extends StatefulWidget {
  const DirectorTaDaClaimDetailScreen({
    super.key,
    required this.auth,
    required this.claimId,
  });

  final AuthController auth;
  final int claimId;

  @override
  State<DirectorTaDaClaimDetailScreen> createState() =>
      _DirectorTaDaClaimDetailScreenState();
}

class _DirectorTaDaClaimDetailScreenState
    extends State<DirectorTaDaClaimDetailScreen> {
  late Future<Map<String, dynamic>> _future;
  bool _acting = false;

  DirectorApi get _api => DirectorApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  final _currency = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 2,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.getTaDaClaim(widget.claimId);
  }

  Future<void> _reload() async {
    setState(() => _future = _api.getTaDaClaim(widget.claimId));
    await _future;
  }

  Future<void> _approve() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Approve TA Bill'),
        content: const Text('Approve this manager TA bill?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Approve'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    setState(() => _acting = true);
    try {
      await _api.approveTaDaClaim(widget.claimId);
      if (!mounted) return;
      Navigator.pop(context, true);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    } finally {
      if (mounted) setState(() => _acting = false);
    }
  }

  Future<void> _reject() async {
    final remark = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Reject TA Bill'),
        content: TextField(
          controller: remark,
          maxLines: 3,
          decoration: const InputDecoration(
            labelText: 'Rejection remark',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Reject'),
          ),
        ],
      ),
    );
    final text = remark.text.trim();
    remark.dispose();
    if (confirmed != true || !mounted) return;
    if (text.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Rejection remark is required.')),
      );
      return;
    }
    setState(() => _acting = true);
    try {
      await _api.rejectTaDaClaim(widget.claimId, remark: text);
      if (!mounted) return;
      Navigator.pop(context, true);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    } finally {
      if (mounted) setState(() => _acting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'TA Bill', auth: widget.auth),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              !snapshot.hasData) {
            return const PgLoadingState();
          }
          if (snapshot.hasError || !snapshot.hasData) {
            return PgErrorState(
              message: 'Unable to open this TA bill.',
              onRetry: _reload,
            );
          }
          final claim = snapshot.data!;
          final pending = claim['status'] == 'pending';
          final photo = claim['bill_photo_url']?.toString();
          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              PgDetailHeader(
                title: claim['claim_no']?.toString() ?? 'TA Bill',
                subtitle: claim['employee_name']?.toString() ?? 'Manager',
                badgeLabel: claim['status_label']?.toString() ?? 'Pending',
              ),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Column(
                  children: [
                    PgInvoiceRow(
                      label: 'Manager',
                      value: claim['employee_name']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'Date',
                      value: claim['claim_date']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'Submitted',
                      value: claim['submitted_at']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'From',
                      value: claim['from_location']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'To',
                      value: claim['to_location']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'Travel KM',
                      value: '${claim['travel_km'] ?? 0}',
                    ),
                    PgInvoiceRow(
                      label: 'Travel Amount',
                      value: _currency.format(
                        double.tryParse('${claim['travel_amount'] ?? 0}') ?? 0,
                      ),
                    ),
                    PgInvoiceRow(
                      label: 'DA Amount',
                      value: _currency.format(
                        double.tryParse('${claim['da_amount'] ?? 0}') ?? 0,
                      ),
                    ),
                    PgInvoiceRow(
                      label: 'Other Amount',
                      value: _currency.format(
                        double.tryParse('${claim['other_expense'] ?? 0}') ?? 0,
                      ),
                    ),
                    PgInvoiceRow(
                      label: 'Total Amount',
                      value: _currency.format(
                        double.tryParse('${claim['total_amount'] ?? 0}') ?? 0,
                      ),
                      isTotal: true,
                    ),
                    if ((claim['employee_remarks']?.toString() ?? '').isNotEmpty)
                      PgInvoiceRow(
                        label: 'Remarks',
                        value: claim['employee_remarks'].toString(),
                      ),
                    if ((claim['admin_remark']?.toString() ?? '').isNotEmpty)
                      PgInvoiceRow(
                        label: 'Rejection Remark',
                        value: claim['admin_remark'].toString(),
                      ),
                  ],
                ),
              ),
              if (photo != null && photo.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: CachedNetworkImage(
                    imageUrl: photo,
                    height: 220,
                    width: double.infinity,
                    fit: BoxFit.cover,
                    errorWidget: (_, _, _) =>
                        const Icon(Icons.broken_image, size: 48),
                  ),
                ),
              ],
              if (pending) ...[
                const SizedBox(height: AppSpacing.lg),
                FilledButton(
                  onPressed: _acting ? null : _approve,
                  child: const Text('Approve'),
                ),
                const SizedBox(height: AppSpacing.sm),
                OutlinedButton(
                  onPressed: _acting ? null : _reject,
                  child: const Text('Reject'),
                ),
              ],
            ],
          );
        },
      ),
    );
  }
}
