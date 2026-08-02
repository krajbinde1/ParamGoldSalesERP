import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/prompt_dialog.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/manager_api.dart';

class ManagerTaDaClaimsScreen extends StatefulWidget {
  const ManagerTaDaClaimsScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<ManagerTaDaClaimsScreen> createState() =>
      _ManagerTaDaClaimsScreenState();
}

class _ManagerTaDaClaimsScreenState extends State<ManagerTaDaClaimsScreen> {
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    _future = ManagerApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
          .dio,
    ).listTaDaClaims(status: 'pending');
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(locale: 'en_IN', symbol: '₹');

    return Scaffold(
      appBar: RoleAppBar(title: 'Pending TA/DA Claims', auth: widget.auth),
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(message: errorMessage(snapshot.error));
          }
          final claims = snapshot.data ?? const [];
          if (claims.isEmpty) {
            return const PgEmptyState(
              message: 'No pending TA/DA claims.',
              icon: const Icon(Icons.receipt_long_outlined),
            );
          }
          return ListView.builder(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            itemCount: claims.length,
            itemBuilder: (context, index) {
              final claim = claims[index];
              return PgCard(
                onTap: () =>
                    context.push('/manager/ta-da-claims/${claim['id']}'),
                margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            claim['employee_name']?.toString() ?? '-',
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                          Text(
                            claim['claim_date']?.toString() ?? '-',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    Text(
                      currency.format(
                        double.tryParse('${claim['total_amount'] ?? 0}') ?? 0,
                      ),
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                  ],
                ),
              );
            },
          );
        },
      ),
    );
  }
}

class ManagerTaDaClaimDetailScreen extends StatefulWidget {
  const ManagerTaDaClaimDetailScreen({
    super.key,
    required this.auth,
    required this.claimId,
  });

  final AuthController auth;
  final int claimId;

  @override
  State<ManagerTaDaClaimDetailScreen> createState() =>
      _ManagerTaDaClaimDetailScreenState();
}

class _ManagerTaDaClaimDetailScreenState
    extends State<ManagerTaDaClaimDetailScreen> {
  late Future<Map<String, dynamic>> _future;
  bool _submitting = false;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.getTaDaClaim(widget.claimId);
  }

  Future<void> _approve() async {
    if (_submitting) return;
    final confirmed = await confirmAction(
      context,
      title: 'Approve Claim',
      message: 'Approve this TA/DA claim?',
    );
    if (!confirmed) return;
    setState(() => _submitting = true);
    try {
      await _api.approveTaDaClaim(widget.claimId);
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Claim approved.')));
      safePop(context, true);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(errorMessage(error))));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _reject() async {
    if (_submitting) return;
    final remark = await promptRemarkDialog(context, title: 'Reject Claim');
    if (remark == null) return;
    setState(() => _submitting = true);
    try {
      await _api.rejectTaDaClaim(widget.claimId, remark: remark);
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Claim rejected.')));
      safePop(context, true);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(errorMessage(error))));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(locale: 'en_IN', symbol: '₹');

    return Scaffold(
      appBar: RoleAppBar(title: 'TA/DA Claim', auth: widget.auth),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(message: errorMessage(snapshot.error));
          }
          final claim = snapshot.data!;
          final status = claim['status']?.toString() ?? '';

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              PgDetailHeader(
                title: claim['employee_name']?.toString() ?? '-',
                subtitle: claim['claim_date']?.toString() ?? '-',
                badgeLabel: claim['status_label']?.toString(),
                badgeTone: PgStatusRules.claimTone(status),
              ),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    PgInvoiceRow(
                      label: 'Travel KM',
                      value: '${claim['travel_km'] ?? 0}',
                    ),
                    PgInvoiceRow(
                      label: 'Per KM Rate',
                      value: currency.format(
                        double.tryParse('${claim['per_km_rate'] ?? 0}') ?? 0,
                      ),
                    ),
                    PgInvoiceRow(
                      label: 'Travel Amount',
                      value: currency.format(
                        double.tryParse('${claim['travel_amount'] ?? 0}') ?? 0,
                      ),
                    ),
                    PgInvoiceRow(
                      label: 'DA Amount',
                      value: currency.format(
                        double.tryParse('${claim['da_amount'] ?? 0}') ?? 0,
                      ),
                    ),
                    PgInvoiceRow(
                      label: 'Other Amount',
                      value: currency.format(
                        double.tryParse('${claim['other_expense'] ?? 0}') ?? 0,
                      ),
                    ),
                    const Divider(height: AppSpacing.lg),
                    PgInvoiceRow(
                      label: 'Total',
                      value: currency.format(
                        double.tryParse('${claim['total_amount'] ?? 0}') ?? 0,
                      ),
                      isTotal: true,
                    ),
                  ],
                ),
              ),
              if (status == 'pending' &&
                  widget.auth.permissions.canApproveTaDa) ...[
                const SizedBox(height: 16),
                FilledButton(
                  onPressed: _submitting ? null : _approve,
                  child: const Text('Approve Claim'),
                ),
                const SizedBox(height: 8),
                OutlinedButton(
                  onPressed: _submitting ? null : _reject,
                  child: const Text('Reject Claim'),
                ),
              ],
            ],
          );
        },
      ),
    );
  }
}

