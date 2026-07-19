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

class ManagerOrdersScreen extends StatefulWidget {
  const ManagerOrdersScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<ManagerOrdersScreen> createState() => _ManagerOrdersScreenState();
}

class _ManagerOrdersScreenState extends State<ManagerOrdersScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  late ManagerApi _api;

  Future<ManagerOrderListResult>? _pendingFuture;
  Future<ManagerOrderListResult>? _approvedFuture;
  Future<ManagerOrderListResult>? _dispatchedFuture;
  ManagerOrderCounts _counts = const ManagerOrderCounts(
    pendingApproval: 0,
    approved: 0,
    dispatched: 0,
  );

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
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

  void _reloadAll() {
    setState(() {
      _pendingFuture = _api.listOrders(status: 'pending_approval');
      _approvedFuture = _api.listOrders(status: 'approved');
      _dispatchedFuture = _api.listOrders(status: 'dispatched');
    });
  }

  Future<void> _refreshAll() async {
    _reloadAll();
    await Future.wait([
      _pendingFuture!,
      _approvedFuture!,
      _dispatchedFuture!,
    ]);
  }

  void _updateCounts(ManagerOrderCounts counts) {
    if (_counts.pendingApproval == counts.pendingApproval &&
        _counts.approved == counts.approved &&
        _counts.dispatched == counts.dispatched) {
      return;
    }
    setState(() => _counts = counts);
  }

  Future<void> _openOrderDetail(int orderId, {required int tabIndex}) async {
    final result = await context.push<bool>('/manager/orders/$orderId');
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
        title: 'Order Approvals',
        auth: widget.auth,
        bottom: TabBar(
          controller: _tabController,
          isScrollable: true,
          tabAlignment: TabAlignment.start,
          tabs: [
            Tab(text: 'Pending (${_counts.pendingApproval})'),
            Tab(text: 'Approved (${_counts.approved})'),
            Tab(text: 'Dispatched (${_counts.dispatched})'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          _ManagerOrderTab(
            future: _pendingFuture,
            emptyMessage: 'No pending orders for approval.',
            onCounts: _updateCounts,
            onRefresh: _refreshAll,
            onTap: (id) => _openOrderDetail(id, tabIndex: 0),
          ),
          _ManagerOrderTab(
            future: _approvedFuture,
            emptyMessage: 'No approved orders.',
            onCounts: _updateCounts,
            onRefresh: _refreshAll,
            onTap: (id) => _openOrderDetail(id, tabIndex: 1),
          ),
          _ManagerOrderTab(
            future: _dispatchedFuture,
            emptyMessage: 'No dispatched orders.',
            onCounts: _updateCounts,
            onRefresh: _refreshAll,
            onTap: (id) => _openOrderDetail(id, tabIndex: 2),
          ),
        ],
      ),
    );
  }
}

class _ManagerOrderTab extends StatelessWidget {
  const _ManagerOrderTab({
    required this.future,
    required this.emptyMessage,
    required this.onCounts,
    required this.onRefresh,
    required this.onTap,
  });

  final Future<ManagerOrderListResult>? future;
  final String emptyMessage;
  final void Function(ManagerOrderCounts counts) onCounts;
  final Future<void> Function() onRefresh;
  final void Function(int id) onTap;

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(locale: 'en_IN', symbol: '₹');

    return FutureBuilder<ManagerOrderListResult>(
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
          onCounts(result.counts);
        });
        final orders = result.orders;

        if (orders.isEmpty) {
          return RefreshIndicator(
            onRefresh: onRefresh,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                SizedBox(
                  height: MediaQuery.sizeOf(context).height * 0.5,
                  child: PgEmptyState(message: emptyMessage),
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
            itemCount: orders.length,
            itemBuilder: (context, index) {
              final order = orders[index];
              final statusLabel = order['status_label']?.toString();
              final status = order['status']?.toString() ?? '';
              return PgCard(
                onTap: () =>
                    onTap(int.tryParse('${order['id'] ?? 0}') ?? 0),
                margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            order['order_no']?.toString() ?? '-',
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                          const SizedBox(height: 4),
                          Text(
                            '${order['employee_name'] ?? '-'} • ${order['dealer_name'] ?? '-'}',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          currency.format(
                            double.tryParse('${order['grand_total'] ?? 0}') ?? 0,
                          ),
                          style: Theme.of(context).textTheme.titleSmall,
                        ),
                        if (statusLabel != null) ...[
                          const SizedBox(height: 4),
                          PgStatusBadge(
                            label: statusLabel,
                            tone: PgStatusRules.orderTone(status),
                          ),
                        ],
                      ],
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

class ManagerOrderDetailScreen extends StatefulWidget {
  const ManagerOrderDetailScreen({
    super.key,
    required this.auth,
    required this.orderId,
  });

  final AuthController auth;
  final int orderId;

  @override
  State<ManagerOrderDetailScreen> createState() =>
      _ManagerOrderDetailScreenState();
}

class _ManagerOrderDetailScreenState extends State<ManagerOrderDetailScreen> {
  late Future<Map<String, dynamic>> _future;
  bool _submitting = false;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.getOrder(widget.orderId);
  }

  Future<void> _approve() async {
    if (_submitting || !widget.auth.permissions.canApproveOrders) return;
    final confirmed = await confirmAction(
      context,
      title: 'Approve Order',
      message: 'Approve this order?',
    );
    if (!confirmed || !mounted) return;

    final remark = await promptRemarkDialog(
      context,
      title: 'Approval Remark',
      required: false,
    );
    if (!mounted) return;

    setState(() => _submitting = true);
    try {
      await _api.approveOrder(
        widget.orderId,
        remark: remark?.isNotEmpty == true ? remark : null,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Order approved.')));
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
    if (_submitting || !widget.auth.permissions.canRejectOrders) return;
    final confirmed = await confirmAction(
      context,
      title: 'Reject Order',
      message: 'Reject this order?',
    );
    if (!confirmed || !mounted) return;

    final remark = await promptRemarkDialog(
      context,
      title: 'Rejection Remark',
    );
    if (remark == null || !mounted) return;

    setState(() => _submitting = true);
    try {
      await _api.rejectOrder(widget.orderId, remark: remark);
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Order rejected.')));
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
    final dateFormat = DateFormat('dd MMM yyyy, hh:mm a');

    return Scaffold(
      appBar: RoleAppBar(title: 'Order Details', auth: widget.auth),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(message: errorMessage(snapshot.error));
          }

          final order = snapshot.data!;
          final status = order['status']?.toString() ?? '';
          final items = (order['items'] as List?) ?? const [];
          final isPending = status == 'pending_approval';

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              PgDetailHeader(
                title: order['order_no']?.toString() ?? '-',
                subtitle: order['dealer']?['firm_name']?.toString() ?? '-',
                badgeLabel: order['status_label']?.toString(),
                badgeTone: PgStatusRules.orderTone(status),
              ),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Order Info',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    PgInvoiceRow(
                      label: 'Employee',
                      value: order['employee_name']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'Total',
                      value: currency.format(
                        double.tryParse('${order['grand_total'] ?? 0}') ?? 0,
                      ),
                      emphasize: true,
                    ),
                    if (order['remarks'] != null &&
                        order['remarks'].toString().isNotEmpty)
                      PgInvoiceRow(
                        label: 'Remarks',
                        value: order['remarks'].toString(),
                      ),
                    if (order['approved_at'] != null) ...[
                      PgInvoiceRow(
                        label: 'Approved',
                        value: _formatDate(order['approved_at'], dateFormat),
                      ),
                      if (order['approved_by_name'] != null)
                        PgInvoiceRow(
                          label: 'Approved By',
                          value: order['approved_by_name'].toString(),
                        ),
                    ],
                    if (order['rejected_at'] != null) ...[
                      PgInvoiceRow(
                        label: 'Rejected',
                        value: _formatDate(order['rejected_at'], dateFormat),
                      ),
                      if (order['rejected_by_name'] != null)
                        PgInvoiceRow(
                          label: 'Rejected By',
                          value: order['rejected_by_name'].toString(),
                        ),
                      if (order['rejection_remark'] != null)
                        PgInvoiceRow(
                          label: 'Rejection Remark',
                          value: order['rejection_remark'].toString(),
                        ),
                    ],
                    if (order['dispatched_at'] != null) ...[
                      PgInvoiceRow(
                        label: 'Dispatched',
                        value: _formatDate(order['dispatched_at'], dateFormat),
                      ),
                      if (order['dispatched_by_name'] != null)
                        PgInvoiceRow(
                          label: 'Dispatched By',
                          value: order['dispatched_by_name'].toString(),
                        ),
                      if (order['dispatch_remark'] != null &&
                          order['dispatch_remark'].toString().isNotEmpty)
                        PgInvoiceRow(
                          label: 'Dispatch Remark',
                          value: order['dispatch_remark'].toString(),
                        ),
                      if (order['transport_type_label'] != null) ...[
                        PgInvoiceRow(
                          label: 'Transport',
                          value: order['transport_type_label'].toString(),
                        ),
                        PgInvoiceRow(
                          label: 'Transport Amount',
                          value: currency.format(
                            double.tryParse(
                                  '${order['transport_amount'] ?? 0}',
                                ) ??
                                0,
                          ),
                        ),
                      ],
                    ],
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Items',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    ...items.map(
                      (item) => Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              item['product_name']?.toString() ?? '-',
                              style: Theme.of(context).textTheme.titleSmall,
                            ),
                            PgInvoiceRow(
                              label: 'Quantity',
                              value: _caseWiseSummary(item),
                            ),
                            PgInvoiceRow(
                              label: 'Rate Per No',
                              value: currency.format(
                                double.tryParse(
                                      '${item['rate_per_no'] ?? item['rate'] ?? 0}',
                                    ) ??
                                    0,
                              ),
                            ),
                            PgInvoiceRow(
                              label: 'Line Total',
                              value: currency.format(
                                double.tryParse('${item['line_total'] ?? 0}') ??
                                    0,
                              ),
                              emphasize: true,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              if (isPending && widget.auth.permissions.canApproveOrders) ...[
                const SizedBox(height: AppSpacing.md),
                FilledButton(
                  onPressed: _submitting ? null : _approve,
                  child: const Text('Approve Order'),
                ),
                const SizedBox(height: AppSpacing.sm),
                OutlinedButton(
                  onPressed: _submitting ? null : _reject,
                  child: const Text('Reject Order'),
                ),
              ],
            ],
          );
        },
      ),
    );
  }

  String _formatDate(Object? value, DateFormat format) {
    if (value == null) return '-';
    final parsed = DateTime.tryParse(value.toString());
    return parsed == null ? value.toString() : format.format(parsed.toLocal());
  }

  String _caseWiseSummary(Map<String, dynamic> item) {
    final display = item['display_summary']?.toString();
    if (display != null && display.isNotEmpty) return display;

    final cases = int.tryParse('${item['case_quantity'] ?? 0}') ?? 0;
    final nosPerCase = int.tryParse('${item['nos_per_case'] ?? 0}') ?? 0;
    final totalNos =
        int.tryParse('${item['total_quantity_nos'] ?? 0}') ?? cases * nosPerCase;
    return '$cases Cases × $nosPerCase Nos = $totalNos Nos';
  }
}
