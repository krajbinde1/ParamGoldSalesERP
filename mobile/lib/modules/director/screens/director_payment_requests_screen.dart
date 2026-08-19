import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/utils/secure_document.dart';
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

final _inr = NumberFormat.currency(
  locale: 'en_IN',
  symbol: '₹',
  decimalDigits: 0,
);
final _dateFmt = DateFormat('dd MMM yyyy');
final _timeFmt = DateFormat('hh:mm a');
final _dateTimeFmt = DateFormat('dd MMM yyyy • hh:mm a');

String _vendorPrimary(Map<String, dynamic> m) {
  final v = '${m['vendor_name'] ?? ''}'.trim();
  return v.isEmpty ? 'Vendor Not Available' : v.toUpperCase();
}

String _requestIdOnly(Map<String, dynamic> m) {
  final id = '${m['request_no'] ?? m['id'] ?? ''}'.trim();
  return id.isEmpty ? '—' : id;
}

bool _isPendingStatus(String raw) {
  final s = raw.toLowerCase();
  return s.contains('pending');
}

bool _isRejectedStatus(String raw) {
  return raw.toLowerCase().contains('reject');
}

bool _isPaymentDoneStatus(String raw) {
  return raw.toLowerCase().contains('payment_done') ||
      raw.toLowerCase() == 'payment done';
}

bool _isApprovedStatus(String raw) {
  final s = raw.toLowerCase();
  return s.contains('approved') && !_isPaymentDoneStatus(s);
}

String _badgeLabel(Map<String, dynamic> m) {
  final status = '${m['status'] ?? ''}';
  if (_isPendingStatus(status)) return 'Pending';
  if (_isRejectedStatus(status)) return 'Rejected';
  if (_isPaymentDoneStatus(status)) return 'Payment Done';
  if (_isApprovedStatus(status)) return 'Approved';
  final label = '${m['status_label'] ?? m['current_stage'] ?? ''}'.trim();
  return label.isEmpty ? 'Unknown' : label;
}

PgStatusTone _badgeTone(String status) {
  if (_isPendingStatus(status)) return PgStatusTone.pending;
  if (_isRejectedStatus(status)) return PgStatusTone.rejected;
  if (_isPaymentDoneStatus(status)) return PgStatusTone.info;
  if (_isApprovedStatus(status)) return PgStatusTone.approved;
  return PgStatusTone.neutral;
}

DateTime? _parseDt(dynamic v) {
  if (v == null) return null;
  final s = v.toString().trim();
  if (s.isEmpty) return null;
  return DateTime.tryParse(s);
}

String _fmtDateTime(dynamic v) {
  final d = _parseDt(v);
  if (d == null) return '—';
  return _dateTimeFmt.format(d.toLocal());
}

String _fmtDate(dynamic v) {
  final d = _parseDt(v);
  if (d == null) return '—';
  return _dateFmt.format(d.toLocal());
}

String _fmtTime(dynamic v) {
  final d = _parseDt(v);
  if (d == null) return '—';
  return _timeFmt.format(d.toLocal());
}

double _amountOf(Map<String, dynamic> m) =>
    double.tryParse('${m['amount'] ?? 0}') ?? 0;

String _personText(dynamic v) {
  if (v == null) return '—';
  if (v is Map) {
    final n = '${v['name'] ?? ''}'.trim();
    return n.isEmpty ? '—' : n;
  }
  final s = v.toString().trim();
  return s.isEmpty ? '—' : s;
}

/// Director Payment Approval — Pending / All Requests.
class DirectorPaymentRequestsScreen extends StatefulWidget {
  const DirectorPaymentRequestsScreen({
    super.key,
    required this.auth,
    this.initialFilter = 'pending',
    this.selectAllOnLoad = false,
  });

  final AuthController auth;
  final String initialFilter;
  final bool selectAllOnLoad;

  @override
  State<DirectorPaymentRequestsScreen> createState() =>
      _DirectorPaymentRequestsScreenState();
}

class _DirectorPaymentRequestsScreenState
    extends State<DirectorPaymentRequestsScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs;
  final _searchCtrl = TextEditingController();

  bool _loading = true;
  bool _refreshing = false;
  String? _error;

  List<Map<String, dynamic>> _pending = const [];
  List<Map<String, dynamic>> _all = const [];
  int _apiPendingCount = 0;
  double _apiPendingTotal = 0;

  String _statusFilter = 'all';
  DateTimeRange? _dateRange;
  double? _minAmount;
  double? _maxAmount;

  DirectorApi get _api => DirectorApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  String get _normalizedInitial {
    final f = widget.initialFilter.trim().toLowerCase();
    if (f == 'approved' ||
        f == 'rejected' ||
        f == 'history' ||
        f == 'pending') {
      return f;
    }
    return 'pending';
  }

  @override
  void initState() {
    super.initState();
    final initial = _normalizedInitial;
    _tabs = TabController(
      length: 2,
      vsync: this,
      initialIndex: initial == 'pending' ? 0 : 1,
    );
    if (initial == 'approved' ||
        initial == 'rejected' ||
        initial == 'history') {
      _statusFilter = initial == 'history' ? 'all' : initial;
    }
    _tabs.addListener(() {
      if (!_tabs.indexIsChanging) setState(() {});
    });
    _load(initial: true);
  }

  @override
  void dispose() {
    _tabs.dispose();
    _searchCtrl.dispose();
    super.dispose();
  }

  Future<void> _load({bool initial = false}) async {
    setState(() {
      if (initial) {
        _loading = true;
      } else {
        _refreshing = true;
      }
      _error = null;
    });
    try {
      final results = await Future.wait<_PaymentListResult>([
        _api.listPaymentRequests(status: 'pending'),
        _api.listPaymentRequests(),
      ]);
      if (!mounted) return;
      setState(() {
        _pending = results[0].data;
        _apiPendingCount = results[0].pendingCount;
        _apiPendingTotal = results[0].pendingTotalAmount;
        _all = results[1].data;
        _loading = false;
        _refreshing = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _refreshing = false;
        _error = 'Unable to load payment requests';
      });
    }
  }

  List<Map<String, dynamic>> get _source =>
      _tabs.index == 0 ? _pending : _all;

  List<Map<String, dynamic>> get _filtered {
    final q = _searchCtrl.text.trim().toLowerCase();
    return _source.where((m) {
      final status = '${m['status'] ?? ''}'.toLowerCase();

      if (_tabs.index == 1 && _statusFilter != 'all') {
        if (_statusFilter == 'pending' && !_isPendingStatus(status)) {
          return false;
        }
        if (_statusFilter == 'approved' && !_isApprovedStatus(status)) {
          return false;
        }
        if (_statusFilter == 'rejected' && !_isRejectedStatus(status)) {
          return false;
        }
        if (_statusFilter == 'payment_done' && !_isPaymentDoneStatus(status)) {
          return false;
        }
      }

      final created = _parseDt(m['created_at']);
      if (_dateRange != null && created != null) {
        final start = DateTime(
          _dateRange!.start.year,
          _dateRange!.start.month,
          _dateRange!.start.day,
        );
        final end = DateTime(
          _dateRange!.end.year,
          _dateRange!.end.month,
          _dateRange!.end.day,
          23,
          59,
          59,
        );
        if (created.isBefore(start) || created.isAfter(end)) return false;
      }

      final amount = _amountOf(m);
      if (_minAmount != null && amount < _minAmount!) return false;
      if (_maxAmount != null && amount > _maxAmount!) return false;

      if (q.isEmpty) return true;
      final vendor = '${m['vendor_name'] ?? ''}'.toLowerCase();
      final rid = '${m['request_no'] ?? m['id'] ?? ''}'.toLowerCase();
      final amt = amount.toStringAsFixed(0);
      final remark = '${m['remark'] ?? ''}'.toLowerCase();
      return vendor.contains(q) ||
          rid.contains(q) ||
          amt.contains(q) ||
          remark.contains(q);
    }).toList();
  }

  Map<String, num> get _summary {
    final base = _all.isNotEmpty ? _all : _pending;
    num totalAmt = 0;
    var pending = 0;
    var approved = 0;
    for (final m in base) {
      totalAmt += _amountOf(m);
      final s = '${m['status'] ?? ''}';
      if (_isPendingStatus(s)) pending++;
      if (_isApprovedStatus(s) || _isPaymentDoneStatus(s)) approved++;
    }
    return {
      'total': base.length,
      'amount': totalAmt,
      'pending': _apiPendingCount > 0 ? _apiPendingCount : pending,
      'approved': approved,
      'pending_amount': _apiPendingTotal,
    };
  }

  Future<void> _openDetail(Map<String, dynamic> m) async {
    // Backend primary key — not request_no (e.g. PR-0007).
    final id = int.tryParse('${m['id']}') ?? 0;
    debugPrint(
      'PARAMGOLD_PAYMENT_OPEN id=$id request_no=${m['request_no']}',
    );
    if (id <= 0) return;
    final result = await context.push('/director/payment-requests/$id');
    if (!mounted) return;
    if (result == true ||
        result == 'approved' ||
        result == 'rejected' ||
        result == 'changed') {
      await _load();
    }
  }

  Future<void> _openFilters() async {
    String status = _statusFilter;
    DateTimeRange? range = _dateRange;
    final minCtrl = TextEditingController(
      text: _minAmount?.toStringAsFixed(0) ?? '',
    );
    final maxCtrl = TextEditingController(
      text: _maxAmount?.toStringAsFixed(0) ?? '',
    );

    final applied = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return StatefulBuilder(
          builder: (ctx, setModal) {
            return Padding(
              padding: EdgeInsets.only(
                left: AppSpacing.md,
                right: AppSpacing.md,
                top: AppSpacing.md,
                bottom: MediaQuery.of(ctx).viewInsets.bottom + AppSpacing.md,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Center(
                    child: Container(
                      width: 40,
                      height: 4,
                      decoration: BoxDecoration(
                        color: AppColors.border,
                        borderRadius: BorderRadius.circular(999),
                      ),
                    ),
                  ),
                  const SizedBox(height: AppSpacing.md),
                  Text(
                    'Filter Requests',
                    style: Theme.of(ctx).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                          color: AppColors.textPrimary,
                        ),
                  ),
                  const SizedBox(height: AppSpacing.md),
                  const Text(
                    'Status',
                    style: TextStyle(
                      fontWeight: FontWeight.w700,
                      color: AppColors.textSecondary,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final e in const [
                        ('all', 'All'),
                        ('pending', 'Pending'),
                        ('approved', 'Approved'),
                        ('rejected', 'Rejected'),
                        ('payment_done', 'Payment Done'),
                      ])
                        ChoiceChip(
                          label: Text(e.$2),
                          selected: status == e.$1,
                          selectedColor:
                              AppColors.primary.withValues(alpha: 0.15),
                          labelStyle: TextStyle(
                            color: status == e.$1
                                ? AppColors.primary
                                : AppColors.textSecondary,
                            fontWeight: FontWeight.w700,
                          ),
                          onSelected: (_) => setModal(() => status = e.$1),
                        ),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.md),
                  OutlinedButton.icon(
                    onPressed: () async {
                      final picked = await showDateRangePicker(
                        context: ctx,
                        firstDate: DateTime(2020),
                        lastDate: DateTime.now().add(const Duration(days: 1)),
                        initialDateRange: range,
                      );
                      if (picked != null) setModal(() => range = picked);
                    },
                    icon: const Icon(Icons.date_range_outlined),
                    label: Text(
                      range == null
                          ? 'Date range'
                          : '${_dateFmt.format(range!.start)} – ${_dateFmt.format(range!.end)}',
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: minCtrl,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(
                            labelText: 'Min amount',
                            prefixText: '₹ ',
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: TextField(
                          controller: maxCtrl,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(
                            labelText: 'Max amount',
                            prefixText: '₹ ',
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () {
                            status = 'all';
                            range = null;
                            minCtrl.clear();
                            maxCtrl.clear();
                            setModal(() {});
                          },
                          child: const Text('Clear'),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: FilledButton(
                          onPressed: () => Navigator.pop(ctx, true),
                          child: const Text('Apply'),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            );
          },
        );
      },
    );

    if (applied == true && mounted) {
      setState(() {
        _statusFilter = status;
        _dateRange = range;
        _minAmount = double.tryParse(minCtrl.text.trim());
        _maxAmount = double.tryParse(maxCtrl.text.trim());
        if (_statusFilter != 'all' && _tabs.index == 0) {
          _tabs.animateTo(1);
        }
      });
    }
    minCtrl.dispose();
    maxCtrl.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final employee = widget.auth.session?.employee;
    final name = (employee?.fullName ?? 'Director').trim();
    final first = name.isEmpty ? 'Director' : name.split(' ').first;
    final summary = _summary;
    final items = _filtered;
    final filtersActive = _statusFilter != 'all' ||
        _dateRange != null ||
        _minAmount != null ||
        _maxAmount != null;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        smartBack(context);
      },
      child: Scaffold(
        backgroundColor: const Color(0xFFF5F7FA),
        appBar: RoleAppBar(
          title: 'Payment Approval',
          auth: widget.auth,
          showBack: true,
          onBack: () => smartBack(context),
        ),
        body: Column(
          children: [
            Material(
              color: Colors.white,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
                    child: Text(
                      'Welcome, $first',
                      style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ),
                  TabBar(
                    controller: _tabs,
                    labelColor: AppColors.primary,
                    unselectedLabelColor: AppColors.textSecondary,
                    indicatorColor: AppColors.primary,
                    indicatorWeight: 3,
                    labelStyle: const TextStyle(
                      fontWeight: FontWeight.w800,
                      fontSize: 14,
                    ),
                    unselectedLabelStyle: const TextStyle(
                      fontWeight: FontWeight.w600,
                      fontSize: 14,
                    ),
                    tabs: const [
                      Tab(text: 'Pending'),
                      Tab(text: 'All Requests'),
                    ],
                  ),
                ],
              ),
            ),
            Expanded(
              child: _loading
                  ? const _PaymentListSkeleton()
                  : _error != null
                      ? _ErrorPane(
                          message: _error!,
                          onRetry: () => _load(initial: true),
                        )
                      : RefreshIndicator(
                          onRefresh: () => _load(),
                          color: AppColors.primary,
                          child: CustomScrollView(
                            physics: const AlwaysScrollableScrollPhysics(),
                            slivers: [
                              if (_refreshing)
                                const SliverToBoxAdapter(
                                  child: LinearProgressIndicator(minHeight: 2),
                                ),
                              SliverToBoxAdapter(
                                child: Padding(
                                  padding:
                                      const EdgeInsets.fromLTRB(16, 16, 16, 0),
                                  child: Column(
                                    children: [
                                      _SummaryCard(
                                        totalRequests:
                                            summary['total']!.toInt(),
                                        totalAmount: summary['amount']!,
                                        pending: summary['pending']!.toInt(),
                                        approved: summary['approved']!.toInt(),
                                      ),
                                      const SizedBox(height: 12),
                                      _SearchFilterRow(
                                        controller: _searchCtrl,
                                        filtersActive: filtersActive,
                                        onChanged: (_) => setState(() {}),
                                        onFilter: _openFilters,
                                      ),
                                      const SizedBox(height: 8),
                                    ],
                                  ),
                                ),
                              ),
                              if (items.isEmpty)
                                SliverFillRemaining(
                                  hasScrollBody: false,
                                  child: _EmptyPane(
                                    pendingTab: _tabs.index == 0,
                                  ),
                                )
                              else
                                SliverPadding(
                                  padding:
                                      const EdgeInsets.fromLTRB(16, 8, 16, 24),
                                  sliver: SliverList.separated(
                                    itemCount: items.length,
                                    separatorBuilder: (_, _) =>
                                        const SizedBox(height: 8),
                                    itemBuilder: (context, i) {
                                      final m = items[i];
                                      return _PaymentRequestCard(
                                        data: m,
                                        onOpen: () => _openDetail(m),
                                      );
                                    },
                                  ),
                                ),
                            ],
                          ),
                        ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({
    required this.totalRequests,
    required this.totalAmount,
    required this.pending,
    required this.approved,
  });

  final int totalRequests;
  final num totalAmount;
  final int pending;
  final int approved;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.04),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
        border: Border.all(color: AppColors.border.withValues(alpha: 0.65)),
      ),
      child: Row(
        children: [
          Expanded(
            child: _SummaryMetric(
              icon: Icons.receipt_long_outlined,
              label: 'Total Requests',
              value: '$totalRequests',
            ),
          ),
          _vDivider(),
          Expanded(
            child: _SummaryMetric(
              icon: Icons.currency_rupee,
              label: 'Total Amount',
              value: _inr.format(totalAmount),
            ),
          ),
          _vDivider(),
          Expanded(
            child: _SummaryMetric(
              icon: Icons.hourglass_top_rounded,
              label: 'Pending',
              value: '$pending',
              accent: AppColors.warning,
            ),
          ),
          _vDivider(),
          Expanded(
            child: _SummaryMetric(
              icon: Icons.verified_outlined,
              label: 'Approved',
              value: '$approved',
              accent: AppColors.success,
            ),
          ),
        ],
      ),
    );
  }

  Widget _vDivider() => Container(
        width: 1,
        height: 36,
        margin: const EdgeInsets.symmetric(horizontal: 2),
        color: AppColors.border.withValues(alpha: 0.7),
      );
}

class _SummaryMetric extends StatelessWidget {
  const _SummaryMetric({
    required this.icon,
    required this.label,
    required this.value,
    this.accent,
  });

  final IconData icon;
  final String label;
  final String value;
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    final c = accent ?? AppColors.primary;
    return Column(
      children: [
        Icon(icon, size: 18, color: c),
        const SizedBox(height: 6),
        Text(
          label,
          textAlign: TextAlign.center,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(
            fontSize: 10,
            fontWeight: FontWeight.w600,
            color: AppColors.textSecondary,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          value,
          textAlign: TextAlign.center,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w800,
            color: accent ?? AppColors.textPrimary,
          ),
        ),
      ],
    );
  }
}

class _SearchFilterRow extends StatelessWidget {
  const _SearchFilterRow({
    required this.controller,
    required this.filtersActive,
    required this.onChanged,
    required this.onFilter,
  });

  final TextEditingController controller;
  final bool filtersActive;
  final ValueChanged<String> onChanged;
  final VoidCallback onFilter;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 42,
      child: Row(
        children: [
          Expanded(
            child: TextField(
              controller: controller,
              onChanged: onChanged,
              textInputAction: TextInputAction.search,
              style: const TextStyle(fontSize: 13),
              decoration: InputDecoration(
                isDense: true,
                hintText: 'Search by Vendor Name, Request ID, Amount...',
                hintStyle: TextStyle(
                  fontSize: 12.5,
                  color: AppColors.textSecondary.withValues(alpha: 0.85),
                ),
                prefixIcon: const Icon(
                  Icons.search,
                  size: 20,
                  color: AppColors.textSecondary,
                ),
                prefixIconConstraints: const BoxConstraints(
                  minWidth: 40,
                  minHeight: 40,
                ),
                filled: true,
                fillColor: Colors.white,
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 10,
                ),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: BorderSide(
                    color: AppColors.border.withValues(alpha: 0.8),
                  ),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: BorderSide(
                    color: AppColors.border.withValues(alpha: 0.8),
                  ),
                ),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: const BorderSide(
                    color: AppColors.primary,
                    width: 1.4,
                  ),
                ),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Material(
            color: filtersActive
                ? AppColors.primary.withValues(alpha: 0.12)
                : Colors.white,
            borderRadius: BorderRadius.circular(10),
            child: InkWell(
              onTap: onFilter,
              borderRadius: BorderRadius.circular(10),
              child: Container(
                width: 42,
                height: 42,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(
                    color: filtersActive
                        ? AppColors.primary
                        : AppColors.border.withValues(alpha: 0.8),
                  ),
                ),
                child: Icon(
                  Icons.tune_rounded,
                  size: 20,
                  color: filtersActive
                      ? AppColors.primary
                      : AppColors.textSecondary,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _PaymentRequestCard extends StatelessWidget {
  const _PaymentRequestCard({
    required this.data,
    required this.onOpen,
  });

  final Map<String, dynamic> data;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final vendorRaw = '${data['vendor_name'] ?? ''}'.trim();
    final vendor =
        vendorRaw.isEmpty ? 'Vendor Not Available' : vendorRaw;
    final status = '${data['status'] ?? ''}';
    final amount = _amountOf(data);
    final remark = '${data['remark'] ?? ''}'.trim();
    final requestedBy = _personText(data['created_by']);
    final created = _fmtDateTime(data['created_at']);
    final requestId = _requestIdOnly(data);

    return Material(
      color: Colors.white,
      elevation: 0,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onOpen,
        borderRadius: BorderRadius.circular(12),
        child: Ink(
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AppColors.border.withValues(alpha: 0.65)),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.05),
                blurRadius: 8,
                offset: const Offset(0, 2),
              ),
            ],
          ),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            requestId,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontSize: 15,
                              fontWeight: FontWeight.w800,
                              height: 1.15,
                              color: AppColors.textPrimary,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            vendor,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                              height: 1.2,
                              color: AppColors.textPrimary,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            created,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w500,
                              color: AppColors.textSecondary,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    PgStatusBadge(
                      label: _badgeLabel(data),
                      tone: _badgeTone(status),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Requested by:',
                            style: TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.w500,
                              color: AppColors.textSecondary.withValues(
                                alpha: 0.95,
                              ),
                            ),
                          ),
                          const SizedBox(height: 1),
                          Text(
                            requestedBy,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.w700,
                              color: AppColors.textPrimary,
                            ),
                          ),
                          if (remark.isNotEmpty) ...[
                            const SizedBox(height: 4),
                            Text(
                              'Remark: $remark',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w500,
                                color: AppColors.textSecondary,
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                    const SizedBox(width: 10),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          _inr.format(amount),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.w800,
                            height: 1.1,
                            color: AppColors.textPrimary,
                          ),
                        ),
                        const SizedBox(height: 8),
                        FilledButton(
                          onPressed: onOpen,
                          style: FilledButton.styleFrom(
                            backgroundColor: AppColors.primary,
                            foregroundColor: Colors.white,
                            elevation: 0,
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 8,
                            ),
                            minimumSize: const Size(0, 34),
                            tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                            visualDensity: VisualDensity.compact,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(8),
                            ),
                          ),
                          child: const Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                'Review & Approve',
                                style: TextStyle(
                                  fontWeight: FontWeight.w700,
                                  fontSize: 12.5,
                                  color: Colors.white,
                                ),
                              ),
                              SizedBox(width: 2),
                              Icon(
                                Icons.chevron_right,
                                size: 16,
                                color: Colors.white,
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _EmptyPane extends StatelessWidget {
  const _EmptyPane({required this.pendingTab});

  final bool pendingTab;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              pendingTab ? Icons.verified_outlined : Icons.inbox_outlined,
              size: 48,
              color: AppColors.primary.withValues(alpha: 0.45),
            ),
            const SizedBox(height: 16),
            Text(
              pendingTab
                  ? 'No Pending Payment Requests'
                  : 'No Payment Requests Found',
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.w800,
                color: AppColors.textPrimary,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              pendingTab
                  ? 'All payment approvals are up to date.'
                  : 'Try adjusting search or filters.',
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 13,
                color: AppColors.textSecondary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ErrorPane extends StatelessWidget {
  const _ErrorPane({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.cloud_off_outlined, size: 44, color: AppColors.error),
            const SizedBox(height: 12),
            Text(
              message,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh),
              label: const Text('Retry'),
            ),
          ],
        ),
      ),
    );
  }
}

class _PaymentListSkeleton extends StatelessWidget {
  const _PaymentListSkeleton();

  @override
  Widget build(BuildContext context) {
    Widget box({required double height, double radius = 16}) {
      return Container(
        height: height,
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(radius),
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        box(height: 88),
        const SizedBox(height: 12),
        box(height: 48, radius: 12),
        const SizedBox(height: 16),
        for (var i = 0; i < 4; i++) ...[
          box(height: 148),
          const SizedBox(height: 12),
        ],
      ],
    );
  }
}

/// Payment Request Review — approve / reject / timeline.
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
  bool _loading = true;
  bool _acting = false;
  bool _changed = false;
  String? _error;
  Map<String, dynamic>? _data;

  DirectorApi get _api => DirectorApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (widget.requestId <= 0) {
      setState(() {
        _loading = false;
        _error = 'Unable to load payment request';
      });
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      debugPrint('PARAMGOLD_PAYMENT_DETAIL_UI id=${widget.requestId}');
      final data = await _api.getPaymentRequest(widget.requestId);
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
      });
    } catch (error, stack) {
      debugPrint('PARAMGOLD_PAYMENT_DETAIL_UI_FAIL id=${widget.requestId} $error');
      debugPrint('$stack');
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = 'Unable to load payment request';
      });
    }
  }

  void _popWithResult() {
    if (!context.mounted) return;
    if (context.canPop()) {
      context.pop(_changed ? 'changed' : null);
      return;
    }
    smartBack(context);
  }

  int? get _myUserId => widget.auth.session?.user.id;

  bool get _approvedByMe {
    final myId = _myUserId;
    if (myId == null || _data == null) return false;
    final status = '${_data!['status'] ?? ''}';
    if (_isRejectedStatus(status)) return false;
    final first = int.tryParse('${_data!['first_approved_by'] ?? ''}');
    final second = int.tryParse('${_data!['second_approved_by'] ?? ''}');
    return first == myId || second == myId;
  }

  String? get _approvedByMeAt {
    if (_data == null) return null;
    final myId = _myUserId;
    if (myId == null) return null;
    final first = int.tryParse('${_data!['first_approved_by'] ?? ''}');
    final second = int.tryParse('${_data!['second_approved_by'] ?? ''}');
    if (first == myId) {
      return _fmtDateTime(_data!['first_approved_at']);
    }
    if (second == myId) {
      return _fmtDateTime(_data!['second_approved_at']);
    }
    return null;
  }

  Future<void> _approve() async {
    if (_acting || _data == null) return;
    final vendor = _vendorPrimary(_data!);
    final amount = _inr.format(_amountOf(_data!));

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Approve Payment Request?'),
        content: Text('Vendor: $vendor\nAmount: $amount'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Approve'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;

    setState(() => _acting = true);
    try {
      await _api.approvePaymentRequest(widget.requestId);
      if (!mounted) return;
      _changed = true;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Payment request approved')),
      );
      await _load();
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Unable to approve payment request')),
      );
    } finally {
      if (mounted) setState(() => _acting = false);
    }
  }

  Future<void> _reject() async {
    if (_acting || _data == null) return;

    final remark = await promptRemarkDialog(
      context,
      title: 'Reject Payment Request',
      label: 'Rejection Remark',
      submitLabel: 'Reject Request',
      required: true,
    );
    if (remark == null || remark.trim().isEmpty || !mounted) return;

    setState(() => _acting = true);
    try {
      await _api.rejectPaymentRequest(
        widget.requestId,
        remark: remark.trim(),
      );
      if (!mounted) return;
      _changed = true;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Payment request rejected')),
      );
      await _load();
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Unable to reject payment request')),
      );
    } finally {
      if (mounted) setState(() => _acting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    final canApprove = d != null && d['can_approve'] == true;
    final canReject = d != null && d['can_reject'] == true;
    final status = '${d?['status'] ?? ''}';
    final vendor = d == null ? '' : _vendorPrimary(d);
    final amount = d == null ? 0.0 : _amountOf(d);
    final timeline = (d?['timeline'] as List?)
            ?.map((e) => Map<String, dynamic>.from(e as Map))
            .toList() ??
        const <Map<String, dynamic>>[];
    final proof = '${d?['payment_proof_url'] ?? ''}'.trim();
    final supportingDocs = (d?['supporting_documents'] as List?)
            ?.map((e) => Map<String, dynamic>.from(e as Map))
            .toList() ??
        const <Map<String, dynamic>>[];
    final waitingPrevious = d != null &&
        _isPendingStatus(status) &&
        !canApprove &&
        !_approvedByMe;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        _popWithResult();
      },
      child: Scaffold(
        backgroundColor: const Color(0xFFF5F7FA),
        appBar: RoleAppBar(
          title: 'Payment Request',
          auth: widget.auth,
          showBack: true,
          onBack: _popWithResult,
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? _ErrorPane(message: _error!, onRetry: _load)
                : d == null
                    ? const SizedBox.shrink()
                    : Column(
                        children: [
                          Expanded(
                            child: ListView(
                              padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
                              children: [
                                _ReviewHero(
                                  vendor: vendor,
                                  amount: amount,
                                  status: status,
                                  badgeLabel: _isPendingStatus(status)
                                      ? 'Pending Approval'
                                      : _badgeLabel(d),
                                ),
                                const SizedBox(height: 16),
                                _SectionCard(
                                  title: 'Payment Details',
                                  children: [
                                    _DetailRow('Vendor Name', vendor),
                                    _DetailRow(
                                      'Vendor Mobile',
                                      '${d['vendor_mobile'] ?? '—'}',
                                    ),
                                    _DetailRow('Amount', _inr.format(amount)),
                                    _DetailRow(
                                      'Remark/Purpose',
                                      () {
                                        final r =
                                            '${d['remark'] ?? ''}'.trim();
                                        return r.isEmpty ? '—' : r;
                                      }(),
                                    ),
                                    _DetailRow(
                                      'Request Date',
                                      _fmtDate(d['created_at']),
                                    ),
                                    _DetailRow(
                                      'Request Time',
                                      _fmtTime(d['created_at']),
                                    ),
                                    _DetailRow(
                                      'Requested By',
                                      _personText(d['created_by']),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 12),
                                _SectionCard(
                                  title: 'Reference',
                                  children: [
                                    _DetailRow(
                                      'Request ID',
                                      _requestIdOnly(d),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 12),
                                _SectionCard(
                                  title: supportingDocs.isEmpty
                                      ? 'Supporting Documents'
                                      : 'Supporting Documents (${supportingDocs.length})',
                                  children: [
                                    if (supportingDocs.isEmpty)
                                      const Text(
                                        'No Supporting Documents',
                                        style: TextStyle(
                                          color: AppColors.textSecondary,
                                          fontWeight: FontWeight.w600,
                                        ),
                                      )
                                    else
                                      for (final doc in supportingDocs)
                                        _SupportingDocumentTile(
                                          document: doc,
                                          onView: () => openSecureDocument(
                                            context,
                                            dio: ApiClient(
                                              SessionStore(),
                                              onUnauthorized:
                                                  widget.auth.sessionExpired,
                                            ).dio,
                                            title:
                                                '${doc['file_name'] ?? 'Document'}',
                                            mimeType:
                                                '${doc['mime_type'] ?? ''}',
                                            viewPath:
                                                '${doc['view_path'] ?? ''}',
                                            viewUrl:
                                                '${doc['view_url'] ?? ''}',
                                            documentId: int.tryParse(
                                              '${doc['id'] ?? ''}',
                                            ),
                                            paymentRequestId: widget.requestId,
                                          ),
                                        ),
                                  ],
                                ),
                                if (proof.isNotEmpty) ...[
                                  const SizedBox(height: 12),
                                  _SectionCard(
                                    title: 'Payment Proof',
                                    children: [
                                      SelectableText(
                                        proof,
                                        style: const TextStyle(
                                          fontSize: 13,
                                          color: AppColors.primary,
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                    ],
                                  ),
                                ],
                                if ((int.tryParse('${d['reminder_count'] ?? 0}') ?? 0) > 0) ...[
                                  const SizedBox(height: 12),
                                  _SectionCard(
                                    title: 'Reminders',
                                    children: [
                                      _DetailRow(
                                        'Reminders Sent',
                                        '${d['reminder_count'] ?? 0}',
                                      ),
                                      _DetailRow(
                                        'Last Reminder',
                                        _fmtDateTime(
                                          d['last_reminder_sent_at'] ??
                                              d['last_reminded_at'],
                                        ),
                                      ),
                                      _DetailRow(
                                        'Sent by',
                                        _personText(
                                          d['last_reminder_sent_by'] ??
                                              d['last_reminded_by'],
                                        ),
                                      ),
                                    ],
                                  ),
                                ],
                                const SizedBox(height: 12),
                                _SectionCard(
                                  title: 'Approval Workflow',
                                  children: [
                                    if (timeline.isEmpty)
                                      const Text(
                                        'No approval history available.',
                                        style: TextStyle(
                                          color: AppColors.textSecondary,
                                        ),
                                      )
                                    else
                                      _ApprovalTimeline(steps: timeline),
                                  ],
                                ),
                                if (_approvedByMe && !canApprove) ...[
                                  const SizedBox(height: 16),
                                  _ApprovedByYouBanner(at: _approvedByMeAt),
                                ],
                                if (waitingPrevious) ...[
                                  const SizedBox(height: 12),
                                  Container(
                                    width: double.infinity,
                                    padding: const EdgeInsets.all(14),
                                    decoration: BoxDecoration(
                                      color: const Color(0xFFFFF8E8),
                                      borderRadius: BorderRadius.circular(12),
                                      border: Border.all(
                                        color: AppColors.warning
                                            .withValues(alpha: 0.35),
                                      ),
                                    ),
                                    child: const Text(
                                      'Waiting for Previous Approval',
                                      textAlign: TextAlign.center,
                                      style: TextStyle(
                                        fontWeight: FontWeight.w700,
                                        color: Color(0xFF92400E),
                                      ),
                                    ),
                                  ),
                                ],
                              ],
                            ),
                          ),
                          if (canApprove || canReject)
                            SafeArea(
                              top: false,
                              child: Container(
                                padding:
                                    const EdgeInsets.fromLTRB(16, 12, 16, 12),
                                decoration: BoxDecoration(
                                  color: Colors.white,
                                  boxShadow: [
                                    BoxShadow(
                                      color:
                                          Colors.black.withValues(alpha: 0.06),
                                      blurRadius: 10,
                                      offset: const Offset(0, -2),
                                    ),
                                  ],
                                ),
                                child: Row(
                                  children: [
                                    if (canReject)
                                      Expanded(
                                        child: OutlinedButton(
                                          onPressed:
                                              _acting ? null : _reject,
                                          style: OutlinedButton.styleFrom(
                                            foregroundColor: AppColors.error,
                                            side: const BorderSide(
                                              color: AppColors.error,
                                            ),
                                            padding: const EdgeInsets.symmetric(
                                              vertical: 14,
                                            ),
                                          ),
                                          child: const Text(
                                            'Reject',
                                            style: TextStyle(
                                              fontWeight: FontWeight.w800,
                                            ),
                                          ),
                                        ),
                                      ),
                                    if (canReject && canApprove)
                                      const SizedBox(width: 12),
                                    if (canApprove)
                                      Expanded(
                                        flex: 2,
                                        child: FilledButton(
                                          onPressed:
                                              _acting ? null : _approve,
                                          style: FilledButton.styleFrom(
                                            padding: const EdgeInsets.symmetric(
                                              vertical: 14,
                                            ),
                                          ),
                                          child: _acting
                                              ? const SizedBox(
                                                  width: 20,
                                                  height: 20,
                                                  child:
                                                      CircularProgressIndicator(
                                                    strokeWidth: 2,
                                                    color: Colors.white,
                                                  ),
                                                )
                                              : const Text(
                                                  'Approve Payment',
                                                  style: TextStyle(
                                                    fontWeight: FontWeight.w800,
                                                  ),
                                                ),
                                        ),
                                      ),
                                  ],
                                ),
                              ),
                            ),
                        ],
                      ),
      ),
    );
  }
}

class _ReviewHero extends StatelessWidget {
  const _ReviewHero({
    required this.vendor,
    required this.amount,
    required this.status,
    required this.badgeLabel,
  });

  final String vendor;
  final num amount;
  final String status;
  final String badgeLabel;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppSpacing.radiusMd),
        border: Border.all(color: AppColors.border.withValues(alpha: 0.7)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.04),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            vendor,
            style: const TextStyle(
              fontSize: 22,
              fontWeight: FontWeight.w900,
              color: AppColors.textPrimary,
              letterSpacing: 0.3,
            ),
          ),
          const SizedBox(height: 10),
          Text(
            _inr.format(amount),
            style: const TextStyle(
              fontSize: 28,
              fontWeight: FontWeight.w900,
              color: AppColors.primary,
            ),
          ),
          const SizedBox(height: 12),
          PgStatusBadge(
            label: badgeLabel,
            tone: _badgeTone(status),
          ),
        ],
      ),
    );
  }
}

class _SectionCard extends StatelessWidget {
  const _SectionCard({required this.title, required this.children});

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppSpacing.radiusMd),
        border: Border.all(color: AppColors.border.withValues(alpha: 0.7)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary,
            ),
          ),
          const SizedBox(height: 12),
          ...children,
        ],
      ),
    );
  }
}

class _SupportingDocumentTile extends StatelessWidget {
  const _SupportingDocumentTile({
    required this.document,
    required this.onView,
  });

  final Map<String, dynamic> document;
  final VoidCallback onView;

  @override
  Widget build(BuildContext context) {
    final name = '${document['file_name'] ?? 'Document'}'.trim();
    final mime = '${document['mime_type'] ?? ''}';
    final typeLabel = documentTypeLabel(fileName: name, mimeType: mime);
    final sizeLabel = '${document['file_size_label'] ?? ''}'.trim().isNotEmpty
        ? '${document['file_size_label']}'
        : formatDocumentBytes(int.tryParse('${document['file_size'] ?? 0}'));
    final meta = [
      if (sizeLabel.trim().isNotEmpty && sizeLabel != '0 B') sizeLabel,
      typeLabel,
    ].join(' · ');

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            documentIconFor(fileName: name, mimeType: mime),
            color: documentIconColorFor(fileName: name, mimeType: mime),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name.isEmpty ? 'Document' : name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                if (meta.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    meta,
                    style: const TextStyle(
                      fontSize: 12,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ],
              ],
            ),
          ),
          TextButton(
            onPressed: onView,
            style: TextButton.styleFrom(
              foregroundColor: AppColors.primary,
              padding: const EdgeInsets.symmetric(horizontal: 8),
              minimumSize: Size.zero,
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
            ),
            child: const Text(
              'View',
              style: TextStyle(fontWeight: FontWeight.w800),
            ),
          ),
        ],
      ),
    );
  }
}

class _DetailRow extends StatelessWidget {
  const _DetailRow(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(
              label,
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: AppColors.textSecondary,
              ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ApprovalTimeline extends StatelessWidget {
  const _ApprovalTimeline({required this.steps});

  final List<Map<String, dynamic>> steps;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        for (var i = 0; i < steps.length; i++) ...[
          _TimelineStep(step: steps[i]),
          if (i < steps.length - 1)
            Padding(
              padding: const EdgeInsets.only(left: 11, top: 2, bottom: 2),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Container(
                  width: 2,
                  height: 18,
                  color: AppColors.border,
                ),
              ),
            ),
        ],
      ],
    );
  }
}

class _TimelineStep extends StatelessWidget {
  const _TimelineStep({required this.step});

  final Map<String, dynamic> step;

  @override
  Widget build(BuildContext context) {
    final title = '${step['label'] ?? step['title'] ?? 'Step'}';
    final actor = '${step['actor'] ?? ''}'.trim();
    final role = '${step['actor_role'] ?? ''}'.trim();
    final at = '${step['at'] ?? ''}'.trim();
    final remark = '${step['remark'] ?? ''}'.trim();
    final rejected = step['is_rejection'] == true;
    final completed = step['completed'] == true;
    final current = step['is_current'] == true;
    final pending = step['pending'] == true || (!completed && !rejected);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 24,
          height: 24,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: rejected
                ? AppColors.error.withValues(alpha: 0.12)
                : completed
                    ? AppColors.success.withValues(alpha: 0.15)
                    : AppColors.border.withValues(alpha: 0.5),
          ),
          child: Icon(
            rejected
                ? Icons.close
                : completed
                    ? Icons.check
                    : Icons.circle,
            size: completed || rejected ? 14 : 8,
            color: rejected
                ? AppColors.error
                : completed
                    ? AppColors.success
                    : AppColors.textSecondary,
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: TextStyle(
                  fontWeight: FontWeight.w800,
                  fontSize: 13,
                  color: rejected ? AppColors.error : AppColors.textPrimary,
                ),
              ),
              if (actor.isNotEmpty || role.isNotEmpty)
                Text(
                  [actor, role].where((e) => e.isNotEmpty).join(' · '),
                  style: const TextStyle(
                    fontSize: 12,
                    color: AppColors.textSecondary,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              Text(
                rejected
                    ? 'Rejected'
                    : completed
                        ? 'Approved'
                        : current || pending
                            ? 'Pending'
                            : 'Pending',
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: rejected
                      ? AppColors.error
                      : completed
                          ? AppColors.success
                          : AppColors.warning,
                ),
              ),
              if (at.isNotEmpty)
                Text(
                  at,
                  style: const TextStyle(
                    fontSize: 11,
                    color: AppColors.textSecondary,
                  ),
                ),
              if (remark.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    remark,
                    style: TextStyle(
                      fontSize: 12,
                      color: rejected
                          ? AppColors.error
                          : AppColors.textSecondary,
                    ),
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

class _ApprovedByYouBanner extends StatelessWidget {
  const _ApprovedByYouBanner({this.at});

  final String? at;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.success.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.success.withValues(alpha: 0.35)),
      ),
      child: Row(
        children: [
          const Icon(Icons.check_circle, color: AppColors.success),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Approved by You',
                  style: TextStyle(
                    fontWeight: FontWeight.w800,
                    color: AppColors.success,
                  ),
                ),
                if (at != null && at != '—')
                  Text(
                    at!,
                    style: const TextStyle(
                      fontSize: 12,
                      color: AppColors.textSecondary,
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
