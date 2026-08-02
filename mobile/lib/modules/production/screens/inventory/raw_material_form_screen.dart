import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/api_errors.dart';
import '../../../../core/design/app_spacing.dart';
import '../../../../core/storage/session_store.dart';
import '../../../../core/widgets/design/pg_empty_state.dart';
import '../../../../core/widgets/role_shell_widgets.dart';
import '../../../auth/providers/auth_controller.dart';
import '../../api/inventory_production_api.dart';

/// Create / Edit Raw Material — fields match Filament RawMaterialForm.
class RawMaterialFormScreen extends StatefulWidget {
  const RawMaterialFormScreen({
    super.key,
    required this.auth,
    this.materialId,
  });

  final AuthController auth;
  final int? materialId;

  bool get isEdit => materialId != null && materialId! > 0;

  @override
  State<RawMaterialFormScreen> createState() => _RawMaterialFormScreenState();
}

class _RawMaterialFormScreenState extends State<RawMaterialFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameCtrl = TextEditingController();
  final _codeCtrl = TextEditingController();
  final _minStockCtrl = TextEditingController(text: '0');
  final _remarksCtrl = TextEditingController();
  final _openingQtyCtrl = TextEditingController(text: '0');
  final _openingValueCtrl = TextEditingController(text: '0');

  String? _unit;
  bool _batchTracking = false;
  bool _expiryTracking = false;
  bool _status = true;
  DateTime _openingDate = DateTime.now();
  bool _loading = true;
  bool _saving = false;
  String? _error;
  Map<String, String> _unitOptions = const {
    'Kg': 'Kg',
    'Gram': 'Gram',
    'Litre': 'Litre',
    'Ml': 'Millilitre',
    'Nos': 'Nos',
    'Piece': 'Piece',
    'Bag': 'Bag',
    'Packet': 'Packet',
    'Box': 'Box',
    'Drum': 'Drum',
    'Ton': 'Ton',
    'Bottle': 'Bottle',
  };

  final _inr = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 4,
  );

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    _openingQtyCtrl.addListener(_onOpeningChanged);
    _openingValueCtrl.addListener(_onOpeningChanged);
    _bootstrap();
  }

  @override
  void dispose() {
    _openingQtyCtrl.removeListener(_onOpeningChanged);
    _openingValueCtrl.removeListener(_onOpeningChanged);
    _nameCtrl.dispose();
    _codeCtrl.dispose();
    _minStockCtrl.dispose();
    _remarksCtrl.dispose();
    _openingQtyCtrl.dispose();
    _openingValueCtrl.dispose();
    super.dispose();
  }

  void _onOpeningChanged() {
    if (mounted) setState(() {});
  }

  /// Same Effective Rate display as Filament Placeholder `opening_effective_rate`
  /// (MaterialInwardCosting effective_unit_rate = inventory value ÷ qty).
  String get _effectiveRateDisplay {
    final qty = double.tryParse(_openingQtyCtrl.text.trim());
    final value = double.tryParse(_openingValueCtrl.text.trim());
    if (qty == null || value == null || qty <= 0 || value <= 0) {
      return '—';
    }
    return _inr.format(value / qty);
  }

  Future<void> _bootstrap() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      if (widget.isEdit) {
        final data = await _api.getRawMaterial(widget.materialId!);
        _nameCtrl.text = '${data['material_name'] ?? ''}';
        _codeCtrl.text = '${data['material_code'] ?? data['code'] ?? ''}';
        _unit = '${data['unit'] ?? ''}';
        _minStockCtrl.text = '${data['minimum_stock'] ?? 0}';
        _batchTracking = data['batch_tracking_enabled'] == true;
        _expiryTracking = data['expiry_tracking_enabled'] == true;
        _status = data['status'] != false;
        _remarksCtrl.text = '${data['remarks'] ?? ''}';
      } else {
        // Pull unit options from master list meta when available.
        final masters = await _api.listRawMaterialMasters(page: 1);
        final meta = masters['meta'] is Map
            ? Map<String, dynamic>.from(masters['meta'] as Map)
            : const <String, dynamic>{};
        final units = meta['unit_options'];
        if (units is Map && units.isNotEmpty) {
          _unitOptions = units.map(
            (k, v) => MapEntry('$k', '$v'),
          );
        }
        _unit ??= _unitOptions.keys.first;
      }
      if (_unit == null || _unit!.isEmpty) {
        _unit = _unitOptions.keys.first;
      }
    } catch (e) {
      _error = errorMessage(e);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    try {
      final payload = <String, dynamic>{
        'material_name': _nameCtrl.text.trim(),
        'unit': _unit,
        'minimum_stock': double.tryParse(_minStockCtrl.text.trim()) ?? 0,
        'batch_tracking_enabled': _batchTracking,
        'expiry_tracking_enabled': _expiryTracking,
        'status': _status,
        'remarks': _remarksCtrl.text.trim().isEmpty
            ? null
            : _remarksCtrl.text.trim(),
      };

      if (!widget.isEdit) {
        final qty = double.tryParse(_openingQtyCtrl.text.trim()) ?? 0;
        final value = double.tryParse(_openingValueCtrl.text.trim()) ?? 0;
        payload.addAll({
          'opening_stock_quantity': qty,
          'opening_stock_value': value,
          'opening_date': DateFormat('yyyy-MM-dd').format(_openingDate),
        });
        await _api.createRawMaterial(payload);
      } else {
        await _api.updateRawMaterial(widget.materialId!, payload);
      }

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            widget.isEdit
                ? 'Raw material updated'
                : 'Raw material created',
          ),
        ),
      );
      context.pop(true);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(e))),
      );
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _pickOpeningDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _openingDate,
      firstDate: DateTime(2020),
      lastDate: DateTime.now().add(const Duration(days: 1)),
    );
    if (picked != null) setState(() => _openingDate = picked);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: widget.isEdit ? 'Edit Raw Material' : 'Add Raw Material',
        auth: widget.auth,
      ),
      body: _loading
          ? const PgLoadingState()
          : _error != null
              ? PgErrorState(message: _error!, onRetry: _bootstrap)
              : Form(
                  key: _formKey,
                  child: ListView(
                    padding: const EdgeInsets.all(AppSpacing.screenPadding),
                    children: [
                      Text(
                        'Material Details',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _codeCtrl,
                        enabled: false,
                        decoration: const InputDecoration(
                          labelText: 'Material Code',
                          hintText: 'Generated automatically when saved',
                        ),
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _nameCtrl,
                        decoration: const InputDecoration(
                          labelText: 'Material Name',
                        ),
                        validator: (v) =>
                            (v == null || v.trim().isEmpty) ? 'Required' : null,
                      ),
                      const SizedBox(height: 12),
                      DropdownButtonFormField<String>(
                        value: _unitOptions.containsKey(_unit) ? _unit : null,
                        decoration: const InputDecoration(labelText: 'Unit'),
                        items: [
                          for (final entry in _unitOptions.entries)
                            DropdownMenuItem(
                              value: entry.key,
                              child: Text(entry.value),
                            ),
                        ],
                        onChanged: (v) => setState(() => _unit = v),
                        validator: (v) =>
                            (v == null || v.isEmpty) ? 'Required' : null,
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _minStockCtrl,
                        keyboardType: const TextInputType.numberWithOptions(
                          decimal: true,
                        ),
                        decoration: const InputDecoration(
                          labelText: 'Minimum Stock Level',
                        ),
                        validator: (v) {
                          final n = double.tryParse('${v ?? ''}');
                          if (n == null || n < 0) return 'Enter a valid amount';
                          return null;
                        },
                      ),
                      SwitchListTile(
                        contentPadding: EdgeInsets.zero,
                        title: const Text('Batch Tracking'),
                        value: _batchTracking,
                        onChanged: (v) => setState(() => _batchTracking = v),
                      ),
                      SwitchListTile(
                        contentPadding: EdgeInsets.zero,
                        title: const Text('Expiry Tracking'),
                        value: _expiryTracking,
                        onChanged: (v) => setState(() => _expiryTracking = v),
                      ),
                      SwitchListTile(
                        contentPadding: EdgeInsets.zero,
                        title: const Text('Active'),
                        value: _status,
                        onChanged: (v) => setState(() => _status = v),
                      ),
                      TextFormField(
                        controller: _remarksCtrl,
                        maxLines: 2,
                        decoration: const InputDecoration(
                          labelText: 'Remarks',
                        ),
                      ),
                      if (!widget.isEdit) ...[
                        const SizedBox(height: 24),
                        Text(
                          'Opening Stock',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Optional. Enter Opening Stock Quantity greater than zero to post opening stock. Leave as 0 to create without stock.',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          controller: _openingQtyCtrl,
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          decoration: InputDecoration(
                            labelText: 'Opening Stock Quantity',
                            suffixText: (_unit == null || _unit!.isEmpty)
                                ? null
                                : _unit,
                          ),
                          validator: (v) {
                            final n = double.tryParse('${v ?? ''}');
                            if (n == null || n.isNaN || n < 0) {
                              return 'Enter a valid quantity (≥ 0)';
                            }
                            if (n > 0 &&
                                (_unit == null || _unit!.trim().isEmpty)) {
                              return 'Select Unit before entering quantity';
                            }
                            return null;
                          },
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          controller: _openingValueCtrl,
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          decoration: const InputDecoration(
                            labelText: 'Opening Stock Value',
                            prefixText: '₹ ',
                          ),
                          validator: (v) {
                            final qty = double.tryParse(
                                  _openingQtyCtrl.text.trim(),
                                ) ??
                                0;
                            final n = double.tryParse('${v ?? ''}');
                            if (n == null || n.isNaN || n < 0) {
                              return 'Enter a valid value (≥ 0)';
                            }
                            if (qty > 0 && n <= 0) {
                              return 'Required when quantity > 0';
                            }
                            if (qty <= 0 && n > 0) {
                              return 'Must be 0 when quantity is 0';
                            }
                            return null;
                          },
                        ),
                        const SizedBox(height: 12),
                        InputDecorator(
                          decoration: const InputDecoration(
                            labelText: 'Effective Rate',
                            border: OutlineInputBorder(),
                          ),
                          child: Text(
                            _effectiveRateDisplay,
                            style: Theme.of(context).textTheme.titleMedium
                                ?.copyWith(fontWeight: FontWeight.w600),
                          ),
                        ),
                        const SizedBox(height: 12),
                        OutlinedButton(
                          onPressed: _pickOpeningDate,
                          child: Text(
                            'Opening Date ${DateFormat('dd-MM-yyyy').format(_openingDate)}',
                          ),
                        ),
                      ],
                      const SizedBox(height: 24),
                      FilledButton(
                        onPressed: _saving ? null : _save,
                        child: _saving
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            : Text(widget.isEdit ? 'Save' : 'Create'),
                      ),
                    ],
                  ),
                ),
    );
  }
}
