import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/utils/bill_document.dart';
import '../../../core/utils/order_number.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../../orders/models/order.dart';
import '../../orders/models/order_detail.dart';
import '../../orders/widgets/order_info_card.dart';
import '../../orders/widgets/order_invoice_products_table.dart';
import '../../orders/widgets/order_widgets.dart';
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

  String get _status => _order?['status']?.toString() ?? '';

  bool get _isPendingForBilling => _status == 'pending_for_billing';

  bool get _isBilled =>
      _status == 'billed' && (_order?['can_dispatch'] == true);

  bool get _canSendForBill => _order?['can_send_for_bill'] == true;

  bool get _canDispatch =>
      _isBilled && widget.auth.permissions.canDispatchOrders;

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

  Future<void> _showSendForBillModal() async {
    var vehicles = <Map<String, dynamic>>[];
    String? formError;
    var submitting = false;
    int? selectedVehicleId;

    final freightController = TextEditingController(
      text: (_order?['transport_amount'] != null &&
              (double.tryParse('${_order?['transport_amount']}') ?? 0) > 0)
          ? (double.tryParse('${_order?['transport_amount']}') ?? 0)
                .toStringAsFixed(2)
          : '',
    );
    final remarkController = TextEditingController(
      text: _order?['transport_remark']?.toString() ?? '',
    );

    try {
      vehicles = await _api.listVehicles();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
      freightController.dispose();
      remarkController.dispose();
      return;
    }

    if (!mounted) {
      freightController.dispose();
      remarkController.dispose();
      return;
    }

    Future<void> reloadVehicles(
      void Function(void Function()) setModalState, {
      int? selectId,
    }) async {
      try {
        final loaded = await _api.listVehicles();
        setModalState(() {
          vehicles = loaded;
          if (selectId != null) {
            selectedVehicleId = selectId;
          }
          formError = null;
        });
      } catch (error) {
        setModalState(() => formError = errorMessage(error));
      }
    }

    Future<void> showAddVehicle(
      void Function(void Function()) setModalState,
    ) async {
      final numberController = TextEditingController();
      final nameController = TextEditingController();
      final typeController = TextEditingController();
      String? addError;
      var saving = false;

      final created = await showDialog<Map<String, dynamic>>(
        context: context,
        barrierDismissible: false,
        builder: (context) {
          return StatefulBuilder(
            builder: (context, setAddState) {
              return AlertDialog(
                title: const Text('Add Vehicle'),
                content: SingleChildScrollView(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      TextField(
                        controller: numberController,
                        textCapitalization: TextCapitalization.characters,
                        decoration: const InputDecoration(
                          labelText: 'Vehicle Number *',
                        ),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: nameController,
                        decoration: const InputDecoration(
                          labelText: 'Vehicle Name / Model',
                        ),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: typeController,
                        decoration: const InputDecoration(
                          labelText: 'Vehicle Type',
                        ),
                      ),
                      if (addError != null) ...[
                        const SizedBox(height: 12),
                        Text(
                          addError!,
                          style: TextStyle(
                            color: Theme.of(context).colorScheme.error,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                actions: [
                  TextButton(
                    onPressed:
                        saving ? null : () => Navigator.pop(context),
                    child: const Text('Cancel'),
                  ),
                  FilledButton(
                    onPressed: saving
                        ? null
                        : () async {
                            final number = numberController.text.trim();
                            if (number.isEmpty) {
                              setAddState(
                                () => addError = 'Vehicle number is required.',
                              );
                              return;
                            }
                            setAddState(() {
                              saving = true;
                              addError = null;
                            });
                            try {
                              final vehicle = await _api.createVehicle(
                                vehicleNumber: number,
                                vehicleName: nameController.text.trim().isEmpty
                                    ? null
                                    : nameController.text.trim(),
                                vehicleType: typeController.text.trim().isEmpty
                                    ? null
                                    : typeController.text.trim(),
                              );
                              if (context.mounted) {
                                Navigator.pop(context, vehicle);
                              }
                            } catch (error) {
                              setAddState(() {
                                saving = false;
                                addError = errorMessage(error);
                              });
                            }
                          },
                    child: saving
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('Save Vehicle'),
                  ),
                ],
              );
            },
          );
        },
      );

      numberController.dispose();
      nameController.dispose();
      typeController.dispose();

      if (created == null) return;
      final newId = int.tryParse('${created['id']}');
      await reloadVehicles(setModalState, selectId: newId);
    }

    final confirmed = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            return AlertDialog(
              title: const Text('Send for Bill'),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Order ${productionOrderListNo(_order ?? const {})}',
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<int>(
                      value: selectedVehicleId,
                      isExpanded: true,
                      decoration: const InputDecoration(
                        labelText: 'Vehicle Number *',
                      ),
                      items: vehicles.map((vehicle) {
                        final id = int.tryParse('${vehicle['id']}') ?? 0;
                        final label =
                            vehicle['display_label']?.toString() ??
                                vehicle['vehicle_number']?.toString() ??
                                '-';
                        return DropdownMenuItem<int>(
                          value: id,
                          child: Text(label, overflow: TextOverflow.ellipsis),
                        );
                      }).toList(),
                      onChanged: (value) {
                        setModalState(() {
                          selectedVehicleId = value;
                          formError = null;
                        });
                      },
                    ),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: TextButton.icon(
                        onPressed: submitting
                            ? null
                            : () => showAddVehicle(setModalState),
                        icon: const Icon(Icons.add, size: 18),
                        label: const Text('+ Add Vehicle'),
                      ),
                    ),
                    const SizedBox(height: 4),
                    TextField(
                      controller: freightController,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      inputFormatters: [
                        FilteringTextInputFormatter.allow(
                          RegExp(r'^\d*\.?\d{0,2}'),
                        ),
                      ],
                      decoration: const InputDecoration(
                        labelText: 'Transport Charges *',
                        prefixText: '₹ ',
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: remarkController,
                      maxLines: 3,
                      decoration: const InputDecoration(
                        labelText: 'Transport Remark (optional)',
                      ),
                    ),
                    if (formError != null) ...[
                      const SizedBox(height: 12),
                      Text(
                        formError!,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: submitting
                      ? null
                      : () => Navigator.pop(context, false),
                  child: const Text('Cancel'),
                ),
                FilledButton(
                  onPressed: submitting
                      ? null
                      : () {
                          final freight = double.tryParse(
                            freightController.text.trim(),
                          );
                          if (selectedVehicleId == null) {
                            setModalState(
                              () => formError = 'Select a vehicle.',
                            );
                            return;
                          }
                          if (freight == null || freight < 0) {
                            setModalState(
                              () => formError =
                                  'Enter a valid transport charge amount.',
                            );
                            return;
                          }
                          Navigator.pop(context, true);
                        },
                  child: submitting
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('Send for Bill'),
                ),
              ],
            );
          },
        );
      },
    );

    final freight =
        double.tryParse(freightController.text.trim()) ?? 0;
    final remark = remarkController.text.trim();
    final vehicleId = selectedVehicleId;
    freightController.dispose();
    remarkController.dispose();

    if (confirmed != true || !mounted || vehicleId == null) return;

    setState(() => _submitting = true);
    try {
      final updated = await _api.sendForBill(
        widget.orderId,
        vehicleId: vehicleId,
        transportFreight: freight,
        transportRemark: remark.isEmpty ? null : remark,
      );
      if (!mounted) return;
      setState(() {
        _order = updated;
        _future = Future.value(updated);
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Order sent for billing.')),
      );
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(errorMessage(error))));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
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
          final billUrl = order['bill_url']?.toString().trim() ?? '';

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              PgDetailHeader(
                title: order['order_no']?.toString() ?? '-',
                subtitle: dealer?['firm_name']?.toString() ?? '-',
                badgeLabel: OrderStatusRules.badgeLabel(
                  status,
                  statusLabel: order['status_label']?.toString(),
                ),
                badgeTone: PgStatusRules.orderTone(status),
              ),
              const SizedBox(height: AppSpacing.md),
              OrderInfoCard.fromOrderMap(
                Map<String, dynamic>.from(order),
                dealer: dealer is Map
                    ? Map<String, dynamic>.from(dealer)
                    : null,
              ),
              if (billUrl.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.md),
                OutlinedButton.icon(
                  onPressed: () => openBillDocument(
                    context,
                    url: billUrl,
                    title: 'Bill ${order['bill_number'] ?? ''}'.trim(),
                  ),
                  icon: const Icon(Icons.picture_as_pdf_outlined),
                  label: const Text('View Bill'),
                ),
              ],
              if (_canSendForBill) ...[
                const SizedBox(height: AppSpacing.md),
                FilledButton.icon(
                  onPressed: _submitting ? null : _showSendForBillModal,
                  icon: _submitting
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.send_outlined),
                  label: Text(
                    _submitting ? 'Sending...' : 'Send for Bill',
                  ),
                ),
              ],
              if (_isPendingForBilling) ...[
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Text(
                    'This order is pending for Admin billing.',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ),
              ],
              const SizedBox(height: AppSpacing.md),
              OrderInvoiceProductsCard.sharedReview(
                lines: items
                    .map(
                      (raw) => OrderInvoiceLine.fromMap(
                        Map<String, dynamic>.from(raw as Map),
                      ),
                    )
                    .toList(growable: false),
                summary: OrderInvoiceSummaryBlock(
                  subtotal: double.tryParse(
                        '${calc['gross_amount'] ?? order['gross_amount'] ?? order['subtotal'] ?? 0}',
                      ) ??
                      0,
                  discount: double.tryParse(
                        '${calc['total_discount'] ?? order['total_discount'] ?? order['discount_amount'] ?? 0}',
                      ) ??
                      0,
                  gst: double.tryParse(
                        '${calc['total_gst'] ?? order['total_gst'] ?? order['gst_amount'] ?? 0}',
                      ) ??
                      0,
                  grandTotal: double.tryParse(
                        '${calc['grand_total'] ?? order['grand_total'] ?? 0}',
                      ) ??
                      0,
                  transport: order['transport_amount'] == null
                      ? null
                      : double.tryParse('${order['transport_amount']}') ?? 0,
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Status Timeline',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    ...() {
                      final rawTimeline =
                          (order['timeline'] as List?) ?? const [];
                      final timeline = rawTimeline
                          .whereType<Map>()
                          .map(
                            (step) => OrderTimelineStep.fromApi(
                              Map<String, dynamic>.from(step),
                            ),
                          )
                          .toList();
                      final steps = timeline.isNotEmpty
                          ? timeline
                          : OrderTimelineStep.build(status);
                      return steps.asMap().entries.map(
                        (entry) => OrderTimelineRow(
                          step: entry.value,
                          isLast: entry.key == steps.length - 1,
                        ),
                      );
                    }(),
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
                        'Dispatch Transport',
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
