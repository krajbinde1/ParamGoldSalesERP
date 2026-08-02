import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/api_errors.dart';
import '../../../../core/design/app_spacing.dart';
import '../../../../core/network/network_guard.dart';
import '../../../../core/storage/session_store.dart';
import '../../../../core/widgets/design/pg_card.dart';
import '../../../../core/widgets/design/pg_empty_state.dart';
import '../../../auth/providers/auth_controller.dart';
import '../../api/raw_material_inward_api.dart';

class RawMaterialInwardHubScreen extends StatefulWidget {
  const RawMaterialInwardHubScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<RawMaterialInwardHubScreen> createState() =>
      _RawMaterialInwardHubScreenState();
}

class _RawMaterialInwardHubScreenState extends State<RawMaterialInwardHubScreen> {
  late RawMaterialInwardApi _api;

  @override
  void initState() {
    super.initState();
    _api = RawMaterialInwardApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Raw Material Inward')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/production/inwards/new'),
        icon: const Icon(Icons.add),
        label: const Text('Create'),
      ),
      body: _InwardList(api: _api, status: null),
    );
  }
}

class _InwardList extends StatefulWidget {
  const _InwardList({required this.api, required this.status});
  final RawMaterialInwardApi api;
  final String? status;

  @override
  State<_InwardList> createState() => _InwardListState();
}

class _InwardListState extends State<_InwardList> {
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  void _reload() {
    _future = widget.api.list(status: widget.status);
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async {
        setState(_reload);
        await _future;
      },
      child: FutureBuilder<List<Map<String, dynamic>>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(message: errorMessage(snapshot.error));
          }
          final items = snapshot.data ?? const [];
          if (items.isEmpty) {
            return const PgEmptyState(message: 'No inward entries found.');
          }
          return ListView.builder(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            itemCount: items.length,
            itemBuilder: (context, index) {
              final item = items[index];
              return PgCard(
                margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                onTap: () => context.push('/production/inwards/${item['id']}'),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item['inward_number']?.toString() ?? '-',
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                    Text(
                      '${item['supplier_name'] ?? '-'} • ${item['inward_date'] ?? '-'}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      '${item['status_label'] ?? item['status']} • Items: ${item['total_items'] ?? 0} • Qty: ${item['total_quantity'] ?? item['total_accepted_qty'] ?? 0}',
                      style: Theme.of(context).textTheme.bodySmall,
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
}

class NewRawMaterialInwardScreen extends StatefulWidget {
  const NewRawMaterialInwardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<NewRawMaterialInwardScreen> createState() =>
      _NewRawMaterialInwardScreenState();
}

class _NewRawMaterialInwardScreenState extends State<NewRawMaterialInwardScreen> {
  late final RawMaterialInwardApi _api;
  final _invoiceCtrl = TextEditingController();
  final _remarksCtrl = TextEditingController();
  DateTime _inwardDate = DateTime.now();
  Map<String, dynamic>? _supplier;
  String? _attachmentPath;
  bool _saving = false;
  final List<_InwardLineDraft> _lines = [_InwardLineDraft()];

  @override
  void initState() {
    super.initState();
    _api = RawMaterialInwardApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    );
  }

  @override
  void dispose() {
    _invoiceCtrl.dispose();
    _remarksCtrl.dispose();
    super.dispose();
  }

  Future<void> _pickSupplier() async {
    final selected = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _SearchSheet(
        title: 'Select Supplier',
        onSearch: _api.searchSuppliers,
        labelBuilder: (item) => item['supplier_name']?.toString() ?? '-',
      ),
    );
    if (selected != null) {
      setState(() => _supplier = selected);
    }
  }

  Future<void> _pickMaterial(int index) async {
    final selected = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _SearchSheet(
        title: 'Select Material',
        onSearch: _api.searchRawMaterials,
        labelBuilder: (item) =>
            '${item['material_code']} — ${item['material_name']} (Stock: ${item['current_stock']})',
      ),
    );
    if (selected != null) {
      setState(() {
        _lines[index].material = selected;
        _lines[index].unit = selected['unit']?.toString();
        _lines[index].currentStock =
            double.tryParse('${selected['current_stock'] ?? 0}') ?? 0;
        _lines[index].currentAverageRate =
            double.tryParse('${selected['average_rate'] ?? 0}') ?? 0;
        _lines[index].basicRate =
            double.tryParse('${selected['purchase_rate'] ?? 0}') ?? 0;
      });
    }
  }

  Future<void> _attachPhoto() async {
    final picker = ImagePicker();
    final photo =
        await picker.pickImage(source: ImageSource.camera, imageQuality: 70);
    if (photo == null) return;
    try {
      final path = await _api.uploadAttachment(photo.path);
      setState(() => _attachmentPath = path);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Attachment uploaded')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(errorMessage(e))),
        );
      }
    }
  }

  Map<String, dynamic> _payload() {
    return {
      'inward_date':
          '${_inwardDate.year.toString().padLeft(4, '0')}-${_inwardDate.month.toString().padLeft(2, '0')}-${_inwardDate.day.toString().padLeft(2, '0')}',
      'supplier_id': _supplier?['id'],
      'supplier_name': _supplier?['supplier_name'],
      'supplier_invoice_number': _invoiceCtrl.text.trim(),
      'remarks':
          _remarksCtrl.text.trim().isEmpty ? null : _remarksCtrl.text.trim(),
      'attachment_path': _attachmentPath,
      'items': _lines
          .where((l) => l.material != null)
          .map(
            (l) => {
              'raw_material_id': l.material!['id'],
              'inward_quantity': l.inwardQty,
              'basic_rate': l.basicRate,
              'discount_amount': l.discount,
              'freight_amount': l.freight,
              'other_charges': l.otherCharges,
              'gst_percentage': l.gstPercent,
              'remarks': l.remarks,
            },
          )
          .toList(),
    };
  }

  Future<void> _save({required bool post}) async {
    if (_saving) return;
    if (!await NetworkGuard.isOnline()) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text(NetworkGuard.offlineMessage)),
      );
      return;
    }
    if (_supplier == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please select a supplier')),
      );
      return;
    }
    if (_invoiceCtrl.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Supplier invoice number is required')),
      );
      return;
    }
    if (_lines.every((l) => l.material == null)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Add at least one raw material')),
      );
      return;
    }
    for (final line in _lines.where((l) => l.material != null)) {
      if (line.inwardQty <= 0 || line.basicRate <= 0) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Quantity and purchase rate must be greater than zero',
            ),
          ),
        );
        return;
      }
    }

    setState(() => _saving = true);
    try {
      // Backend store() already createAndPost — no separate draft/post step.
      final created = await _api.createDraft(_payload());
      if (!mounted) return;
      final id = int.tryParse('${created['id']}') ?? 0;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Inward created and posted to stock')),
      );
      if (id > 0) {
        context.go('/production/inwards/$id');
      } else {
        context.go('/production/inwards');
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(errorMessage(e))),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('New Raw Material Inward')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Inward Date'),
            subtitle: Text(
              '${_inwardDate.day}/${_inwardDate.month}/${_inwardDate.year}',
            ),
            onTap: () async {
              final picked = await showDatePicker(
                context: context,
                initialDate: _inwardDate,
                firstDate: DateTime(2020),
                lastDate: DateTime.now().add(const Duration(days: 1)),
              );
              if (picked != null) setState(() => _inwardDate = picked);
            },
          ),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Supplier Name'),
            subtitle:
                Text(_supplier?['supplier_name']?.toString() ?? 'Tap to select'),
            trailing: const Icon(Icons.search),
            onTap: _pickSupplier,
          ),
          TextFormField(
            controller: _invoiceCtrl,
            decoration:
                const InputDecoration(labelText: 'Supplier Invoice Number *'),
          ),
          TextFormField(
            controller: _remarksCtrl,
            decoration: const InputDecoration(labelText: 'Remarks'),
            maxLines: 2,
          ),
          const SizedBox(height: AppSpacing.sm),
          OutlinedButton.icon(
            onPressed: _attachPhoto,
            icon: const Icon(Icons.camera_alt_outlined),
            label: Text(
              _attachmentPath == null
                  ? 'Attach Invoice Photo'
                  : 'Attachment ready',
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          Text('Materials', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: AppSpacing.sm),
          ...List.generate(_lines.length, (index) {
            final line = _lines[index];
            return PgCard(
              margin: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          'Material ${index + 1}',
                          style: Theme.of(context).textTheme.titleSmall,
                        ),
                      ),
                      IconButton(
                        icon: const Icon(Icons.delete_outline),
                        onPressed: _lines.length == 1
                            ? null
                            : () => setState(() => _lines.removeAt(index)),
                      ),
                    ],
                  ),
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(
                      line.material == null
                          ? 'Select raw material'
                          : '${line.material!['material_code']} — ${line.material!['material_name']}',
                    ),
                    trailing: const Icon(Icons.search),
                    onTap: () => _pickMaterial(index),
                  ),
                  Text(
                    'Current Stock: ${line.currentStock.toStringAsFixed(3)} ${line.unit ?? ''}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  Text(
                    'Current Average Rate: ₹${line.currentAverageRate.toStringAsFixed(4)}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    decoration: const InputDecoration(
                      labelText: 'Inward Quantity *',
                    ),
                    keyboardType: TextInputType.number,
                    onChanged: (v) {
                      line.inwardQty = double.tryParse(v) ?? 0;
                      setState(() {});
                    },
                  ),
                  TextFormField(
                    decoration: const InputDecoration(
                      labelText: 'Purchase Rate *',
                      prefixText: '₹ ',
                    ),
                    keyboardType: TextInputType.number,
                    initialValue:
                        line.basicRate > 0 ? line.basicRate.toString() : null,
                    onChanged: (v) {
                      line.basicRate = double.tryParse(v) ?? 0;
                      setState(() {});
                    },
                  ),
                  TextFormField(
                    decoration: const InputDecoration(
                      labelText: 'Discount Amount',
                      prefixText: '₹ ',
                    ),
                    keyboardType: TextInputType.number,
                    initialValue: '0',
                    onChanged: (v) {
                      line.discount = double.tryParse(v) ?? 0;
                      setState(() {});
                    },
                  ),
                  TextFormField(
                    decoration: const InputDecoration(
                      labelText: 'Freight Amount',
                      prefixText: '₹ ',
                    ),
                    keyboardType: TextInputType.number,
                    initialValue: '0',
                    onChanged: (v) {
                      line.freight = double.tryParse(v) ?? 0;
                      setState(() {});
                    },
                  ),
                  TextFormField(
                    decoration: const InputDecoration(
                      labelText: 'Other Charges',
                      prefixText: '₹ ',
                    ),
                    keyboardType: TextInputType.number,
                    initialValue: '0',
                    onChanged: (v) {
                      line.otherCharges = double.tryParse(v) ?? 0;
                      setState(() {});
                    },
                  ),
                  TextFormField(
                    decoration: const InputDecoration(labelText: 'GST Percentage'),
                    keyboardType: TextInputType.number,
                    initialValue: '0',
                    onChanged: (v) {
                      line.gstPercent = double.tryParse(v) ?? 0;
                      setState(() {});
                    },
                  ),
                  TextFormField(
                    decoration: const InputDecoration(labelText: 'Remarks'),
                    onChanged: (v) => line.remarks = v.trim().isEmpty ? null : v.trim(),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(AppSpacing.sm),
                    decoration: BoxDecoration(
                      color: Theme.of(context).colorScheme.surfaceContainerHighest,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Cost Summary',
                          style: Theme.of(context).textTheme.labelLarge,
                        ),
                        Text('Basic Value: ₹${line.basicValue.toStringAsFixed(2)}'),
                        Text('Discount: ₹${line.discount.toStringAsFixed(2)}'),
                        Text('Other Taxable Charges: ₹${line.otherCharges.toStringAsFixed(2)}'),
                        Text('Taxable Amount: ₹${line.taxable.toStringAsFixed(2)}'),
                        Text('Freight Charges: ₹${line.freight.toStringAsFixed(2)}'),
                        Text('GST Amount: ₹${line.gstAmount.toStringAsFixed(2)}'),
                        Text(
                          'Effective Inventory Value: ₹${line.effectiveInventoryValue.toStringAsFixed(2)}',
                        ),
                        Text('Effective Rate: ₹${line.effectiveRate.toStringAsFixed(4)}'),
                      ],
                    ),
                  ),
                ],
              ),
            );
          }),
          TextButton.icon(
            onPressed: () => setState(() => _lines.add(_InwardLineDraft())),
            icon: const Icon(Icons.add),
            label: const Text('Add Material'),
          ),
          const SizedBox(height: AppSpacing.md),
          PgCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Inward Summary', style: Theme.of(context).textTheme.titleSmall),
                Text('Total Materials: ${_lines.where((l) => l.material != null).length}'),
                Text('Total Inward Qty: ${_formTotalQty.toStringAsFixed(3)}'),
                Text('Total Basic Value: ₹${_formTotalBasic.toStringAsFixed(2)}'),
                Text('Total Discount: ₹${_formTotalDiscount.toStringAsFixed(2)}'),
                Text('Total Freight: ₹${_formTotalFreight.toStringAsFixed(2)}'),
                Text('Total Other Charges: ₹${_formTotalOther.toStringAsFixed(2)}'),
                Text('Total Taxable: ₹${_formTotalTaxable.toStringAsFixed(2)}'),
                Text('Total GST: ₹${_formTotalGst.toStringAsFixed(2)}'),
                Text(
                  'Grand Total: ₹${_formGrandTotal.toStringAsFixed(2)}',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          FilledButton(
            onPressed: _saving ? null : () => _save(post: true),
            child: Text(_saving ? 'Creating…' : 'Create Inward'),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextButton(
            onPressed: _saving ? null : () => context.pop(),
            child: const Text('Cancel'),
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            'Create posts stock immediately. Weighted average rate is calculated on the server.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }

  double get _formTotalQty =>
      _lines.fold(0.0, (sum, l) => sum + (l.material == null ? 0 : l.inwardQty));
  double get _formTotalBasic =>
      _lines.fold(0.0, (sum, l) => sum + (l.material == null ? 0 : l.basicValue));
  double get _formTotalDiscount =>
      _lines.fold(0.0, (sum, l) => sum + (l.material == null ? 0 : l.discount));
  double get _formTotalFreight =>
      _lines.fold(0.0, (sum, l) => sum + (l.material == null ? 0 : l.freight));
  double get _formTotalOther =>
      _lines.fold(0.0, (sum, l) => sum + (l.material == null ? 0 : l.otherCharges));
  double get _formTotalTaxable =>
      _lines.fold(0.0, (sum, l) => sum + (l.material == null ? 0 : l.taxable));
  double get _formTotalGst =>
      _lines.fold(0.0, (sum, l) => sum + (l.material == null ? 0 : l.gstAmount));
  double get _formGrandTotal =>
      _lines.fold(0.0, (sum, l) => sum + (l.material == null ? 0 : l.totalAmount));
}

class RawMaterialInwardDetailScreen extends StatefulWidget {
  const RawMaterialInwardDetailScreen({
    super.key,
    required this.auth,
    required this.inwardId,
  });
  final AuthController auth;
  final int inwardId;

  @override
  State<RawMaterialInwardDetailScreen> createState() =>
      _RawMaterialInwardDetailScreenState();
}

class _RawMaterialInwardDetailScreenState
    extends State<RawMaterialInwardDetailScreen> {
  late Future<Map<String, dynamic>> _future;
  late RawMaterialInwardApi _api;

  @override
  void initState() {
    super.initState();
    _api = RawMaterialInwardApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    );
    _future = _api.detail(widget.inwardId);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Inward Details')),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return PgErrorState(message: errorMessage(snapshot.error));
          }
          final data = snapshot.data ?? {};
          final items = (data['items'] as List?)
                  ?.map((e) => Map<String, dynamic>.from(e as Map))
                  .toList() ??
              const [];
          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              Text(
                data['inward_number']?.toString() ?? '-',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              Text('${data['status_label']} • ${data['inward_date']}'),
              Text('Supplier: ${data['supplier_name']}'),
              Text('Invoice: ${data['supplier_invoice_number'] ?? '-'}'),
              const SizedBox(height: AppSpacing.md),
              ...items.map(
                (item) => PgCard(
                  margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${item['material_code']} — ${item['material_name']}',
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                      Text(
                        'Qty ${item['inward_quantity'] ?? item['accepted_quantity'] ?? 0} ${item['unit'] ?? ''}',
                      ),
                      if (item['stock_before'] != null)
                        Text(
                          'Stock ${item['stock_before']} → ${item['stock_after']}',
                        ),
                      if (item['effective_unit_rate'] != null)
                        Text(
                          'Effective ₹${item['effective_unit_rate']} | Avg ₹${item['old_average_rate']} → ₹${item['new_average_rate']}',
                        ),
                    ],
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _InwardLineDraft {
  Map<String, dynamic>? material;
  String? unit;
  String? remarks;
  double currentStock = 0;
  double currentAverageRate = 0;
  double inwardQty = 0;
  double basicRate = 0;
  double discount = 0;
  double freight = 0;
  double otherCharges = 0;
  double gstPercent = 0;

  double get basicValue => inwardQty * basicRate;

  double get taxable => basicValue - discount + otherCharges;

  double get gstAmount => taxable * gstPercent / 100;

  double get effectiveInventoryValue => taxable + freight + gstAmount;

  double get effectiveRate =>
      inwardQty > 0 ? effectiveInventoryValue / inwardQty : 0;

  double get totalAmount => effectiveInventoryValue;

  double get expectedAverageRate {
    if (inwardQty <= 0) return currentAverageRate;
    final newStock = currentStock + inwardQty;
    if (currentStock <= 0 || newStock <= 0) return effectiveRate;
    return ((currentStock * currentAverageRate) + (inwardQty * effectiveRate)) /
        newStock;
  }
}

class _SearchSheet extends StatefulWidget {
  const _SearchSheet({
    required this.title,
    required this.onSearch,
    required this.labelBuilder,
  });

  final String title;
  final Future<List<Map<String, dynamic>>> Function(String q) onSearch;
  final String Function(Map<String, dynamic> item) labelBuilder;

  @override
  State<_SearchSheet> createState() => _SearchSheetState();
}

class _SearchSheetState extends State<_SearchSheet> {
  final _ctrl = TextEditingController();
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.onSearch('');
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.75,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Text(widget.title, style: Theme.of(context).textTheme.titleMedium),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
              child: TextField(
                controller: _ctrl,
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search),
                  hintText: 'Search...',
                ),
                onChanged: (v) => setState(() => _future = widget.onSearch(v)),
              ),
            ),
            Expanded(
              child: FutureBuilder<List<Map<String, dynamic>>>(
                future: _future,
                builder: (context, snapshot) {
                  if (snapshot.connectionState == ConnectionState.waiting) {
                    return const Center(child: CircularProgressIndicator());
                  }
                  final items = snapshot.data ?? const [];
                  return ListView.builder(
                    itemCount: items.length,
                    itemBuilder: (context, index) {
                      final item = items[index];
                      return ListTile(
                        title: Text(widget.labelBuilder(item)),
                        onTap: () => Navigator.pop(context, item),
                      );
                    },
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
