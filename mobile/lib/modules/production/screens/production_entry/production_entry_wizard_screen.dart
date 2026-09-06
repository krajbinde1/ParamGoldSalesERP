import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:uuid/uuid.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/api_errors.dart';
import '../../../../core/design/app_colors.dart';
import '../../../../core/design/app_spacing.dart';
import '../../../../core/navigation/navigation_guard.dart';
import '../../../../core/network/network_guard.dart';
import '../../../../core/storage/session_store.dart';
import '../../../../core/widgets/design/pg_card.dart';
import '../../../../core/widgets/design/pg_status_badge.dart';
import '../../../../core/widgets/role_shell_widgets.dart';
import '../../../auth/providers/auth_controller.dart';
import '../../api/inventory_production_api.dart';

/// New Production Entry — mirrors Filament CreateProductionEntry:
/// form fields → Review & Confirm → ProductionService::completeProduction.
class ProductionEntryWizardScreen extends StatefulWidget {
  const ProductionEntryWizardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<ProductionEntryWizardScreen> createState() =>
      _ProductionEntryWizardScreenState();
}

class _ProductionEntryWizardScreenState
    extends State<ProductionEntryWizardScreen> {
  final _productionQty = TextEditingController();
  final _labourRate = TextEditingController(text: '0');
  final _labourCost = TextEditingController(text: '0');
  final _transportCost = TextEditingController(text: '0');
  final _otherCost = TextEditingController(text: '0');
  final _notes = TextEditingController();
  final _postingToken = const Uuid().v4();
  final Map<int, TextEditingController> _actualUsedControllers = {};

  DateTime _productionDate = DateTime.now();
  bool _submitting = false;
  bool _loadingPreview = false;
  bool _reviewMode = false;
  bool _online = true;
  bool _syncingLabour = false;

  String _outputType = 'finished_product';
  int? _productId;
  int? _semiFinishedId;
  String? _activeBomLabel;

  List<Map<String, dynamic>> _products = [];
  List<Map<String, dynamic>> _semiFinished = [];
  Map<String, dynamic>? _preview;

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    _online = await NetworkGuard.isOnline();
    await _loadCatalog();
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    _productionQty.dispose();
    _labourRate.dispose();
    _labourCost.dispose();
    _transportCost.dispose();
    _otherCost.dispose();
    _notes.dispose();
    for (final controller in _actualUsedControllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _loadCatalog({String? search}) async {
    try {
      final products = await _api.manufacturableProducts(search: search);
      final sf = await _api.manufacturableSemiFinished();
      if (!mounted) return;
      setState(() {
        if (search == null || search.trim().isEmpty) {
          _products = products;
        } else {
          _products = _mergeProducts(_products, products);
        }
        _semiFinished = sf;
      });
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(e))),
      );
    }
  }

  List<Map<String, dynamic>> _mergeProducts(
    List<Map<String, dynamic>> current,
    List<Map<String, dynamic>> incoming,
  ) {
    final byId = <int, Map<String, dynamic>>{};
    for (final row in [...current, ...incoming]) {
      final id = int.tryParse('${row['id']}') ?? 0;
      if (id > 0) byId[id] = row;
    }
    return byId.values.toList();
  }

  List<Map<String, dynamic>> get _catalog =>
      _outputType == 'finished_product' ? _products : _semiFinished;

  Map<String, dynamic>? get _selectedItem {
    final id = _outputType == 'finished_product' ? _productId : _semiFinishedId;
    if (id == null) return null;
    for (final row in _catalog) {
      if (int.tryParse('${row['id']}') == id) return row;
    }
    return null;
  }

  String get _selectedUnit {
    final item = _selectedItem;
    if (item == null) return '';
    return '${item['production_unit'] ?? item['unit'] ?? ''}'.trim();
  }

  String get _selectedLabel {
    final item = _selectedItem;
    if (item == null) return '-';
    final code = '${item['material_code'] ?? item['product_code'] ?? ''}'.trim();
    final name =
        '${item['name'] ?? item['material_name'] ?? item['product_name'] ?? ''}';
    if (code.isEmpty) return name;
    return '$code — $name';
  }

  double _num(dynamic value) => double.tryParse('$value') ?? 0;

  String _fmtQty(double value) {
    if (value == value.roundToDouble()) {
      return value.toStringAsFixed(0);
    }
    return value.toStringAsFixed(4).replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
  }

  double _round2(double value) => (value * 100).round() / 100;
  double _round4(double value) => (value * 10000).round() / 10000;

  void _recalcLabour({bool resetReview = true}) {
    if (_syncingLabour) return;
    _syncingLabour = true;
    final qty = _num(_productionQty.text.trim());
    final rate = _num(_labourRate.text.trim());
    _labourCost.text = _round2(qty * rate).toStringAsFixed(2);
    _syncingLabour = false;
    if (resetReview) {
      setState(_resetReview);
    } else {
      setState(() {});
    }
  }

  Map<String, dynamic> _payload({bool includeMaterials = false}) {
    final qty = _num(_productionQty.text.trim());
    final labourRate = _num(_labourRate.text.trim());
    return {
      'output_type': _outputType,
      if (_productId != null) 'product_id': _productId,
      if (_semiFinishedId != null) 'semi_finished_id': _semiFinishedId,
      'production_date': _productionDate.toIso8601String().substring(0, 10),
      'production_quantity': qty,
      'planned_quantity': qty,
      'actual_output_quantity': qty,
      'labour_rate_per_nos': labourRate,
      'labour_cost': _num(_labourCost.text.trim()),
      'transport_cost': _num(_transportCost.text.trim()),
      'other_manufacturing_cost': _num(_otherCost.text.trim()),
      'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      'posting_token': _postingToken,
      if (includeMaterials)
        'materials': _requirements
            .map(
              (row) => {
                'bom_item_id': row['bom_item_id'],
                'actual_used_formulation_quantity': _num(
                  row['actual_used_formulation_quantity'] ??
                      row['actual_used_qty'],
                ),
              },
            )
            .toList(),
    };
  }

  void _resetReview() {
    _preview = null;
    _reviewMode = false;
  }

  Future<void> _loadActiveBomLabel() async {
    final id = _outputType == 'finished_product' ? _productId : _semiFinishedId;
    if (id == null) {
      setState(() => _activeBomLabel = null);
      return;
    }
    try {
      final data = await _api.activeBom(
        outputType: _outputType,
        productId: _productId,
        semiFinishedId: _semiFinishedId,
        plannedQuantity: 1,
      );
      if (!mounted) return;
      final bom = data['bom'] is Map
          ? Map<String, dynamic>.from(data['bom'] as Map)
          : data;
      setState(() {
        _activeBomLabel =
            '${bom['bom_number'] ?? data['bom_number'] ?? ''}'.trim();
        if (_activeBomLabel!.isEmpty) _activeBomLabel = null;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _activeBomLabel = null);
    }
  }

  Future<void> _prepareReview() async {
    if (!_online) {
      _toast('You are offline. Connect to review production.');
      return;
    }
    final id = _outputType == 'finished_product' ? _productId : _semiFinishedId;
    final qty = _num(_productionQty.text.trim());
    if (id == null || id <= 0 || qty <= 0) {
      _toast(
        'Select an output item and enter a production quantity greater than zero.',
      );
      return;
    }

    _recalcLabour(resetReview: false);
    setState(() => _loadingPreview = true);
    try {
      final preview = await _api.preview(_payload());
      if (!mounted) return;
      setState(() {
        _preview = preview;
        _reviewMode = true;
        final bom = preview['bom'];
        if (bom is Map) {
          _activeBomLabel = '${bom['bom_number'] ?? ''}'.trim();
          if (_activeBomLabel!.isEmpty) _activeBomLabel = null;
        }
        _syncActualUsedControllers();
      });
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(e))),
      );
    } finally {
      if (mounted) setState(() => _loadingPreview = false);
    }
  }

  bool get _hasShortage => _preview?['has_mandatory_shortage'] == true;

  bool get _hasUsageVariance {
    if (_preview?['has_usage_variance'] == true) return true;
    return _requirements.any(
      (row) =>
          row['is_optional'] != true &&
          (row['has_usage_variance'] == true ||
              _num(row['actual_used_formulation_quantity'] ??
                      row['actual_used_qty']) <
                  _num(row['required_formulation_quantity'] ??
                          row['required_qty'] ??
                          row['formulation_quantity']) -
                      0.0001),
    );
  }

  List<Map<String, dynamic>> get _requirements =>
      (_preview?['requirements'] as List?)
          ?.map((e) => Map<String, dynamic>.from(e as Map))
          .toList() ??
      const [];

  Map<String, dynamic>? get _costing {
    final raw = _preview?['costing'];
    if (raw is Map) return Map<String, dynamic>.from(raw);
    return null;
  }

  void _syncActualUsedControllers() {
    final keep = <int>{};
    for (final row in _requirements) {
      final id = int.tryParse('${row['bom_item_id']}') ?? 0;
      if (id <= 0) continue;
      keep.add(id);
      final text = _fmtQty(
        _num(
          row['actual_used_formulation_quantity'] ?? row['actual_used_qty'],
        ),
      );
      final existing = _actualUsedControllers[id];
      if (existing == null) {
        _actualUsedControllers[id] = TextEditingController(text: text);
      } else if (existing.text != text) {
        existing.text = text;
      }
    }
    final stale = _actualUsedControllers.keys
        .where((id) => !keep.contains(id))
        .toList();
    for (final id in stale) {
      _actualUsedControllers.remove(id)?.dispose();
    }
  }

  void _applyActualUsed(int index, String rawValue) {
    final rows = _requirements;
    if (index < 0 || index >= rows.length) return;
    final row = Map<String, dynamic>.from(rows[index]);
    final required = _num(
      row['required_formulation_quantity'] ??
          row['required_qty'] ??
          row['formulation_quantity'],
    );
    final availableForm = _num(
      row['available_stock_formulation'] ??
          row['max_actual_used_formulation'] ??
          required,
    );
    final maxUsed = [
      required,
      availableForm < 0 ? 0.0 : availableForm,
    ].reduce((a, b) => a < b ? a : b);
    var actual = _num(rawValue);
    if (actual < 0) actual = 0;
    if (actual - maxUsed > 0.0001) actual = maxUsed < 0 ? 0 : maxUsed;

    final rate = _num(row['formulation_average_rate'] ?? row['average_rate']);
    final factor = _num(row['conversion_factor']);
    final invFactor = factor > 0 ? factor : 1;
    final invActual = actual * invFactor;
    final availableInv = _num(row['available_stock']);
    final requiredInv = _num(row['required_quantity']);

    row['actual_used_formulation_quantity'] = actual;
    row['actual_used_qty'] = actual;
    row['actual_used_quantity'] = invActual;
    row['consumed_quantity'] = invActual;
    row['estimated_value'] = _round2(actual * rate);
    row['material_cost'] = row['estimated_value'];
    row['balance_after'] = _round4(availableInv - invActual);
    row['balance_after_formulation'] = _round4(availableForm - actual);
    row['has_usage_variance'] = (required - actual) > 0.0001;
    row['variance_quantity'] = _round4(requiredInv > 0
        ? (requiredInv - invActual).clamp(0, requiredInv)
        : (required - actual).clamp(0, required));
    row['shortage_quantity'] = 0;
    if (row['has_usage_variance'] == true && row['is_optional'] != true) {
      row['stock_status'] = 'usage_variance';
      row['stock_status_label'] = 'Shortage';
    } else {
      row['stock_status'] = 'available';
      row['stock_status_label'] = 'Sufficient';
    }

    final next = [...rows];
    next[index] = row;
    final costing = _recomputeCosting(next);
    setState(() {
      _preview = {
        ...?_preview,
        'requirements': next,
        'costing': costing,
        'has_usage_variance': next.any(
          (item) =>
              item['is_optional'] != true && item['has_usage_variance'] == true,
        ),
        'has_mandatory_shortage': false,
      };
      final id = int.tryParse('${row['bom_item_id']}') ?? 0;
      final typed = _num(rawValue);
      final controller = _actualUsedControllers[id];
      if (controller != null && (typed - actual).abs() > 0.0001) {
        final display = _fmtQty(actual);
        controller.value = TextEditingValue(
          text: display,
          selection: TextSelection.collapsed(offset: display.length),
        );
      }
    });
  }

  Map<String, dynamic>? _recomputeCosting(List<Map<String, dynamic>> rows) {
    final current = _costing;
    if (current == null) return current;
    var material = 0.0;
    var pack = 0.0;
    for (final row in rows) {
      final value = _num(row['estimated_value'] ?? row['material_cost']);
      final type = '${row['item_type']}';
      if (type == 'packaging_material') {
        pack += value;
      } else {
        material += value;
      }
    }
    final labour = _num(_labourCost.text.trim());
    final transport = _num(_transportCost.text.trim());
    final other = _num(_otherCost.text.trim());
    final conversion = labour + transport + other;
    final total = material + pack + conversion;
    final qty = _num(_productionQty.text.trim()).clamp(0.0001, double.infinity);
    return {
      ...current,
      'total_material_cost': _round2(material),
      'total_packaging_cost': _round2(pack),
      'total_conversion_cost': _round2(conversion),
      'total_batch_cost': _round2(total),
      'cost_per_unit': _round4(total / qty),
      'cost_per_pack': _round4(total / qty),
    };
  }

  Future<void> _confirm() async {
    if (_hasShortage || _requirements.isEmpty) {
      _toast(
        _hasShortage
            ? 'Insufficient stock for one or more materials.'
            : 'Review data is missing. Please try again.',
      );
      return;
    }
    if (!_online) {
      _toast('You are offline. Connect to post production.');
      return;
    }

    setState(() => _submitting = true);
    try {
      final batch = await _api.confirmProduction(
        _payload(includeMaterials: true),
      );
      if (!mounted) return;
      final batchId = int.tryParse('${batch['id']}') ?? 0;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Production completed: ${batch['batch_number'] ?? 'OK'}',
          ),
        ),
      );
      if (batchId > 0) {
        context.go('/production/batches/$batchId');
      } else {
        context.go('/production/batches');
      }
    } catch (e) {
      if (!mounted) return;
      _toast(errorMessage(e));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  void _toast(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _productionDate,
      firstDate: DateTime(2020),
      lastDate: DateTime.now().add(const Duration(days: 1)),
    );
    if (picked == null || !mounted) return;
    setState(() {
      _productionDate = picked;
      _resetReview();
    });
  }

  Future<void> _openFinishedProductSearch() async {
    final selected = await showModalBottomSheet<int>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => _FinishedProductSearchSheet(
        products: _products,
        selectedId: _productId,
      ),
    );
    if (selected == null || !mounted) return;
    setState(() {
      _productId = selected;
      _semiFinishedId = null;
      _resetReview();
    });
    _loadActiveBomLabel();
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: !_reviewMode,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop && _reviewMode) {
          setState(() => _reviewMode = false);
        }
      },
      child: Scaffold(
        appBar: RoleAppBar(
          title: _reviewMode ? 'Review Production' : 'New Production Entry',
          auth: widget.auth,
          showBack: true,
          onBack: () {
            if (_reviewMode) {
              setState(() => _reviewMode = false);
              return;
            }
            smartBack(context);
          },
        ),
        body: _reviewMode ? _buildReview() : _buildForm(),
      ),
    );
  }

  Widget _buildForm() {
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.screenPadding),
      children: [
        Text(
          'Enter production details, then open Review to confirm materials and costs.',
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: AppColors.textSecondary,
              ),
        ),
        const SizedBox(height: AppSpacing.md),
        DropdownButtonFormField<String>(
          value: _outputType,
          decoration: const InputDecoration(labelText: 'Output Type'),
          items: const [
            DropdownMenuItem(
              value: 'finished_product',
              child: Text('Finished Product'),
            ),
            DropdownMenuItem(
              value: 'semi_finished',
              child: Text('Semi-Finished'),
            ),
          ],
          onChanged: (value) {
            if (value == null) return;
            setState(() {
              _outputType = value;
              _productId = null;
              _semiFinishedId = null;
              _activeBomLabel = null;
              _resetReview();
            });
          },
        ),
        const SizedBox(height: AppSpacing.sm),
        if (_outputType == 'finished_product')
          InkWell(
            onTap: _openFinishedProductSearch,
            child: InputDecorator(
              decoration: const InputDecoration(
                labelText: 'Finished Product',
                suffixIcon: Icon(Icons.search),
              ),
              child: Text(
                _productId == null ? 'Search by name or product code' : _selectedLabel,
                style: TextStyle(
                  color: _productId == null
                      ? AppColors.textMuted
                      : AppColors.textPrimary,
                ),
              ),
            ),
          )
        else
          DropdownButtonFormField<int>(
            value: _semiFinishedId,
            isExpanded: true,
            decoration: const InputDecoration(
              labelText: 'Semi-Finished Material',
            ),
            items: _semiFinished
                .map((row) {
                  final id = int.tryParse('${row['id']}') ?? 0;
                  final code = '${row['material_code'] ?? ''}'.trim();
                  final name =
                      '${row['name'] ?? row['material_name'] ?? 'Semi-Finished'}';
                  final label = code.isEmpty ? name : '$code — $name';
                  return DropdownMenuItem(value: id, child: Text(label));
                })
                .where((e) => e.value! > 0)
                .toList(),
            onChanged: (id) {
              setState(() {
                _semiFinishedId = id;
                _productId = null;
                _resetReview();
              });
              _loadActiveBomLabel();
            },
          ),
        const SizedBox(height: AppSpacing.sm),
        InputDecorator(
          decoration: const InputDecoration(labelText: 'Active BOM'),
          child: Text(
            _activeBomLabel ?? 'Select an output item to load the active BOM',
            style: TextStyle(
              color: _activeBomLabel == null
                  ? AppColors.textMuted
                  : AppColors.textPrimary,
            ),
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        ListTile(
          contentPadding: EdgeInsets.zero,
          title: const Text('Production Date'),
          subtitle: Text(
            _productionDate.toIso8601String().substring(0, 10),
          ),
          trailing: const Icon(Icons.calendar_today_outlined),
          onTap: _pickDate,
        ),
        TextField(
          controller: _productionQty,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
            labelText: 'Production Quantity',
            suffixText: _selectedUnit.isEmpty ? null : _selectedUnit,
          ),
          onChanged: (_) => _recalcLabour(),
        ),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          controller: _labourRate,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(
            labelText: 'Labour Rate Per Nos',
            prefixText: '₹ ',
            suffixText: '/Nos',
          ),
          onChanged: (_) => _recalcLabour(),
        ),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          controller: _labourCost,
          readOnly: true,
          enableInteractiveSelection: false,
          decoration: const InputDecoration(
            labelText: 'Total Labour Cost',
            prefixText: '₹ ',
            helperText: 'Production Quantity × Labour Rate Per Nos',
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          controller: _transportCost,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(
            labelText: 'Transport Cost',
            prefixText: '₹ ',
          ),
          onChanged: (_) => setState(_resetReview),
        ),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          controller: _otherCost,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(
            labelText: 'Other Manufacturing Cost',
            prefixText: '₹ ',
          ),
          onChanged: (_) => setState(_resetReview),
        ),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          controller: _notes,
          maxLines: 2,
          decoration: const InputDecoration(labelText: 'Remarks'),
          onChanged: (_) => setState(_resetReview),
        ),
        const SizedBox(height: AppSpacing.lg),
        FilledButton.icon(
          onPressed: _loadingPreview ? null : _prepareReview,
          icon: _loadingPreview
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Icon(Icons.fact_check_outlined),
          label: Text(_loadingPreview ? 'Preparing…' : 'Review & Confirm'),
        ),
      ],
    );
  }

  Widget _buildReview() {
    final showCosts = widget.auth.permissions.canViewProductionCosts ||
        _preview?['can_view_costs'] == true ||
        _costing != null;
    final qty = _num(_productionQty.text.trim());

    return ListView(
      padding: const EdgeInsets.all(AppSpacing.screenPadding),
      children: [
        if (_hasShortage)
          PgCard(
            child: Row(
              children: [
                const Icon(Icons.error_outline, color: Colors.red),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'Insufficient stock for one or more materials. Cannot post production.',
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: Colors.red.shade700,
                        ),
                  ),
                ),
              ],
            ),
          ),
        if (_hasShortage) const SizedBox(height: AppSpacing.sm),
        if (_hasUsageVariance && !_hasShortage)
          PgCard(
            child: Row(
              children: [
                Icon(Icons.warning_amber_outlined, color: Colors.orange.shade800),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'Actual Used Qty is below Required Qty for one or more materials. Confirming will post the actual used quantity.',
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: Colors.orange.shade800,
                        ),
                  ),
                ),
              ],
            ),
          ),
        if (_hasUsageVariance && !_hasShortage) const SizedBox(height: AppSpacing.sm),
        PgCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(_selectedLabel,
                  style: Theme.of(context).textTheme.titleSmall),
              const SizedBox(height: 4),
              Text('BOM: ${_activeBomLabel ?? '-'}'),
              Text(
                'Date: ${_productionDate.toIso8601String().substring(0, 10)}'
                ' · Qty: $qty $_selectedUnit',
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        Text(
          'Material Consumption',
          style: Theme.of(context).textTheme.titleSmall,
        ),
        const SizedBox(height: AppSpacing.sm),
        ...List.generate(_requirements.length, _requirementCard),
        if (showCosts && _costing != null) ...[
          const SizedBox(height: AppSpacing.md),
          Text(
            'Cost Summary',
            style: Theme.of(context).textTheme.titleSmall,
          ),
          const SizedBox(height: AppSpacing.sm),
          PgCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _costRow(
                  'Material',
                  _costing!['total_material_cost'] ??
                      _costing!['material_cost'],
                ),
                _costRow('Packaging', _costing!['total_packaging_cost']),
                _costRow('Labour Rate / Nos', _labourRate.text),
                _costRow('Total Labour Cost', _labourCost.text),
                _costRow('Transport', _transportCost.text),
                _costRow('Other', _otherCost.text),
                const Divider(),
                _costRow(
                  'Total Batch Cost',
                  _costing!['total_batch_cost'],
                  bold: true,
                ),
                _costRow(
                  'Cost Per Unit / Pack',
                  _costing!['cost_per_unit'] ?? _costing!['cost_per_pack'],
                  bold: true,
                ),
              ],
            ),
          ),
        ],
        const SizedBox(height: AppSpacing.lg),
        Row(
          children: [
            Expanded(
              child: OutlinedButton(
                onPressed: _submitting
                    ? null
                    : () => setState(() => _reviewMode = false),
                child: const Text('Back'),
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              flex: 2,
              child: FilledButton.icon(
                onPressed: (_submitting || _hasShortage || _requirements.isEmpty)
                    ? null
                    : _confirm,
                icon: _submitting
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.check_circle_outline),
                label: Text(
                  _submitting ? 'Posting…' : 'Confirm Production',
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _requirementCard(int index) {
    final row = _requirements[index];
    final id = int.tryParse('${row['bom_item_id']}') ?? index;
    final controller = _actualUsedControllers.putIfAbsent(
      id,
      () => TextEditingController(
        text: _fmtQty(
          _num(
            row['actual_used_formulation_quantity'] ?? row['actual_used_qty'],
          ),
        ),
      ),
    );
    final formUnit =
        '${row['uom'] ?? row['formulation_unit'] ?? row['unit'] ?? ''}'.trim();
    final invUnit =
        '${row['inventory_unit'] ?? row['unit'] ?? ''}'.trim();
    final name = '${row['material_name'] ?? row['name'] ?? 'Material'}';
    final required = _num(
      row['required_qty'] ??
          row['required_formulation_quantity'] ??
          row['formulation_quantity'],
    );
    final actual = _num(
      row['actual_used_formulation_quantity'] ?? row['actual_used_qty'],
    );
    final availableInv = _num(row['available_stock']);
    final balance = row['balance_after'] ?? (availableInv - _num(row['actual_used_quantity']));
    final status = '${row['stock_status_label'] ?? 'Sufficient'}';
    final showVariance = row['has_usage_variance'] == true || actual + 0.0001 < required;
    final maxUsed = _num(row['max_actual_used_formulation']);

    return PgCard(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  name,
                  style: Theme.of(context).textTheme.titleSmall,
                ),
              ),
              PgStatusBadge(label: status),
            ],
          ),
          const SizedBox(height: 6),
          Text('Required Qty: ${_fmtQty(required)} $formUnit'),
          const SizedBox(height: 6),
          TextField(
            controller: controller,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(
              labelText: 'Actual Used Qty',
              suffixText: formUnit.isEmpty ? null : formUnit,
              helperText: maxUsed > 0
                  ? 'Max ${_fmtQty(maxUsed)} $formUnit'
                  : 'Same UOM as Required Qty',
            ),
            onChanged: (value) => _applyActualUsed(index, value),
          ),
          Text('UOM: $formUnit'),
          Text('Available Stock: ${_fmtQty(availableInv)} $invUnit'),
          Text('Balance After Production: ${_fmtQty(_num(balance))} $invUnit'),
          if (row['average_rate'] != null)
            Text('Average Rate: ₹${row['average_rate']} / $formUnit'),
          if (row['material_cost'] != null || row['estimated_value'] != null)
            Text(
              'Material Cost: ₹${row['material_cost'] ?? row['estimated_value']}',
            ),
          if (showVariance)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'Shortage / variance: ${_fmtQty(required - actual)} $formUnit below required',
                style: TextStyle(color: Colors.orange.shade800),
              ),
            ),
        ],
      ),
    );
  }

  Widget _costRow(String label, dynamic value, {bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Expanded(child: Text(label)),
          Text(
            '₹$value',
            style: bold
                ? const TextStyle(fontWeight: FontWeight.w700)
                : null,
          ),
        ],
      ),
    );
  }
}

class _FinishedProductSearchSheet extends StatefulWidget {
  const _FinishedProductSearchSheet({
    required this.products,
    required this.selectedId,
  });

  final List<Map<String, dynamic>> products;
  final int? selectedId;

  @override
  State<_FinishedProductSearchSheet> createState() =>
      _FinishedProductSearchSheetState();
}

class _FinishedProductSearchSheetState
    extends State<_FinishedProductSearchSheet> {
  final _query = TextEditingController();
  String _filter = '';

  @override
  void dispose() {
    _query.dispose();
    super.dispose();
  }

  List<Map<String, dynamic>> get _matches {
    final q = _filter.trim().toLowerCase();
    if (q.isEmpty) return widget.products;
    return widget.products.where((row) {
      final name =
          '${row['product_name'] ?? row['name'] ?? ''}'.toLowerCase();
      final code = '${row['product_code'] ?? ''}'.toLowerCase();
      final label = '${row['label'] ?? ''}'.toLowerCase();
      return name.contains(q) || code.contains(q) || label.contains(q);
    }).toList();
  }

  String _label(Map<String, dynamic> row) {
    final code = '${row['product_code'] ?? ''}'.trim();
    final name = '${row['product_name'] ?? row['name'] ?? 'Product'}'.trim();
    if (code.isEmpty) return name;
    return '$code — $name';
  }

  @override
  Widget build(BuildContext context) {
    final matches = _matches;
    return Padding(
      padding: EdgeInsets.only(
        left: AppSpacing.screenPadding,
        right: AppSpacing.screenPadding,
        top: AppSpacing.md,
        bottom: MediaQuery.of(context).viewInsets.bottom + AppSpacing.md,
      ),
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.7,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Select Finished Product',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: AppSpacing.sm),
            TextField(
              controller: _query,
              autofocus: true,
              decoration: const InputDecoration(
                labelText: 'Search by Product Name or Product Code',
                prefixIcon: Icon(Icons.search),
              ),
              onChanged: (value) {
                setState(() => _filter = value);
              },
            ),
            const SizedBox(height: AppSpacing.sm),
            Expanded(
              child: matches.isEmpty
                  ? const Center(child: Text('No matching finished products.'))
                  : ListView.builder(
                      itemCount: matches.length,
                      itemBuilder: (context, index) {
                        final row = matches[index];
                        final id = int.tryParse('${row['id']}') ?? 0;
                        return ListTile(
                          title: Text(_label(row)),
                          selected: id == widget.selectedId,
                          onTap: id > 0 ? () => Navigator.pop(context, id) : null,
                        );
                      },
                    ),
            ),
          ],
        ),
      ),
    );
  }
}
