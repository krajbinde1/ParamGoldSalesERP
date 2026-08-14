import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/prompt_dialog.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/director_api.dart';

typedef _PaymentListResult = ({
  int pendingCount,
  double pendingTotalAmount,
  List<Map<String, dynamic>> data,
});

class DirectorPaymentRequestsScreen extends StatefulWidget {
  const DirectorPaymentRequestsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<DirectorPaymentRequestsScreen> createState() =>
      _DirectorPaymentRequestsScreenState();
}

class _DirectorPaymentRequestsScreenState
    extends State<DirectorPaymentRequestsScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs;
  late Future<_PaymentListResult> _pending;
  late Future<_PaymentListResult> _all;
  final Set<int> _selectedIds = {};
  bool _busy = false;

  DirectorApi get _api => DirectorApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  final _currency = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 0,
  );

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 2, vsync: this);
    _pending = _api.listPaymentRequests(status: 'pending');
    _all = _api.listPaymentRequests();
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  Future<void> _reload() async {
    setState(() {
      _selectedIds.clear();
      _pending = _api.listPaymentRequests(status: 'pending');
      _all = _api.listPaymentRequests();
    });
    await Future.wait([_pending, _all]);
  }

  Future<void> _approveSelected(List<Map<String, dynamic>> items) async {
    final selected = items
        .where((item) => _selectedIds.contains(int.tryParse('${item['id']}')))
        .toList();
    if (selected.isEmpty) return;

    final total = selected.fold<double>(
      0,
      (sum, item) => sum + (double.tryParse('${item['amount'] ?? 0}') ?? 0),
    );
    final confirmed = await confirmAction(
      context,
      title: 'Approve Selected',
      message:
          'Approve ${selected.length} Payment Request${selected.length == 1 ? '' : 's'} totaling ${_currency.format(total)}?',
    );
    if (!confirmed || !mounted) return;

    setState(() => _busy = true);
    try {
      final result = await _api.approvePaymentRequestsBulk(
        ids: selected
            .map((item) => int.tryParse('${item['id']}') ?? 0)
            .where((id) => id > 0)
            .toList(),
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Approved ${result['approved'] ?? 0}'
            '${(result['failed'] ?? 0) > 0 ? ', failed ${result['failed']}' : ''}',
          ),
        ),
      );
      await _reload();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _approveAll(List<Map<String, dynamic>> items) async {
    if (items.isEmpty) return;
    final total = items.fold<double>(
      0,
      (sum, item) => sum + (double.tryParse('${item['amount'] ?? 0}') ?? 0),
    );
    final confirmed = await confirmAction(
      context,
      title: 'Approve All',
      message:
          'Approve ${items.length} Payment Request${items.length == 1 ? '' : 's'} totaling ${_currency.format(total)}?',
    );
    if (!confirmed || !mounted) return;

    setState(() => _busy = true);
    try {
      final result = await _api.approvePaymentRequestsBulk(
        approveAllPending: true,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Approved ${result['approved'] ?? 0}'
            '${(result['failed'] ?? 0) > 0 ? ', failed ${result['failed']}' : ''}',
          ),
        ),
      );
      await _reload();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Payment Request Approval',
        auth: widget.auth,
        bottom: TabBar(
          controller: _tabs,
          tabs: const [
            Tab(text: 'Pending'),
            Tab(text: 'All'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabs,
        children: [
          _pendingTab(),
          _simpleList(_all, empty: 'No payment requests'),
        ],
      ),
    );
  }

  Widget _pendingTab() {
    return RefreshIndicator(
      onRefresh: _reload,
      child: FutureBuilder<_PaymentListResult>(
        future: _pending,
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
                  message: errorMessage(snapshot.error),
                  onRetry: _reload,
                ),
              ],
            );
          }

          final result = snapshot.data!;
          final items = result.data;
          final selectable = items
              .where((item) => item['can_approve'] == true)
              .map((item) => int.tryParse('${item['id']}') ?? 0)
              .where((id) => id > 0)
              .toList();
          final allSelected = selectable.isNotEmpty &&
              selectable.every(_selectedIds.contains);

          if (items.isEmpty) {
            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: const [
                PgEmptyState(message: 'No pending payment approvals'),
              ],
            );
          }

          return ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              Text(
                'Pending Payment Approval',
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const SizedBox(height: 8),
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Total Requests: ${result.pendingCount}'),
                    const SizedBox(height: 4),
                    Text(
                      'Total Amount: ${_currency.format(result.pendingTotalAmount)}',
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              if (selectable.isNotEmpty) ...[
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    OutlinedButton(
                      onPressed: _busy
                          ? null
                          : () {
                              setState(() {
                                if (allSelected) {
                                  _selectedIds.removeAll(selectable);
                                } else {
                                  _selectedIds.addAll(selectable);
                                }
                              });
                            },
                      child: Text(allSelected ? 'Clear Selection' : 'Select All'),
                    ),
                    FilledButton(
                      onPressed: _busy || _selectedIds.isEmpty
                          ? null
                          : () => _approveSelected(items),
                      child: Text('Approve Selected (${_selectedIds.length})'),
                    ),
                    FilledButton.tonal(
                      onPressed: _busy ? null : () => _approveAll(items),
                      child: const Text('Approve All'),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
              ],
              for (final item in items) ...[
                _pendingCard(item),
                const SizedBox(height: 10),
              ],
            ],
          );
        },
      ),
    );
  }

  Widget _pendingCard(Map<String, dynamic> item) {
    final id = int.tryParse('${item['id']}') ?? 0;
    final canApprove = item['can_approve'] == true;
    final selected = _selectedIds.contains(id);

    return PgCard(
      onTap: () async {
        final result = await context.push('/director/payment-requests/$id');
        if (result == true || result == 'approved' || result == 'rejected') {
          await _reload();
        }
      },
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (canApprove)
            Checkbox(
              value: selected,
              onChanged: _busy
                  ? null
                  : (value) {
                      setState(() {
                        if (value == true) {
                          _selectedIds.add(id);
                        } else {
                          _selectedIds.remove(id);
                        }
                      });
                    },
            ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${item['request_no'] ?? '-'}',
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
                const SizedBox(height: 4),
                Text('${item['vendor_name'] ?? '-'}'),
                const SizedBox(height: 4),
                Text(
                  _currency.format(
                    double.tryParse('${item['amount'] ?? 0}') ?? 0,
                  ),
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
                if ('${item['remark'] ?? ''}'.trim().isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    '${item['remark']}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
                if (item['created_at'] != null) ...[
                  const SizedBox(height: 4),
                  Text(
                    _formatDate('${item['created_at']}'),
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _simpleList(Future<_PaymentListResult> future, {required String empty}) {
    return RefreshIndicator(
      onRefresh: _reload,
      child: FutureBuilder<_PaymentListResult>(
        future: future,
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
                  message: errorMessage(snapshot.error),
                  onRetry: _reload,
                ),
              ],
            );
          }

          final items = snapshot.data?.data ?? const [];
          if (items.isEmpty) {
            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [PgEmptyState(message: empty)],
            );
          }

          return ListView.separated(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            itemCount: items.length,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              final item = items[index];
              final id = int.tryParse('${item['id']}') ?? 0;
              return PgCard(
                onTap: () async {
                  final result =
                      await context.push('/director/payment-requests/$id');
                  if (result == true ||
                      result == 'approved' ||
                      result == 'rejected') {
                    await _reload();
                  }
                },
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            '${item['request_no'] ?? '-'}',
                            style: const TextStyle(fontWeight: FontWeight.w800),
                          ),
                        ),
                        PgStatusBadge(
                          label:
                              '${item['current_stage'] ?? item['status_label'] ?? ''}',
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text('${item['vendor_name'] ?? '-'}'),
                    const SizedBox(height: 4),
                    Text(
                      _currency.format(
                        double.tryParse('${item['amount'] ?? 0}') ?? 0,
                      ),
                      style: const TextStyle(fontWeight: FontWeight.w700),
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

  String _formatDate(String raw) {
    final dt = DateTime.tryParse(raw);
    if (dt == null) return raw;
    return DateFormat('d MMM yyyy').format(dt.toLocal());
  }
}

class DirectorPaymentRequestDetailScreen extends StatefulWidget {
  const DirectorPaymentRequestDetailScreen({
    super.key,
    required this.auth,
    required this.requestId,
  });

  final AuthController auth;
  final int requestId;

  @override
  State<DirectorPaymentRequestDetailScreen> createState() =>
      _DirectorPaymentRequestDetailScreenState();
}

class _DirectorPaymentRequestDetailScreenState
    extends State<DirectorPaymentRequestDetailScreen> {
  late Future<Map<String, dynamic>> _future;
  bool _busy = false;

  DirectorApi get _api => DirectorApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  final _currency = NumberFormat.currency(locale: 'en_IN', symbol: '₹');

  @override
  void initState() {
    super.initState();
    _future = _api.getPaymentRequest(widget.requestId);
  }

  Future<void> _reload() async {
    setState(() => _future = _api.getPaymentRequest(widget.requestId));
    await _future;
  }

  Future<void> _approve() async {
    final confirmed = await confirmAction(
      context,
      title: 'Approve Payment Request',
      message: 'Confirm approval for this payment request?',
    );
    if (!confirmed || !mounted) return;

    setState(() => _busy = true);
    try {
      await _api.approvePaymentRequest(widget.requestId);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Payment request approved')),
      );
      safePop(context, 'approved');
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reject() async {
    final confirmed = await confirmAction(
      context,
      title: 'Reject Payment Request',
      message: 'Reject this payment request?',
    );
    if (!confirmed || !mounted) return;

    final remark = await promptRemarkDialog(
      context,
      title: 'Rejection Remark',
      submitLabel: 'Reject',
    );
    if (remark == null || !mounted) return;

    setState(() => _busy = true);
    try {
      await _api.rejectPaymentRequest(widget.requestId, remark: remark);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Payment request rejected')),
      );
      safePop(context, 'rejected');
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Payment Request',
        auth: widget.auth,
      ),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              !snapshot.hasData) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(
              message: errorMessage(snapshot.error),
              onRetry: _reload,
            );
          }

          final data = snapshot.data!;
          final canApprove = data['can_approve'] == true;
          final canReject = data['can_reject'] == true;
          final timeline = (data['timeline'] as List?)
                  ?.map((e) => Map<String, dynamic>.from(e as Map))
                  .toList() ??
              const [];

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            '${data['request_no'] ?? '-'}',
                            style: Theme.of(context)
                                .textTheme
                                .titleLarge
                                ?.copyWith(fontWeight: FontWeight.w800),
                          ),
                        ),
                        PgStatusBadge(
                          label: '${data['status_label'] ?? ''}',
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    _row('Vendor Name', '${data['vendor_name'] ?? '-'}'),
                    _row('Mobile', '${data['vendor_mobile'] ?? '-'}'),
                    _row(
                      'Amount',
                      _currency.format(
                        double.tryParse('${data['amount'] ?? 0}') ?? 0,
                      ),
                    ),
                    _row('Remark', '${data['remark'] ?? '—'}'),
                    _row('Created By', '${data['created_by'] ?? '—'}'),
                    _row(
                      'Created Date',
                      _formatDateTime('${data['created_at'] ?? ''}'),
                    ),
                    _row('Current Stage', '${data['current_stage'] ?? '—'}'),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              const PgSectionHeader(title: 'Approval Timeline'),
              const SizedBox(height: AppSpacing.sm),
              PgCard(
                child: Column(
                  children: [
                    for (final step in timeline) _timelineStep(step),
                  ],
                ),
              ),
              if (canApprove || canReject) ...[
                const SizedBox(height: AppSpacing.lg),
                if (canApprove)
                  FilledButton(
                    onPressed: _busy ? null : _approve,
                    child: const Text('Approve'),
                  ),
                if (canReject) ...[
                  const SizedBox(height: 10),
                  OutlinedButton(
                    onPressed: _busy ? null : _reject,
                    child: const Text('Reject'),
                  ),
                ],
              ],
            ],
          );
        },
      ),
    );
  }

  Widget _row(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(label, style: const TextStyle(color: Colors.black54)),
          ),
          Expanded(
            child: Text(value, style: const TextStyle(fontWeight: FontWeight.w600)),
          ),
        ],
      ),
    );
  }

  Widget _timelineStep(Map<String, dynamic> step) {
    final rejected = step['is_rejection'] == true;
    final completed = step['completed'] == true;
    final current = step['is_current'] == true;
    final actor = '${step['actor'] ?? ''}';
    final role = '${step['actor_role'] ?? ''}';
    final at = '${step['at'] ?? ''}';
    final remark = '${step['remark'] ?? ''}';

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            rejected
                ? Icons.cancel
                : (completed
                    ? Icons.check_circle
                    : Icons.radio_button_unchecked),
            color: rejected
                ? Colors.red
                : (completed
                    ? Colors.green
                    : (current ? Colors.orange : Colors.grey)),
            size: 20,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${step['label'] ?? ''}',
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    color: rejected ? Colors.red.shade700 : null,
                  ),
                ),
                if (actor.isNotEmpty || role.isNotEmpty)
                  Text(
                    [actor, role].where((e) => e.isNotEmpty).join(' · '),
                    style: const TextStyle(fontSize: 12),
                  ),
                if (at.isNotEmpty)
                  Text(
                    at,
                    style: const TextStyle(fontSize: 12, color: Colors.black54),
                  ),
                if (!completed && !rejected)
                  const Text(
                    'Pending',
                    style: TextStyle(fontSize: 12, color: Colors.black45),
                  ),
                if (remark.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      remark,
                      style: TextStyle(
                        fontSize: 12,
                        color: rejected ? Colors.red.shade700 : Colors.black54,
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  String _formatDateTime(String raw) {
    final dt = DateTime.tryParse(raw);
    if (dt == null || raw.isEmpty) return '—';
    return DateFormat('d MMM yyyy, h:mm a').format(dt.toLocal());
  }
}
