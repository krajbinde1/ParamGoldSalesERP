import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/network/network_guard.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/company_transport_api.dart';

class CompanyTransportExpenseFormScreen extends StatefulWidget {
  const CompanyTransportExpenseFormScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<CompanyTransportExpenseFormScreen> createState() =>
      _CompanyTransportExpenseFormScreenState();
}

class _CompanyTransportExpenseFormScreenState
    extends State<CompanyTransportExpenseFormScreen> {
  final _amountCtrl = TextEditingController();
  final _paidToCtrl = TextEditingController();
  final _remarkCtrl = TextEditingController();
  final _otherExpenseCtrl = TextEditingController();
  DateTime _date = DateTime.now();
  String? _expenseType;
  String? _paymentMode;
  int? _vehicleId;
  Map<String, dynamic>? _selectedOrder;
  String? _attachmentPath;
  bool _saving = false;
  String? _formError;
  List<Map<String, dynamic>> _vehicles = const [];
  Map<String, String> _expenseTypes = const {};
  Map<String, String> _paymentModes = const {};

  CompanyTransportApi get _api => CompanyTransportApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  bool get _isOtherExpense => _expenseType == 'other';

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  @override
  void dispose() {
    _amountCtrl.dispose();
    _paidToCtrl.dispose();
    _remarkCtrl.dispose();
    _otherExpenseCtrl.dispose();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    try {
      final ledger = await _api.ledger();
      final lookups = Map<String, dynamic>.from(
        ledger['lookups'] as Map? ?? const {},
      );
      final vehicles = await _api.listVehicles();
      if (!mounted) return;
      setState(() {
        _expenseTypes = Map<String, String>.from(
          (lookups['expense_types'] as Map? ?? const {}).map(
            (key, value) => MapEntry('$key', '$value'),
          ),
        );
        _paymentModes = Map<String, String>.from(
          (lookups['payment_modes'] as Map? ?? const {}).map(
            (key, value) => MapEntry('$key', '$value'),
          ),
        );
        _vehicles = vehicles;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _formError = errorMessage(e));
    }
  }

  String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _pickAttachment() async {
    final photo = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      imageQuality: 70,
    );
    if (photo == null) return;
    try {
      final path = await _api.uploadAttachment(photo.path);
      if (!mounted) return;
      setState(() => _attachmentPath = path);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(e))),
      );
    }
  }

  Future<void> _pickRelatedOrder() async {
    final selected = await showModalBottomSheet<Map<String, dynamic>?>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _RelatedOrderSheet(
        api: _api,
        selectedId: int.tryParse('${_selectedOrder?['id'] ?? ''}'),
      ),
    );
    if (!mounted || selected == null) return;
    setState(() {
      _selectedOrder = selected.isEmpty ? null : selected;
    });
  }

  Future<void> _save() async {
    if (_saving) return;
    if (!await NetworkGuard.isOnline()) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text(NetworkGuard.offlineMessage)),
      );
      return;
    }
    final amount = double.tryParse(_amountCtrl.text.trim()) ?? 0;
    if (amount <= 0 ||
        _expenseType == null ||
        _paymentMode == null ||
        _paidToCtrl.text.trim().isEmpty) {
      setState(() => _formError =
          'Date, amount, expense type, paid to and payment mode are required.');
      return;
    }
    if (_isOtherExpense && _otherExpenseCtrl.text.trim().isEmpty) {
      setState(() => _formError = 'Specify the other expense type.');
      return;
    }
    setState(() {
      _saving = true;
      _formError = null;
    });
    try {
      await _api.createExpense({
        'transaction_date': _ymd(_date),
        'amount': amount,
        'expense_type': _expenseType,
        if (_isOtherExpense)
          'expense_other_description': _otherExpenseCtrl.text.trim(),
        'vehicle_id': _vehicleId,
        'paid_to': _paidToCtrl.text.trim(),
        'order_id': _selectedOrder == null
            ? null
            : int.tryParse('${_selectedOrder?['id'] ?? ''}'),
        'payment_mode': _paymentMode,
        'remark':
            _remarkCtrl.text.trim().isEmpty ? null : _remarkCtrl.text.trim(),
        'attachment_path': _attachmentPath,
      });
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _formError = errorMessage(e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final orderLabel = _selectedOrder == null
        ? 'None'
        : '${_selectedOrder?['label'] ?? _selectedOrder?['order_no'] ?? 'Selected'}';

    return Scaffold(
      appBar: RoleAppBar(
        title: 'Add Transport Expense',
        auth: widget.auth,
        showBack: true,
        onBack: () => smartBack(context),
      ),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          if (_formError != null) ...[
            Text(_formError!, style: const TextStyle(color: Colors.red)),
            const SizedBox(height: 12),
          ],
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Date'),
            subtitle: Text(DateFormat('dd MMM yyyy').format(_date)),
            trailing: const Icon(Icons.calendar_today_outlined),
            onTap: () async {
              final picked = await showDatePicker(
                context: context,
                initialDate: _date,
                firstDate: DateTime(2024),
                lastDate: DateTime.now(),
              );
              if (picked != null) setState(() => _date = picked);
            },
          ),
          TextField(
            controller: _amountCtrl,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(
              labelText: 'Amount *',
              prefixText: '₹ ',
            ),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            value: _expenseType,
            decoration: const InputDecoration(labelText: 'Expense Type *'),
            items: _expenseTypes.entries
                .map(
                  (e) => DropdownMenuItem(value: e.key, child: Text(e.value)),
                )
                .toList(),
            onChanged: (value) => setState(() => _expenseType = value),
          ),
          if (_isOtherExpense) ...[
            const SizedBox(height: 12),
            TextField(
              controller: _otherExpenseCtrl,
              decoration: const InputDecoration(
                labelText: 'Specify Other Expense *',
              ),
            ),
          ],
          const SizedBox(height: 12),
          DropdownButtonFormField<int?>(
            value: _vehicleId,
            decoration: const InputDecoration(labelText: 'Vehicle No.'),
            items: [
              const DropdownMenuItem<int?>(
                value: null,
                child: Text('Select vehicle'),
              ),
              ..._vehicles.map(
                (vehicle) => DropdownMenuItem<int?>(
                  value: int.tryParse('${vehicle['id']}'),
                  child: Text(
                    '${vehicle['vehicle_number'] ?? vehicle['label'] ?? ''}',
                  ),
                ),
              ),
            ],
            onChanged: (value) => setState(() => _vehicleId = value),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _paidToCtrl,
            decoration: const InputDecoration(labelText: 'Paid To *'),
          ),
          const SizedBox(height: 12),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Related Order (optional)'),
            subtitle: Text(orderLabel),
            trailing: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (_selectedOrder != null)
                  IconButton(
                    tooltip: 'Clear',
                    onPressed: () => setState(() => _selectedOrder = null),
                    icon: const Icon(Icons.clear),
                  ),
                const Icon(Icons.search),
              ],
            ),
            onTap: _pickRelatedOrder,
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            value: _paymentMode,
            decoration: const InputDecoration(labelText: 'Payment Mode *'),
            items: _paymentModes.entries
                .map(
                  (e) => DropdownMenuItem(value: e.key, child: Text(e.value)),
                )
                .toList(),
            onChanged: (value) => setState(() => _paymentMode = value),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _remarkCtrl,
            maxLines: 3,
            decoration: const InputDecoration(labelText: 'Remark'),
          ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: _pickAttachment,
            icon: const Icon(Icons.photo_camera_outlined),
            label: Text(
              _attachmentPath == null
                  ? 'Upload bill / photo'
                  : 'Attachment uploaded',
            ),
          ),
          const SizedBox(height: 24),
          FilledButton(
            onPressed: _saving ? null : _save,
            child: Text(_saving ? 'Saving…' : 'Save Expense'),
          ),
        ],
      ),
    );
  }
}

class _RelatedOrderSheet extends StatefulWidget {
  const _RelatedOrderSheet({required this.api, this.selectedId});

  final CompanyTransportApi api;
  final int? selectedId;

  @override
  State<_RelatedOrderSheet> createState() => _RelatedOrderSheetState();
}

class _RelatedOrderSheetState extends State<_RelatedOrderSheet> {
  final _searchCtrl = TextEditingController();
  DateTime? _orderDate;
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<List<Map<String, dynamic>>> _load() {
    return widget.api.searchOrders(
      search: _searchCtrl.text.trim(),
      orderDate: _orderDate == null ? null : _ymd(_orderDate!),
    );
  }

  void _reload() {
    setState(() => _future = _load());
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.8,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Text(
                'Select Related Order',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
              child: Column(
                children: [
                  TextField(
                    controller: _searchCtrl,
                    decoration: const InputDecoration(
                      prefixIcon: Icon(Icons.search),
                      hintText: 'Search order no. or dealer name',
                    ),
                    onChanged: (_) => _reload(),
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: () async {
                            final picked = await showDatePicker(
                              context: context,
                              initialDate: _orderDate ?? DateTime.now(),
                              firstDate: DateTime(2024),
                              lastDate: DateTime.now(),
                            );
                            if (picked == null) return;
                            setState(() => _orderDate = picked);
                            _reload();
                          },
                          icon: const Icon(Icons.event_outlined),
                          label: Text(
                            _orderDate == null
                                ? 'Order Date'
                                : DateFormat('dd MMM yyyy').format(_orderDate!),
                          ),
                        ),
                      ),
                      if (_orderDate != null)
                        IconButton(
                          tooltip: 'Clear date',
                          onPressed: () {
                            setState(() => _orderDate = null);
                            _reload();
                          },
                          icon: const Icon(Icons.clear),
                        ),
                    ],
                  ),
                ],
              ),
            ),
            TextButton(
              onPressed: () => Navigator.pop(context, <String, dynamic>{}),
              child: const Text('No related order'),
            ),
            Expanded(
              child: FutureBuilder<List<Map<String, dynamic>>>(
                future: _future,
                builder: (context, snapshot) {
                  if (snapshot.connectionState == ConnectionState.waiting) {
                    return const Center(child: CircularProgressIndicator());
                  }
                  if (snapshot.hasError) {
                    return Center(child: Text(errorMessage(snapshot.error!)));
                  }
                  final items = snapshot.data ?? const [];
                  if (items.isEmpty) {
                    return const Center(
                      child: Text('No dispatched transport orders found.'),
                    );
                  }
                  return ListView.builder(
                    itemCount: items.length,
                    itemBuilder: (context, index) {
                      final item = items[index];
                      final id = int.tryParse('${item['id'] ?? ''}');
                      final selected = id != null && id == widget.selectedId;
                      return ListTile(
                        title: Text('${item['label'] ?? item['order_no'] ?? ''}'),
                        subtitle: Text(
                          [
                            '${item['order_date_label'] ?? ''}',
                            '${item['dealer_name'] ?? ''}',
                          ].where((part) => part.trim().isNotEmpty).join(' • '),
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                color: AppColors.textSecondary,
                              ),
                        ),
                        trailing: selected ? const Icon(Icons.check) : null,
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
