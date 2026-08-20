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
  final _labourCost = TextEditingController(text: '0');
  final _transportCost = TextEditingController(text: '0');
  final _otherCost = TextEditingController(text: '0');
  final _notes = TextEditingController();
  final _postingToken = const Uuid().v4();

  DateTime _productionDate = DateTime.now();
  bool _submitting = false;
  bool _loadingPreview = false;
  bool _reviewMode = false;
  bool _online = true;

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
    _labourCost.dispose();
    _transportCost.dispose();
    _otherCost.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _loadCatalog() async {
    try {
      final products = await _api.manufacturableProducts();
      final sf = await _api.manufacturableSemiFinished();
      if (!mounted) return;
      setState(() {
        _products = products;
        _semiFinished = sf;
      });
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(e))),
      );
    }
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

  Map<String, dynamic> _payload() {
    final qty = double.tryParse(_productionQty.text.trim()) ?? 0;
    return {
      'output_type': _outputType,
      if (_productId != null) 'product_id': _productId,
      if (_semiFinishedId != null) 'semi_finished_id': _semiFinishedId,
      'production_date': _productionDate.toIso8601String().substring(0, 10),
      'production_quantity': qty,
      'planned_quantity': qty,
      'actual_output_quantity': qty,
      'labour_cost': double.tryParse(_labourCost.text.trim()) ?? 0,
      'transport_cost': double.tryParse(_transportCost.text.trim()) ?? 0,
      'other_manufacturing_cost': double.tryParse(_otherCost.text.trim()) ?? 0,
      'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      'posting_token': _postingToken,
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
    final qty = double.tryParse(_productionQty.text.trim()) ?? 0;
    if (id == null || id <= 0 || qty <= 0) {
      _toast(
        'Select an output item and enter a production quantity greater than zero.',
      );
      return;
    }

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
      final batch = await _api.confirmProduction(_payload());
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
          DropdownButtonFormField<int>(
            value: _productId,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Finished Product'),
            items: _products
                .map((row) {
                  final id = int.tryParse('${row['id']}') ?? 0;
                  final code = '${row['product_code'] ?? ''}'.trim();
                  final name =
                      '${row['name'] ?? row['product_name'] ?? 'Product'}';
                  final label = code.isEmpty ? name : '$code — $name';
                  return DropdownMenuItem(value: id, child: Text(label));
                })
                .where((e) => e.value! > 0)
                .toList(),
            onChanged: (id) {
              setState(() {
                _productId = id;
                _semiFinishedId = null;
                _resetReview();
              });
              _loadActiveBomLabel();
            },
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
          onChanged: (_) => setState(_resetReview),
        ),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          controller: _labourCost,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(
            labelText: 'Labour Cost',
            prefixText: '₹ ',
          ),
          onChanged: (_) => setState(_resetReview),
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
    final qty = double.tryParse(_productionQty.text.trim()) ?? 0;

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
          'Materials Required',
          style: Theme.of(context).textTheme.titleSmall,
        ),
        const SizedBox(height: AppSpacing.sm),
        ..._requirements.map(_requirementCard),
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
                  _costing!['material_cost'] ??
                      _costing!['total_material_cost'],
                ),
                _costRow('Labour', _labourCost.text),
                _costRow('Transport', _transportCost.text),
                _costRow('Other', _otherCost.text),
                const Divider(),
                _costRow(
                  'Total / unit',
                  _costing!['cost_per_unit'] ?? _costing!['unit_cost'],
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

  Widget _requirementCard(Map<String, dynamic> row) {
    final available = double.tryParse('${row['available_stock']}') ?? 0;
    final required = double.tryParse('${row['required_quantity']}') ?? 0;
    final shortage = double.tryParse('${row['shortage_quantity']}') ?? 0;
    final formQty = row['formulation_quantity'] ?? row['required_quantity'];
    final formUnit = '${row['formulation_unit'] ?? row['unit'] ?? ''}'.trim();
    final invUnit =
        '${row['inventory_unit'] ?? row['unit'] ?? ''}'.trim();
    final name = '${row['material_name'] ?? row['name'] ?? 'Material'}';
    final status = shortage > 0 && row['is_optional'] != true
        ? 'Shortage'
        : (available <= (double.tryParse('${row['minimum_stock']}') ?? 0)
            ? 'Low Stock'
            : 'Available');

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
          const SizedBox(height: 4),
          Text('Required: $formQty $formUnit'),
          Text('Available: $available $invUnit'),
          Text(
            'Balance after: ${row['balance_after'] ?? (available - required)} $invUnit',
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
