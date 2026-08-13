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

enum _ManagerTaDaTabKey { pending, approved, rejected }

class ManagerTaDaClaimsScreen extends StatefulWidget {
  const ManagerTaDaClaimsScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<ManagerTaDaClaimsScreen> createState() =>
      _ManagerTaDaClaimsScreenState();
}

class _ManagerTaDaClaimsScreenState extends State<ManagerTaDaClaimsScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  late ManagerApi _api;

  Future<ManagerTaDaListResult>? _pendingFuture;
  Future<ManagerTaDaListResult>? _approvedFuture;
  Future<ManagerTaDaListResult>? _rejectedFuture;

  int _pendingCount = 0;
  int _approvedCount = 0;
  int _rejectedCount = 0;

  static const _tabs = [
    _ManagerTaDaTabKey.pending,
    _ManagerTaDaTabKey.approved,
    _ManagerTaDaTabKey.rejected,
  ];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: _tabs.length, vsync: this);
    _api = ManagerApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    );
    _reloadAll();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<ManagerTaDaListResult> _loadTab(_ManagerTaDaTabKey tab) {
    return _api.listTaDaClaimsWithCounts(
      status: switch (tab) {
        _ManagerTaDaTabKey.pending => 'pending',
        _ManagerTaDaTabKey.approved => 'approved',
        _ManagerTaDaTabKey.rejected => 'rejected',
      },
    );
  }

  void _reloadAll() {
    setState(() {
      _pendingFuture = _loadTab(_ManagerTaDaTabKey.pending);
      _approvedFuture = _loadTab(_ManagerTaDaTabKey.approved);
      _rejectedFuture = _loadTab(_ManagerTaDaTabKey.rejected);
    });
  }

  Future<void> _refreshAll() async {
    _reloadAll();
    await Future.wait([
      _pendingFuture!,
      _approvedFuture!,
      _rejectedFuture!,
    ]);
  }

  void _updateCounts(ManagerTaDaListResult result) {
    if (_pendingCount == result.pending &&
        _approvedCount == result.approved &&
        _rejectedCount == result.rejected) {
      return;
    }
    setState(() {
      _pendingCount = result.pending;
      _approvedCount = result.approved;
      _rejectedCount = result.rejected;
    });
  }

  Future<void> _openClaimDetail(int claimId, {required int tabIndex}) async {
    final result = await context.push<bool>('/manager/ta-da-claims/$claimId');
    if (!mounted || result != true) return;
    await _refreshAll();
    if (tabIndex == 0) {
      _tabController.animateTo(1);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'TA Approval',
        auth: widget.auth,
        bottom: TabBar(
          controller: _tabController,
          tabs: [
            Tab(text: 'Pending ($_pendingCount)'),
            Tab(text: 'Approved ($_approvedCount)'),
            Tab(text: 'Rejected ($_rejectedCount)'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          _ManagerTaDaTab(
            future: _pendingFuture,
            emptyMessage: 'No pending TA/DA claims.',
            onCounts: _updateCounts,
            onRefresh: _refreshAll,
            onTap: (id) => _openClaimDetail(id, tabIndex: 0),
          ),
          _ManagerTaDaTab(
            future: _approvedFuture,
            emptyMessage: 'No approved TA/DA claims.',
            onCounts: _updateCounts,
            onRefresh: _refreshAll,
            onTap: (id) => _openClaimDetail(id, tabIndex: 1),
          ),
          _ManagerTaDaTab(
            future: _rejectedFuture,
            emptyMessage: 'No rejected TA/DA claims.',
            onCounts: _updateCounts,
            onRefresh: _refreshAll,
            onTap: (id) => _openClaimDetail(id, tabIndex: 2),
          ),
        ],
      ),
    );
  }
}

class _ManagerTaDaTab extends StatelessWidget {
  const _ManagerTaDaTab({
    required this.future,
    required this.emptyMessage,
    required this.onCounts,
    required this.onRefresh,
    required this.onTap,
  });

  final Future<ManagerTaDaListResult>? future;
  final String emptyMessage;
  final void Function(ManagerTaDaListResult result) onCounts;
  final Future<void> Function() onRefresh;
  final void Function(int id) onTap;

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(locale: 'en_IN', symbol: '₹');

    return FutureBuilder<ManagerTaDaListResult>(
      future: future,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting &&
            !snapshot.hasData) {
          return const PgLoadingState();
        }

        if (snapshot.hasError) {
          return RefreshIndicator(
            onRefresh: onRefresh,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgErrorState(message: errorMessage(snapshot.error)),
              ],
            ),
          );
        }

        final result = snapshot.data!;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          onCounts(result);
        });
        final claims = result.claims;

        if (claims.isEmpty) {
          return RefreshIndicator(
            onRefresh: onRefresh,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                SizedBox(
                  height: MediaQuery.sizeOf(context).height * 0.5,
                  child: PgEmptyState(
                    message: emptyMessage,
                    icon: const Icon(Icons.receipt_long_outlined),
                  ),
                ),
              ],
            ),
          );
        }

        return RefreshIndicator(
          onRefresh: onRefresh,
          child: ListView.builder(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            itemCount: claims.length,
            itemBuilder: (context, index) {
              final claim = claims[index];
              final status = claim['status']?.toString() ?? '';
              return PgCard(
                onTap: () =>
                    onTap(int.tryParse('${claim['id'] ?? 0}') ?? 0),
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
                          if (claim['status_label'] != null) ...[
                            const SizedBox(height: 6),
                            PgStatusBadge(
                              label: claim['status_label'].toString(),
                              tone: PgStatusRules.claimTone(status),
                            ),
                          ],
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
          ),
        );
      },
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
