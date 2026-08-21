import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/utils/bill_document.dart';
import '../../../core/utils/order_number.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/prompt_dialog.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../../orders/models/order.dart';
import '../../orders/models/order_detail.dart';
import '../../orders/widgets/order_info_card.dart';
import '../../orders/widgets/order_invoice_products_table.dart';
import '../../orders/widgets/order_widgets.dart';
import '../api/production_api.dart';
import '../widgets/mark_as_dispatched_dialog.dart';

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
  bool _submitting = false;

  ProductionApi get _api => ProductionApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _loadOrder();
  }

  Future<Map<String, dynamic>> _loadOrder() async {
    final order = await _api.getOrder(widget.orderId);
    _order = order;
    return order;
  }

  Map<String, dynamic> get _calculation {
    final calc = _order?['calculation'];
    if (calc is Map) {
      return Map<String, dynamic>.from(calc);
    }
    return const {};
  }

  String get _status =>
      (_order?['status']?.toString() ?? '').trim().toLowerCase();

  bool get _isPendingForBilling => _status == 'pending_for_billing';

  bool get _canSendForBill => ProductionApi.isFlagTrue(
        _order?['can_send_for_bill'],
      );

  bool get _canHold => ProductionApi.isFlagTrue(_order?['can_hold']);

  bool get _canReleaseHold =>
      ProductionApi.isFlagTrue(_order?['can_release_hold']);

  bool get _canRevertToManager =>
      ProductionApi.isFlagTrue(_order?['can_revert_to_manager']);

  bool get _canDispatch =>
      widget.auth.permissions.canDispatchOrders &&
      ProductionApi.canShowDispatchAction(
        status: _status,
        canDispatch: _order?['can_dispatch'],
      );

  Future<void> _showSendForBillModal() async {
    var vehicles = <Map<String, dynamic>>[];
    String? formError;
    var submitting = false;
    int? selectedVehicleId;
    String? selectedChargeType;

    final originalGrandTotal =
        double.tryParse('${_order?['grand_total'] ?? 0}') ?? 0;

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
                    Text(
                      'Transport Charge Type *',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                    RadioListTile<String>(
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      title: const Text('Company Transport'),
                      value: 'company_transport',
                      groupValue: selectedChargeType,
                      onChanged: submitting
                          ? null
                          : (value) {
                              setModalState(() {
                                selectedChargeType = value;
                                formError = null;
                              });
                            },
                    ),
                    RadioListTile<String>(
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      title: const Text('Transport Charges Extra'),
                      value: 'transport_extra',
                      groupValue: selectedChargeType,
                      onChanged: submitting
                          ? null
                          : (value) {
                              setModalState(() {
                                selectedChargeType = value;
                                formError = null;
                              });
                            },
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
                      onChanged: (_) => setModalState(() {}),
                      decoration: const InputDecoration(
                        labelText: 'Transport Charges *',
                        prefixText: '₹ ',
                      ),
                    ),
                    const SizedBox(height: 12),
                    _SendForBillTotalPreview(
                      originalGrandTotal: originalGrandTotal,
                      chargeType: selectedChargeType,
                      charges: double.tryParse(
                            freightController.text.trim(),
                          ) ??
                          0,
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
                          if (selectedChargeType == null) {
                            setModalState(
                              () => formError =
                                  'Select Company Transport or Transport Charges Extra.',
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
                          if (selectedChargeType == 'company_transport' &&
                              freight > originalGrandTotal) {
                            setModalState(
                              () => formError =
                                  'Company Transport charges cannot exceed the original grand total.',
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
    final chargeType = selectedChargeType;
    freightController.dispose();
    remarkController.dispose();

    if (confirmed != true ||
        !mounted ||
        vehicleId == null ||
        chargeType == null) {
      return;
    }

    setState(() => _submitting = true);
    try {
      final updated = await _api.sendForBill(
        widget.orderId,
        vehicleId: vehicleId,
        transportFreight: freight,
        transportChargeType: chargeType,
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
    if (!_canDispatch || _submitting) return;

    setState(() => _submitting = true);
    try {
      final ok = await confirmAndDispatchProductionOrder(
        context: context,
        api: _api,
        order: _order ?? const {},
        showSuccessMessage: false,
      );
      if (!ok || !mounted) return;

      _popDetail('dispatched');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _holdOrder() async {
    if (!_canHold || _submitting) return;
    final remark = await promptRemarkDialog(
      context,
      title: 'Hold Order',
      label: 'Hold Remark / Reason',
      submitLabel: 'Confirm Hold',
      required: true,
    );
    if (remark == null || !mounted) return;

    setState(() => _submitting = true);
    try {
      final updated = await _api.holdOrder(widget.orderId, remark: remark);
      if (!mounted) return;
      setState(() {
        _order = updated;
        _future = Future.value(updated);
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Order put on hold.')),
      );
      _popDetail('held');
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(errorMessage(error))));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _releaseHold() async {
    if (!_canReleaseHold || _submitting) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Release Hold'),
        content: const Text(
          'Release this order back to Production? Manager approval stays valid.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Release Hold'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;

    setState(() => _submitting = true);
    try {
      final updated = await _api.releaseHold(widget.orderId);
      if (!mounted) return;
      setState(() {
        _order = updated;
        _future = Future.value(updated);
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Hold released.')),
      );
      _popDetail('released');
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(errorMessage(error))));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _revertToManager() async {
    if (!_canRevertToManager || _submitting) return;
    final remark = await promptRemarkDialog(
      context,
      title: 'Revert to Manager',
      label: 'Revert Remark',
      submitLabel: 'Confirm Revert',
      required: true,
    );
    if (remark == null || !mounted) return;

    setState(() => _submitting = true);
    try {
      final updated = await _api.revertToManager(
        widget.orderId,
        remark: remark,
      );
      if (!mounted) return;
      setState(() {
        _order = updated;
        _future = Future.value(updated);
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Order returned to manager.')),
      );
      _popDetail('reverted');
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(errorMessage(error))));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  void _popDetail([Object? result]) {
    if (!context.mounted) return;
    if (context.canPop()) {
      context.pop(result);
      return;
    }
    smartBack(context);
  }

  @override
  Widget build(BuildContext context) {
    final canPop = context.canPop();
    return PopScope(
      canPop: canPop,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        _popDetail();
      },
      child: Scaffold(
      appBar: RoleAppBar(
        title: 'Order Details',
        auth: widget.auth,
        showBack: true,
        onBack: () => _popDetail(),
      ),
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
              if (_canDispatch) ...[
                const SizedBox(height: AppSpacing.md),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: _submitting ? null : _dispatch,
                    icon: _submitting
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.local_shipping_outlined),
                    label: Text(
                      _submitting ? 'Dispatching...' : 'Mark as Dispatched',
                    ),
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(48),
                    ),
                  ),
                ),
              ],
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
              if (_canHold) ...[
                const SizedBox(height: AppSpacing.sm),
                OutlinedButton.icon(
                  onPressed: _submitting ? null : _holdOrder,
                  icon: const Icon(Icons.pause_circle_outline),
                  label: const Text('Hold Order'),
                ),
              ],
              if (_canRevertToManager) ...[
                const SizedBox(height: AppSpacing.sm),
                OutlinedButton.icon(
                  onPressed: _submitting ? null : _revertToManager,
                  icon: const Icon(Icons.undo),
                  label: const Text('Revert to Manager'),
                ),
              ],
              if (_canReleaseHold) ...[
                const SizedBox(height: AppSpacing.md),
                FilledButton.icon(
                  onPressed: _submitting ? null : _releaseHold,
                  icon: const Icon(Icons.play_circle_outline),
                  label: const Text('Release Hold'),
                ),
              ],
              if (_status == 'on_hold') ...[
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Text(
                    'This order is on hold. Send for Bill is blocked until the hold is released.'
                    '${(order['hold_remark']?.toString().trim().isNotEmpty ?? false) ? '\nRemark: ${order['hold_remark']}' : ''}',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ),
              ],
              if (_status == 'reverted_to_manager') ...[
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  child: Text(
                    'Returned to Manager for review. Send for Bill is unavailable until re-approval.'
                    '${(order['revert_remark']?.toString().trim().isNotEmpty ?? false) ? '\nRemark: ${order['revert_remark']}' : ''}',
                    style: Theme.of(context).textTheme.bodyMedium,
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
                summary: OrderInvoiceSummaryBlock.fromOrderMap(
                  Map<String, dynamic>.from(order),
                  calculation: calc,
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
            ],
          );
        },
      ),
    ),
    );
  }
}

class _SendForBillTotalPreview extends StatelessWidget {
  const _SendForBillTotalPreview({
    required this.originalGrandTotal,
    required this.chargeType,
    required this.charges,
  });

  final double originalGrandTotal;
  final String? chargeType;
  final double charges;

  @override
  Widget build(BuildContext context) {
    final safeCharges = charges < 0 ? 0.0 : charges;
    var adjustment = 0.0;
    String? error;
    var typeLabel = '—';

    if (chargeType == 'company_transport') {
      typeLabel = 'Company Transport';
      if (safeCharges > originalGrandTotal) {
        error =
            'Company Transport charges cannot exceed the original grand total.';
      } else {
        adjustment = -safeCharges;
      }
    } else if (chargeType == 'transport_extra') {
      typeLabel = 'Transport Charges Extra';
      adjustment = safeCharges;
    }

    if (safeCharges == 0) {
      adjustment = 0;
    }

    final finalTotal = originalGrandTotal + adjustment;
    final money = OrderInvoiceProductsTable.money;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        children: [
          PgInvoiceRow(
            label: 'Original Grand Total',
            value: money(originalGrandTotal),
          ),
          PgInvoiceRow(
            label: 'Transport Charges',
            value: money(safeCharges),
          ),
          PgInvoiceRow(label: 'Transport Type', value: typeLabel),
          PgInvoiceRow(
            label: 'Adjustment',
            value: chargeType == null
                ? '—'
                : '${adjustment < 0 ? '- ' : '+ '}${money(adjustment.abs())}',
          ),
          const Divider(height: 16),
          PgInvoiceRow(
            label: 'Final Grand Total',
            value: money(finalTotal),
            isTotal: true,
          ),
          if (error != null) ...[
            const SizedBox(height: 8),
            Text(
              error,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}

