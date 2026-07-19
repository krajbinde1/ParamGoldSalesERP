import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/production_api.dart';

class ProductionOrderDetailScreen extends StatefulWidget {
  const ProductionOrderDetailScreen({
    super.key,
    required this.auth,
    required this.orderId,
  });

  final AuthController auth;
  final int orderId;

  @override
  State<ProductionOrderDetailScreen> createState() =>
      _ProductionOrderDetailScreenState();
}

class _ProductionOrderDetailScreenState
    extends State<ProductionOrderDetailScreen> {
  late Future<Map<String, dynamic>> _future;
  Map<String, dynamic>? _order;
  String? _transportType;
  final _transportAmountController = TextEditingController();
  String? _amountError;
  bool _submitting = false;
  bool _calculating = false;
  Timer? _debounce;

  ProductionApi get _api => ProductionApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _loadOrder();
    _transportAmountController.addListener(_onTransportAmountChanged);
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _transportAmountController.removeListener(_onTransportAmountChanged);
    _transportAmountController.dispose();
    super.dispose();
  }

  Future<Map<String, dynamic>> _loadOrder() async {
    final order = await _api.getOrder(widget.orderId);
    _order = order;
    if (_transportType == null && order['transport_type'] != null) {
      _transportType = order['transport_type']?.toString();
    }
    if (order['transport_amount'] != null &&
        _transportAmountController.text.isEmpty) {
      final amount = double.tryParse('${order['transport_amount']}') ?? 0;
      if (amount > 0) {
        _transportAmountController.text = amount.toStringAsFixed(2);
      }
    }
    return order;
  }

  Map<String, dynamic> get _calculation {
    final calc = _order?['calculation'];
    if (calc is Map) {
      return Map<String, dynamic>.from(calc);
    }
    return const {};
  }

  bool get _isApproved =>
      _order?['status']?.toString() == 'approved' &&
      _order?['can_dispatch'] == true;

  bool get _isDispatched => _order?['status']?.toString() == 'dispatched';

  bool get _canDispatch =>
      _isApproved && widget.auth.permissions.canDispatchOrders;

  void _onTransportAmountChanged() {
    if (!mounted || !_canDispatch || _transportType == null) return;
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 450), _refreshCalculation);
    setState(() => _validateAmount());
  }

  void _validateAmount() {
    final text = _transportAmountController.text.trim();
    if (text.isEmpty) {
      _amountError = 'Transport amount is required.';
      return;
    }
    final amount = double.tryParse(text);
    if (amount == null) {
      _amountError = 'Enter a valid numeric amount.';
      return;
    }
    if (amount < 0) {
      _amountError = 'Transport amount cannot be negative.';
      return;
    }
    final subtotal =
        double.tryParse('${_calculation['subtotal_before_transport'] ?? 0}') ??
        0;
    if (amount > subtotal) {
      _amountError = 'Transport amount cannot exceed subtotal before transport.';
      return;
    }
    _amountError = null;
  }

  Future<void> _refreshCalculation() async {
    if (!_canDispatch || _transportType == null) return;
    final text = _transportAmountController.text.trim();
    if (text.isEmpty) return;

    final amount = double.tryParse(text);
    if (amount == null || amount < 0) return;

    setState(() => _calculating = true);
    try {
      final updated = await _api.calculateDispatch(
        widget.orderId,
        transportType: _transportType!,
        transportAmount: amount,
      );
      if (!mounted) return;
      setState(() {
        _order = updated;
        _validateAmount();
      });
    } catch (error) {
      if (!mounted) return;
      final message = errorMessage(error);
      setState(() => _amountError = message);
    } finally {
      if (mounted) setState(() => _calculating = false);
    }
  }

  Future<void> _onTransportTypeChanged(String? value) async {
    if (value == null) return;
    setState(() => _transportType = value);
    await _refreshCalculation();
  }

  bool get _canSubmitDispatch {
    if (!_canDispatch || _submitting || _calculating) return false;
    if (_transportType == null) return false;
    _validateAmount();
    return _amountError == null &&
        _transportAmountController.text.trim().isNotEmpty;
  }

  Future<void> _dispatch() async {
    if (!_canSubmitDispatch || _submitting) return;

    final calc = _calculation;
    final transportLabel = _transportType == 'outside_transport'
        ? 'Outside Transport'
        : 'Company Transport';
    final amount =
        double.tryParse(_transportAmountController.text.trim()) ?? 0;
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 2,
    );

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Confirm Dispatch'),
        content: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              _ConfirmRow('Order', _order?['order_no']?.toString() ?? '-'),
              _ConfirmRow('Transport Type', transportLabel),
              _ConfirmRow('Transport Amount', currency.format(amount)),
              _ConfirmRow(
                'Taxable After Transport',
                currency.format(
                  double.tryParse(
                        '${calc['taxable_amount_after_transport'] ?? 0}',
                      ) ??
                      0,
                ),
              ),
              _ConfirmRow(
                'Total GST',
                currency.format(
                  double.tryParse('${calc['total_gst'] ?? 0}') ?? 0,
                ),
              ),
              _ConfirmRow(
                'Grand Total',
                currency.format(
                  double.tryParse('${calc['grand_total'] ?? 0}') ?? 0,
                ),
                emphasized: true,
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Confirm Dispatch'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    setState(() => _submitting = true);
    try {
      final updated = await _api.dispatchOrder(
        widget.orderId,
        transportType: _transportType!,
        transportAmount: amount,
      );
      if (!mounted) return;
      setState(() {
        _order = updated;
        _future = Future.value(updated);
      });
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Order dispatched.')));
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
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 2,
    );
    final dateFormat = DateFormat('dd MMM yyyy, hh:mm a');

    return Scaffold(
      appBar: RoleAppBar(title: 'Order Details', auth: widget.auth),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              _order == null) {
            return const PgLoadingState();
          }
          if (snapshot.hasError && _order == null) {
            return PgErrorState(message: errorMessage(snapshot.error));
          }

          final order = _order ?? snapshot.data!;
          final calc = _calculation;
          final items = (order['items'] as List?) ?? const [];
          final dealer = order['dealer'] as Map?;
          final status = order['status']?.toString() ?? '';

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              PgDetailHeader(
                title: order['order_no']?.toString() ?? '-',
                subtitle: dealer?['firm_name']?.toString() ?? '-',
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
                      label: 'Order Date',
                      value: _formatDate(order['order_date'], dateFormat),
                    ),
                    PgInvoiceRow(
                      label: 'Employee',
                      value: order['employee_name']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'Dealer Mobile',
                      value: dealer?['mobile']?.toString() ?? '-',
                    ),
                    PgInvoiceRow(
                      label: 'Delivery Address',
                      value: dealer?['address']?.toString() ?? '-',
                    ),
                      if (order['approved_at'] != null) ...[
                        PgInvoiceRow(
                          label: 'Approval Date',
                          value: _formatDate(order['approved_at'], dateFormat),
                        ),
                        PgInvoiceRow(
                          label: 'Approved By',
                          value: order['approved_by_name']?.toString() ?? '-',
                        ),
                      ],
                      if (order['approval_remark'] != null &&
                          order['approval_remark'].toString().isNotEmpty)
                        PgInvoiceRow(
                          label: 'Approval Remark',
                          value: order['approval_remark'].toString(),
                        ),
                      if (_isDispatched) ...[
                        PgInvoiceRow(
                          label: 'Dispatched At',
                          value: _formatDate(order['dispatched_at'], dateFormat),
                        ),
                        PgInvoiceRow(
                          label: 'Dispatched By',
                          value: order['dispatched_by_name']?.toString() ?? '-',
                        ),
                        if (order['dispatch_remark'] != null &&
                            order['dispatch_remark'].toString().isNotEmpty)
                          PgInvoiceRow(
                            label: 'Dispatch Remark',
                            value: order['dispatch_remark'].toString(),
                          ),
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
                      'Products',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                      ...items.map((raw) {
                        final item = Map<String, dynamic>.from(raw as Map);
                        return Padding(
                          padding: const EdgeInsets.only(bottom: 14),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                item['product_name']?.toString() ?? '-',
                                style: Theme.of(context).textTheme.titleSmall,
                              ),
                              if (item['product_code'] != null)
                                Text(
                                  item['product_code'].toString(),
                                  style: Theme.of(context).textTheme.bodySmall,
                                ),
                              const SizedBox(height: 8),
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
                                label: 'Discount %',
                                value:
                                    '${_formatPercent(item['discount_percentage'])}%',
                              ),
                              PgInvoiceRow(
                                label: 'Discount Amount',
                                value: currency.format(
                                  double.tryParse(
                                        '${item['discount_amount'] ?? 0}',
                                      ) ??
                                      0,
                                ),
                              ),
                              PgInvoiceRow(
                                label: 'Taxable Amount',
                                value: currency.format(
                                  double.tryParse(
                                        '${item['taxable_after_transport'] ?? item['taxable_before_transport'] ?? 0}',
                                      ) ??
                                      0,
                                ),
                              ),
                              PgInvoiceRow(
                                label: 'GST %',
                                value:
                                    '${_formatPercent(item['gst_percentage'])}%',
                              ),
                              PgInvoiceRow(
                                label: 'GST Amount',
                                value: currency.format(
                                  double.tryParse(
                                        '${item['gst_amount'] ?? 0}',
                                      ) ??
                                      0,
                                ),
                              ),
                              PgInvoiceRow(
                                label: 'Line Total',
                                value: currency.format(
                                  double.tryParse(
                                        '${item['line_total'] ?? 0}',
                                      ) ??
                                      0,
                                ),
                                emphasize: true,
                              ),
                              if (item != items.last) const Divider(),
                            ],
                          ),
                        );
                      }),
                    ],
                  ),
                ),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Order Summary',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    PgInvoiceRow(
                      label: 'Gross Product Amount',
                      value: currency.format(
                        double.tryParse(
                              '${calc['gross_amount'] ?? order['gross_amount'] ?? 0}',
                            ) ??
                            0,
                      ),
                    ),
                    PgInvoiceRow(
                      label: 'Total Discount',
                      value: currency.format(
                        double.tryParse(
                              '${calc['total_discount'] ?? order['total_discount'] ?? 0}',
                            ) ??
                            0,
                      ),
                    ),
                    PgInvoiceRow(
                      label: 'Subtotal Before Transport',
                      value: currency.format(
                        double.tryParse(
                              '${calc['subtotal_before_transport'] ?? 0}',
                            ) ??
                            0,
                      ),
                      emphasize: true,
                    ),
                    if (_transportType != null ||
                        order['transport_type'] != null) ...[
                      PgInvoiceRow(
                        label: 'Transport Type',
                        value: order['transport_type_label']?.toString() ??
                            (_transportType == 'outside_transport'
                                ? 'Outside Transport'
                                : 'Company Transport'),
                      ),
                      PgInvoiceRow(
                        label: 'Transport Amount',
                        value:
                            '- ${currency.format(double.tryParse('${calc['transport_amount'] ?? order['transport_amount'] ?? 0}') ?? 0)}',
                      ),
                    ],
                    PgInvoiceRow(
                      label: 'Taxable After Transport',
                      value: currency.format(
                        double.tryParse(
                              '${calc['taxable_amount_after_transport'] ?? order['taxable_amount_after_transport'] ?? 0}',
                            ) ??
                            0,
                      ),
                    ),
                    PgInvoiceRow(
                      label: 'Total GST',
                      value: currency.format(
                        double.tryParse(
                              '${calc['total_gst'] ?? order['total_gst'] ?? order['gst_amount'] ?? 0}',
                            ) ??
                            0,
                      ),
                    ),
                    PgInvoiceRow(
                      label: 'Grand Total',
                      value: currency.format(
                        double.tryParse(
                              '${calc['grand_total'] ?? order['grand_total'] ?? 0}',
                            ) ??
                            0,
                      ),
                      isTotal: true,
                    ),
                  ],
                ),
              ),
              if (_canDispatch) ...[
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Transport Details',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: AppSpacing.sm),
                        Text(
                          'Transport Type *',
                          style: Theme.of(context).textTheme.bodyMedium,
                        ),
                        const SizedBox(height: 8),
                        SegmentedButton<String>(
                          segments: const [
                            ButtonSegment(
                              value: 'company_transport',
                              label: Text('Company'),
                            ),
                            ButtonSegment(
                              value: 'outside_transport',
                              label: Text('Outside'),
                            ),
                          ],
                          selected: _transportType != null
                              ? {_transportType!}
                              : const {},
                          emptySelectionAllowed: true,
                          onSelectionChanged: (selection) {
                            if (selection.isEmpty) return;
                            _onTransportTypeChanged(selection.first);
                          },
                        ),
                        const SizedBox(height: 16),
                        TextField(
                          controller: _transportAmountController,
                          enabled: _transportType != null,
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          inputFormatters: [
                            FilteringTextInputFormatter.allow(
                              RegExp(r'^\d*\.?\d{0,2}'),
                            ),
                          ],
                          decoration: InputDecoration(
                            labelText: 'Transport Amount *',
                            prefixText: '₹ ',
                            errorText: _amountError,
                            suffixIcon: _calculating
                                ? const Padding(
                                    padding: EdgeInsets.all(12),
                                    child: SizedBox(
                                      width: 18,
                                      height: 18,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                      ),
                                    ),
                                  )
                                : null,
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                FilledButton(
                  onPressed: _canSubmitDispatch ? _dispatch : null,
                  child: _submitting
                      ? const SizedBox(
                          width: 22,
                          height: 22,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('Mark as Dispatched'),
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

  String _formatPercent(Object? value) {
    final parsed = double.tryParse('$value') ?? 0;
    return parsed % 1 == 0
        ? parsed.toStringAsFixed(0)
        : parsed.toStringAsFixed(2);
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

class _ConfirmRow extends StatelessWidget {
  const _ConfirmRow(this.label, this.value, {this.emphasized = false});

  final String label;
  final String value;
  final bool emphasized;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Text(
            label,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
              fontWeight: emphasized ? FontWeight.w700 : null,
            ),
          ),
        ),
        Expanded(
          child: Text(
            value,
            textAlign: TextAlign.end,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
              fontWeight: emphasized ? FontWeight.w700 : null,
            ),
          ),
        ),
      ],
    ),
  );
}
