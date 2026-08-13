import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/utils/bill_document.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/prompt_dialog.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../../orders/models/order_detail.dart';
import '../../orders/widgets/order_invoice_products_table.dart';
import '../../orders/widgets/order_widgets.dart';
import '../api/manager_api.dart';

enum _ManagerOrderTabKey { pending, approved, dispatched, rejected }

class ManagerOrdersScreen extends StatefulWidget {
  const ManagerOrdersScreen({
    super.key,
    required this.auth,
    this.initialTab = 'pending',
  });

  final AuthController auth;
  final String initialTab;

  @override
  State<ManagerOrdersScreen> createState() => _ManagerOrdersScreenState();
}

class _ManagerOrdersScreenState extends State<ManagerOrdersScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  late ManagerApi _api;

  final _searchController = TextEditingController();
  final _salesPersonController = TextEditingController();
  final _dealerController = TextEditingController();
  final _orderNoController = TextEditingController();

  DateTime? _dateFrom;
  DateTime? _dateTo;

  Future<ManagerOrderListResult>? _pendingFuture;
  Future<ManagerOrderListResult>? _approvedFuture;
  Future<ManagerOrderListResult>? _dispatchedFuture;
  Future<ManagerOrderListResult>? _rejectedFuture;

  ManagerOrderCounts _counts = const ManagerOrderCounts(
    pendingApproval: 0,
    approved: 0,
    rejected: 0,
    dispatched: 0,
    billed: 0,
    all: 0,
  );

  static const _tabs = [
    _ManagerOrderTabKey.pending,
    _ManagerOrderTabKey.approved,
    _ManagerOrderTabKey.dispatched,
    _ManagerOrderTabKey.rejected,
  ];

  @override
  void initState() {
    super.initState();
    final initialIndex = switch (widget.initialTab) {
      'approved' => 1,
      'dispatched' => 2,
      'rejected' => 3,
      'pending' || 'placed' || 'pending_approval' => 0,
      _ => 0,
    };
    _tabController = TabController(
      length: _tabs.length,
      vsync: this,
      initialIndex: initialIndex,
    );
    _api = ManagerApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    );
    _reloadAll();
  }

  @override
  void dispose() {
    _tabController.dispose();
    _searchController.dispose();
    _salesPersonController.dispose();
    _dealerController.dispose();
    _orderNoController.dispose();
    super.dispose();
  }

  Map<String, String?> get _filters => {
    'search': _searchController.text.trim(),
    'salesPerson': _salesPersonController.text.trim(),
    'dealer': _dealerController.text.trim(),
    'orderNo': _orderNoController.text.trim(),
    'dateFrom': _dateFrom == null
        ? null
        : DateFormat('yyyy-MM-dd').format(_dateFrom!),
    'dateTo': _dateTo == null
        ? null
        : DateFormat('yyyy-MM-dd').format(_dateTo!),
  };

  Future<ManagerOrderListResult> _loadTab(_ManagerOrderTabKey tab) {
    final filters = _filters;
    return _api.listOrders(
      status: switch (tab) {
        _ManagerOrderTabKey.pending => 'pending_approval',
        _ManagerOrderTabKey.approved => 'approved',
        _ManagerOrderTabKey.dispatched => 'dispatched',
        _ManagerOrderTabKey.rejected => 'rejected',
      },
      search: filters['search'],
      salesPerson: filters['salesPerson'],
      dealer: filters['dealer'],
      orderNo: filters['orderNo'],
      dateFrom: filters['dateFrom'],
      dateTo: filters['dateTo'],
    );
  }

  void _reloadAll() {
    setState(() {
      _pendingFuture = _loadTab(_ManagerOrderTabKey.pending);
      _approvedFuture = _loadTab(_ManagerOrderTabKey.approved);
      _dispatchedFuture = _loadTab(_ManagerOrderTabKey.dispatched);
      _rejectedFuture = _loadTab(_ManagerOrderTabKey.rejected);
    });
  }

  Future<void> _refreshAll() async {
    _reloadAll();
    await Future.wait([
      _pendingFuture!,
      _approvedFuture!,
      _dispatchedFuture!,
      _rejectedFuture!,
    ]);
  }

  void _updateCounts(ManagerOrderCounts counts) {
    if (_counts.pendingApproval == counts.pendingApproval &&
        _counts.approved == counts.approved &&
        _counts.dispatched == counts.dispatched &&
        _counts.rejected == counts.rejected) {
      return;
    }
    setState(() => _counts = counts);
  }

  Future<void> _openOrderDetail(int orderId, {required int tabIndex}) async {
    final result = await context.push<Object?>('/manager/orders/$orderId');
    if (!mounted || result == null || result == false) return;

    await _refreshAll();
    if (!mounted) return;

    if (result == 'approved' || (result == true && tabIndex == 0)) {
      _tabController.animateTo(1);
    } else if (result == 'rejected') {
      _tabController.animateTo(3);
    }
  }

  Future<void> _pickDate({required bool isFrom}) async {
    final initial = isFrom ? (_dateFrom ?? DateTime.now()) : (_dateTo ?? DateTime.now());
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(2020),
      lastDate: DateTime.now().add(const Duration(days: 1)),
    );
    if (picked == null) return;
    setState(() {
      if (isFrom) {
        _dateFrom = picked;
      } else {
        _dateTo = picked;
      }
    });
  }

  Future<void> _openFilters() async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (context) {
        return Padding(
          padding: EdgeInsets.only(
            left: AppSpacing.screenPadding,
            right: AppSpacing.screenPadding,
            top: AppSpacing.screenPadding,
            bottom: MediaQuery.viewInsetsOf(context).bottom + AppSpacing.screenPadding,
          ),
          child: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  'Filter Orders',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: AppSpacing.md),
                TextField(
                  controller: _searchController,
                  decoration: const InputDecoration(
                    labelText: 'Search',
                    hintText: 'Order / salesperson / dealer',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                TextField(
                  controller: _salesPersonController,
                  decoration: const InputDecoration(
                    labelText: 'Sales Person',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                TextField(
                  controller: _dealerController,
                  decoration: const InputDecoration(
                    labelText: 'Dealer',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                TextField(
                  controller: _orderNoController,
                  decoration: const InputDecoration(
                    labelText: 'Order Number',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton(
                        onPressed: () => _pickDate(isFrom: true),
                        child: Text(
                          _dateFrom == null
                              ? 'Date From'
                              : DateFormat('dd MMM yyyy').format(_dateFrom!),
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: OutlinedButton(
                        onPressed: () => _pickDate(isFrom: false),
                        child: Text(
                          _dateTo == null
                              ? 'Date To'
                              : DateFormat('dd MMM yyyy').format(_dateTo!),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton(
                        onPressed: () {
                          _searchController.clear();
                          _salesPersonController.clear();
                          _dealerController.clear();
                          _orderNoController.clear();
                          setState(() {
                            _dateFrom = null;
                            _dateTo = null;
                          });
                          Navigator.pop(context);
                          _reloadAll();
                        },
                        child: const Text('Clear'),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: FilledButton(
                        onPressed: () {
                          Navigator.pop(context);
                          _reloadAll();
                        },
                        child: const Text('Apply'),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Orders',
        auth: widget.auth,
        actions: [
          IconButton(
            tooltip: 'Filters',
            onPressed: _openFilters,
            icon: const Icon(Icons.filter_list),
          ),
        ],
        bottom: TabBar(
          controller: _tabController,
          isScrollable: true,
          tabAlignment: TabAlignment.start,
          tabs: [
            Tab(text: 'Pending (${_counts.pendingApproval})'),
            Tab(text: 'Approved (${_counts.approved})'),
            Tab(text: 'Dispatched (${_counts.dispatched})'),
            Tab(text: 'Rejected (${_counts.rejected})'),
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
          _ManagerOrderTab(
            future: _rejectedFuture,
            emptyMessage: 'No rejected orders.',
            onCounts: _updateCounts,
            onRefresh: _refreshAll,
            onTap: (id) => _openOrderDetail(id, tabIndex: 3),
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
    final dateFormat = DateFormat('dd MMM yyyy, hh:mm a');

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
            itemBuilder: (context, index) => _orderCard(
              context,
              orders[index],
              currency,
              dateFormat,
            ),
          ),
        );
      },
    );
  }

  Widget _orderCard(
    BuildContext context,
    Map<String, dynamic> order,
    NumberFormat currency,
    DateFormat dateFormat,
  ) {
    final statusLabel = order['status_label']?.toString();
    final status = order['status']?.toString() ?? '';
    final createdAt = DateTime.tryParse(order['created_at']?.toString() ?? '');
    final dealerLocation = order['dealer_location']?.toString() ?? '';

    return PgCard(
      onTap: () => onTap(int.tryParse('${order['id'] ?? 0}') ?? 0),
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  order['order_no']?.toString() ?? '-',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              Text(
                currency.format(
                  double.tryParse('${order['grand_total'] ?? 0}') ?? 0,
                ),
                style: Theme.of(context).textTheme.titleSmall,
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            createdAt == null
                ? (order['order_date']?.toString() ?? '-')
                : dateFormat.format(createdAt.toLocal()),
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 4),
          Text(
            order['dealer_name']?.toString() ?? '-',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
          if (dealerLocation.isNotEmpty)
            Text(
              dealerLocation,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          Text(
            'Sales Person: ${order['employee_name'] ?? '-'}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          if (statusLabel != null) ...[
            const SizedBox(height: 8),
            PgStatusBadge(
              label: statusLabel,
              tone: PgStatusRules.orderTone(status),
            ),
          ],
          if (status == 'rejected') ...[
            if ((order['rejection_remark']?.toString() ?? '').isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(
                'Reason: ${order['rejection_remark']}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
            if ((order['rejected_by_name']?.toString() ?? '').isNotEmpty ||
                order['rejected_at'] != null)
              Text(
                [
                  if ((order['rejected_by_name']?.toString() ?? '').isNotEmpty)
                    order['rejected_by_name'].toString(),
                  if (order['rejected_at'] != null)
                    _formatRejectedAt(order['rejected_at'], dateFormat),
                ].join(' • '),
                style: Theme.of(context).textTheme.bodySmall,
              ),
          ],
        ],
      ),
    );
  }

  String _formatRejectedAt(Object? value, DateFormat dateFormat) {
    final parsed = DateTime.tryParse(value?.toString() ?? '');
    if (parsed == null) return value?.toString() ?? '';
    return dateFormat.format(parsed.toLocal());
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

  Future<void> _reload() async {
    setState(() => _future = _api.getOrder(widget.orderId));
    await _future;
  }

  Future<void> _editOrder(Map<String, dynamic> order) async {
    final result = await context.push<bool>(
      '/manager/orders/${widget.orderId}/edit',
      extra: order,
    );
    if (!mounted || result != true) return;
    await _reload();
  }

  Future<void> _approve() async {
    if (_submitting || !widget.auth.permissions.canApproveOrders) return;
    final confirmed = await confirmAction(
      context,
      title: 'Approve Order',
      message: 'Approve this order?',
    );
    if (!confirmed || !mounted) return;

    setState(() => _submitting = true);
    try {
      await _api.approveOrder(widget.orderId);
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Order approved.')));
      safePop(context, 'approved');
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
      title: 'Reject Order',
      label: 'Rejection Remark (required)',
      submitLabel: 'Reject Order',
      required: true,
    );
    if (remark == null || !mounted) return;

    setState(() => _submitting = true);
    try {
      await _api.rejectOrder(widget.orderId, remark: remark);
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Order rejected.')));
      safePop(context, 'rejected');
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(errorMessage(error))));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  String _dealerLocation(Map<String, dynamic> dealer) {
    return [
      dealer['village'],
      dealer['taluka'],
      dealer['district'],
    ].whereType<Object>().map((part) => part.toString().trim()).where((part) => part.isNotEmpty).join(', ');
  }

  @override
  Widget build(BuildContext context) {
    final dateFormat = DateFormat('dd MMM yyyy, hh:mm a');

    return Scaffold(
      appBar: RoleAppBar(title: 'Order Review', auth: widget.auth),
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
          final items = ((order['line_items'] as List?) ??
                  (order['items'] as List?) ??
                  const [])
              .map((item) => Map<String, dynamic>.from(item as Map))
              .toList();
          final isPending = status == 'pending_approval';
          final canEdit = order['can_edit'] == true && isPending;
          final dealer = order['dealer'] is Map
              ? Map<String, dynamic>.from(order['dealer'] as Map)
              : <String, dynamic>{};
          final dealerLocation = _dealerLocation(dealer);
          final billUrl = order['bill_url']?.toString() ?? '';
          final timeline = ((order['timeline'] as List?) ?? const [])
              .map(
                (step) => OrderTimelineStep.fromApi(
                  Map<String, dynamic>.from(step as Map),
                ),
              )
              .toList();

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              PgDetailHeader(
                title: order['order_no']?.toString() ?? '-',
                subtitle: dealer['firm_name']?.toString() ??
                    order['dealer_name']?.toString() ??
                    '-',
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
                      label: 'Order Number',
                      value: order['order_no']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'Order Date & Time',
                      value: _formatDate(order['created_at'], dateFormat),
                    ),
                    PgInvoiceRow(
                      label: 'Sales Person',
                      value: order['employee_name']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'Employee Code',
                      value: order['employee_code']?.toString() ?? '-',
                    ),
                    if (order['approved_at'] != null) ...[
                      PgInvoiceRow(
                        label: 'Approved By',
                        value: order['approved_by_role']?.toString() ??
                            'Sales Manager',
                      ),
                      PgInvoiceRow(
                        label: 'Name',
                        value: order['approved_by_name']?.toString() ?? '-',
                      ),
                      PgInvoiceRow(
                        label: 'Approved On',
                        value: order['approved_at_label']?.toString() ??
                            _formatDate(order['approved_at'], dateFormat),
                      ),
                    ],
                    if (order['sent_for_bill_at'] != null) ...[
                      PgInvoiceRow(
                        label: 'Sent for Bill By',
                        value:
                            order['sent_for_bill_by_name']?.toString() ?? '-',
                      ),
                      PgInvoiceRow(
                        label: 'Sent On',
                        value: order['sent_for_bill_at_label']?.toString() ??
                            _formatDate(order['sent_for_bill_at'], dateFormat),
                      ),
                    ],
                    if (order['billed_at'] != null) ...[
                      PgInvoiceRow(
                        label: 'Billed By',
                        value: order['billed_by_name']?.toString() ?? '-',
                      ),
                      PgInvoiceRow(
                        label: 'Billed On',
                        value: order['billed_at_label']?.toString() ??
                            _formatDate(order['billed_at'], dateFormat),
                      ),
                    ],
                    PgInvoiceRow(
                      label: 'Dealer Name',
                      value: dealer['firm_name']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'Dealer Code',
                      value: dealer['dealer_code']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'Dealer Location',
                      value: dealerLocation.isEmpty ? '-' : dealerLocation,
                    ),
                    if (order['last_edited_by_name'] != null) ...[
                      PgInvoiceRow(
                        label: 'Last Edited By',
                        value: order['last_edited_by_name'].toString(),
                      ),
                      PgInvoiceRow(
                        label: 'Last Edited At',
                        value: _formatDate(order['last_edited_at'], dateFormat),
                      ),
                    ],
                    if (order['rejected_at'] != null) ...[
                      PgInvoiceRow(
                        label: 'Rejected By',
                        value: order['rejected_by_name']?.toString() ?? '-',
                      ),
                      PgInvoiceRow(
                        label: 'Rejection Remark',
                        value: order['rejection_remark']?.toString() ?? '-',
                      ),
                      PgInvoiceRow(
                        label: 'Rejected At',
                        value: _formatDate(order['rejected_at'], dateFormat),
                      ),
                    ],
                  ],
                ),
              ),
              if (billUrl.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.md),
                FilledButton.icon(
                  onPressed: () => openBillDocument(
                    context,
                    url: billUrl,
                    title: 'Bill PDF',
                  ),
                  icon: const Icon(Icons.picture_as_pdf_outlined),
                  label: const Text('View Bill / Open Bill PDF'),
                ),
              ],
              const SizedBox(height: AppSpacing.md),
              OrderInvoiceProductsCard(
                lines: items
                    .map(
                      (item) => OrderInvoiceLine.fromMap(
                        Map<String, dynamic>.from(item as Map),
                      ),
                    )
                    .toList(growable: false),
                summary: OrderInvoiceSummaryBlock(
                  title: 'Order Totals',
                  subtotal:
                      double.tryParse('${order['subtotal'] ?? 0}') ?? 0,
                  discount:
                      double.tryParse('${order['discount_amount'] ?? 0}') ?? 0,
                  gst: double.tryParse('${order['gst_amount'] ?? 0}') ?? 0,
                  grandTotal:
                      double.tryParse('${order['grand_total'] ?? 0}') ?? 0,
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Order Timeline',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    if (timeline.isEmpty)
                      const Text('Timeline unavailable.')
                    else
                      ...timeline.asMap().entries.map(
                        (entry) => OrderTimelineRow(
                          step: entry.value,
                          isLast: entry.key == timeline.length - 1,
                        ),
                      ),
                  ],
                ),
              ),
              if (isPending &&
                  (widget.auth.permissions.canApproveOrders ||
                      widget.auth.permissions.canRejectOrders)) ...[
                const SizedBox(height: AppSpacing.md),
                if (canEdit && widget.auth.permissions.canApproveOrders)
                  OutlinedButton.icon(
                    onPressed: _submitting ? null : () => _editOrder(order),
                    icon: const Icon(Icons.edit_outlined),
                    label: const Text('Edit Order'),
                  ),
                if (widget.auth.permissions.canApproveOrders) ...[
                  const SizedBox(height: AppSpacing.sm),
                  FilledButton(
                    onPressed: _submitting ? null : _approve,
                    child: const Text('Approve Order'),
                  ),
                ],
                if (widget.auth.permissions.canRejectOrders) ...[
                  const SizedBox(height: AppSpacing.sm),
                  OutlinedButton(
                    onPressed: _submitting ? null : _reject,
                    child: const Text('Reject Order'),
                  ),
                ],
              ],
              const SizedBox(height: AppSpacing.xl),
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
}
