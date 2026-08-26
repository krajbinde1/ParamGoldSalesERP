import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../../orders/api/dealer_api.dart';
import '../../orders/api/product_api.dart';
import '../../orders/models/order_dealer.dart';
import '../../orders/models/product.dart';
import '../api/credit_note_api.dart';
import '../models/credit_note.dart';

class CreditNoteFormScreen extends StatefulWidget {
  const CreditNoteFormScreen({
    super.key,
    required this.auth,
    this.initial,
    this.managerMode = false,
  });

  final AuthController auth;
  final CreditNoteDetail? initial;
  final bool managerMode;

  @override
  State<CreditNoteFormScreen> createState() => _CreditNoteFormScreenState();
}

class _CreditNoteLineDraft {
  _CreditNoteLineDraft({
    required this.product,
    this.quantity = 1,
    this.rate = 0,
    this.originalRate = 0,
    this.revisedRate = 0,
    this.reason = '',
  });

  final Product product;
  double quantity;
  double rate;
  double originalRate;
  double revisedRate;
  String reason;

  double amount(String type) {
    if (type == 'rate_difference') {
      return (originalRate - revisedRate).abs() * quantity;
    }
    return quantity * rate;
  }
}

class _CreditNoteFormScreenState extends State<CreditNoteFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _billRefController = TextEditingController();
  final _remarksController = TextEditingController();

  String? _type;
  OrderDealer? _dealer;
  DateTime _date = DateTime.now();
  String? _photoPath;
  bool _submitting = false;
  final List<_CreditNoteLineDraft> _lines = [];

  late Future<List<OrderDealer>> _dealersFuture;
  late Future<List<Product>> _productsFuture;

  bool get _isEdit => widget.initial != null;
  bool get _isRateDifference => _type == 'rate_difference';

  @override
  void initState() {
    super.initState();
    final dio = ApiClient(
      SessionStore(),
      onUnauthorized: widget.auth.sessionExpired,
    ).dio;
    _dealersFuture = widget.managerMode
        ? _listDealers(dio, '/dealers')
        : DealerApi(dio).list();
    _productsFuture = widget.managerMode
        ? _listProducts(dio, '/manager/products')
        : ProductApi(dio).list();

    final initial = widget.initial;
    if (initial != null) {
      _type = initial.type;
      _dealer = initial.dealer;
      _billRefController.text = initial.billReference ?? '';
      _remarksController.text = initial.remarks ?? '';
      if (initial.creditNoteDate != null) {
        _date = initial.creditNoteDate!;
      }
      for (final item in initial.items) {
        _lines.add(
          _CreditNoteLineDraft(
            product: Product(
              id: item.productId,
              productCode: item.productCode ?? '',
              productName: item.productName,
              dealerPrice: item.rate ?? item.originalRate ?? 0,
              gstPercentage: 0,
              nosPerCase: 1,
              uom: item.uom,
            ),
            quantity: item.quantity,
            rate: item.rate ?? 0,
            originalRate: item.originalRate ?? 0,
            revisedRate: item.revisedRate ?? 0,
            reason: item.reason ?? '',
          ),
        );
      }
    }
  }

  @override
  void dispose() {
    _billRefController.dispose();
    _remarksController.dispose();
    super.dispose();
  }

  double get _total => _lines.fold(0, (sum, line) => sum + line.amount(_type ?? ''));

  Future<List<OrderDealer>> _listDealers(Dio dio, String path) async {
    try {
      final response = await dio.get(path);
      final body = response.data;
      if (body is! Map) return const [];
      final raw = body['data'] ?? body['dealers'];
      if (raw is! List) return const [];
      return raw
          .whereType<Map>()
          .map((item) => OrderDealer.fromJson(Map<String, dynamic>.from(item)))
          .where((dealer) => dealer.id > 0 && dealer.name.isNotEmpty)
          .toList();
    } on DioException {
      return const [];
    }
  }

  Future<List<Product>> _listProducts(Dio dio, String path) async {
    try {
      final response = await dio.get(path);
      final body = response.data;
      if (body is! Map) return const [];
      final raw = body['data'] ?? body['products'];
      if (raw is! List) return const [];
      return raw
          .whereType<Map>()
          .map((item) => Product.fromJson(Map<String, dynamic>.from(item)))
          .where((product) => product.id > 0 && product.productName.isNotEmpty)
          .toList();
    } on DioException {
      return const [];
    }
  }

  Future<void> _pickDealer() async {
    final dealers = await _dealersFuture;
    if (!mounted) return;
    final searchController = TextEditingController();
    final selected = await showModalBottomSheet<OrderDealer>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => StatefulBuilder(
        builder: (context, setModalState) {
          final query = searchController.text.trim().toLowerCase();
          final filtered = dealers.where((dealer) {
            if (query.isEmpty) return true;
            return dealer.name.toLowerCase().contains(query) ||
                (dealer.ownerName ?? '').toLowerCase().contains(query);
          }).toList();
          return Padding(
            padding: EdgeInsets.only(
              left: 16,
              right: 16,
              bottom: MediaQuery.viewInsetsOf(context).bottom + 16,
            ),
            child: SizedBox(
              height: 420,
              child: Column(
                children: [
                  TextField(
                    controller: searchController,
                    decoration: const InputDecoration(
                      hintText: 'Search dealer',
                      prefixIcon: Icon(Icons.search),
                    ),
                    onChanged: (_) => setModalState(() {}),
                  ),
                  const SizedBox(height: 12),
                  Expanded(
                    child: ListView.builder(
                      itemCount: filtered.length,
                      itemBuilder: (context, index) {
                        final dealer = filtered[index];
                        return ListTile(
                          title: Text(dealer.name),
                          subtitle: Text(dealer.village ?? dealer.mobile ?? ''),
                          onTap: () => Navigator.pop(context, dealer),
                        );
                      },
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
    searchController.dispose();
    if (selected != null) setState(() => _dealer = selected);
  }

  Future<void> _addOrEditLine([_CreditNoteLineDraft? existing]) async {
    final products = await _productsFuture;
    if (!mounted) return;
    Product? product = existing?.product;
    final qtyController = TextEditingController(
      text: existing == null ? '1' : '${existing.quantity}',
    );
    final rateController = TextEditingController(
      text: existing == null ? '' : '${existing.rate}',
    );
    final originalController = TextEditingController(
      text: existing == null ? '' : '${existing.originalRate}',
    );
    final revisedController = TextEditingController(
      text: existing == null ? '' : '${existing.revisedRate}',
    );
    final reasonController = TextEditingController(text: existing?.reason ?? '');
    final searchController = TextEditingController();

    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => StatefulBuilder(
        builder: (context, setModalState) {
          final query = searchController.text.trim().toLowerCase();
          final filtered = products.where((item) {
            if (query.isEmpty) return true;
            return item.matchesQuery(query);
          }).toList();
          return Padding(
            padding: EdgeInsets.only(
              left: 16,
              right: 16,
              bottom: MediaQuery.viewInsetsOf(context).bottom + 16,
            ),
            child: SizedBox(
              height: 560,
              child: ListView(
                children: [
                  Text(
                    existing == null ? 'Add product' : 'Edit product',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: searchController,
                    decoration: const InputDecoration(
                      hintText: 'Search product',
                      prefixIcon: Icon(Icons.search),
                    ),
                    onChanged: (_) => setModalState(() {}),
                  ),
                  const SizedBox(height: 8),
                  ...filtered.take(8).map(
                    (item) => ListTile(
                      selected: product?.id == item.id,
                      title: Text(item.productName),
                      subtitle: Text(
                        '${item.productCode} • ₹${item.dealerPrice}',
                      ),
                      onTap: () {
                        product = item;
                        if (!_isRateDifference && rateController.text.isEmpty) {
                          rateController.text = item.dealerPrice.toString();
                        }
                        if (_isRateDifference &&
                            originalController.text.isEmpty) {
                          originalController.text = item.dealerPrice.toString();
                        }
                        setModalState(() {});
                      },
                    ),
                  ),
                  TextField(
                    controller: qtyController,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(labelText: 'Quantity'),
                  ),
                  if (_isRateDifference) ...[
                    TextField(
                      controller: originalController,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      decoration: const InputDecoration(
                        labelText: 'Original Rate',
                        prefixText: '₹ ',
                      ),
                    ),
                    TextField(
                      controller: revisedController,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      decoration: const InputDecoration(
                        labelText: 'Revised Rate',
                        prefixText: '₹ ',
                      ),
                    ),
                  ] else
                    TextField(
                      controller: rateController,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      decoration: const InputDecoration(
                        labelText: 'Rate',
                        prefixText: '₹ ',
                      ),
                    ),
                  TextField(
                    controller: reasonController,
                    decoration: InputDecoration(
                      labelText: _isRateDifference
                          ? 'Reason / Remarks'
                          : 'Reason for return',
                    ),
                  ),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: () {
                      final selected = product;
                      final qty = double.tryParse(qtyController.text.trim()) ?? 0;
                      if (selected == null || qty <= 0) return;
                      Navigator.pop(context, true);
                    },
                    child: const Text('Save line'),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );

    if (saved == true && product != null) {
      final line = _CreditNoteLineDraft(
        product: product!,
        quantity: double.tryParse(qtyController.text.trim()) ?? 0,
        rate: double.tryParse(rateController.text.trim()) ?? 0,
        originalRate: double.tryParse(originalController.text.trim()) ?? 0,
        revisedRate: double.tryParse(revisedController.text.trim()) ?? 0,
        reason: reasonController.text.trim(),
      );
      setState(() {
        if (existing == null) {
          _lines.add(line);
        } else {
          final index = _lines.indexOf(existing);
          if (index >= 0) _lines[index] = line;
        }
      });
    }

    qtyController.dispose();
    rateController.dispose();
    originalController.dispose();
    revisedController.dispose();
    reasonController.dispose();
    searchController.dispose();
  }

  Future<void> _choosePhoto() async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Camera'),
              onTap: () => Navigator.pop(context, ImageSource.camera),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Gallery'),
              onTap: () => Navigator.pop(context, ImageSource.gallery),
            ),
          ],
        ),
      ),
    );
    if (source == null) return;
    final file = await ImagePicker().pickImage(source: source, imageQuality: 85);
    if (file != null) setState(() => _photoPath = file.path);
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate() || _submitting) return;
    if (_type == null || _dealer == null || _lines.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Select type, dealer, and at least one product.')),
      );
      return;
    }

    setState(() => _submitting = true);
    try {
      final dio = ApiClient(
        SessionStore(),
        onUnauthorized: widget.auth.sessionExpired,
      ).dio;
      final items = _lines
          .map(
            (line) => CreditNoteLine(
              productId: line.product.id,
              productName: line.product.productName,
              quantity: line.quantity,
              amount: line.amount(_type!),
              rate: line.rate,
              originalRate: line.originalRate,
              revisedRate: line.revisedRate,
              reason: line.reason,
            ).toPayload(_type!),
          )
          .toList();

      if (widget.managerMode && widget.initial != null) {
        await ManagerCreditNoteApi(dio).update(
          id: widget.initial!.id,
          type: _type!,
          dealerId: _dealer!.id,
          billReference: _billRefController.text.trim(),
          creditNoteDate: _date,
          items: items,
          remarks: _remarksController.text,
          documentPath: _photoPath,
        );
      } else {
        await CreditNoteApi(dio).submit(
          type: _type!,
          dealerId: _dealer!.id,
          billReference: _billRefController.text.trim(),
          creditNoteDate: _date,
          items: items,
          remarks: _remarksController.text,
          documentPath: _photoPath,
          creditNoteId: widget.initial?.id,
        );
      }

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            _isEdit
                ? 'Credit Note updated successfully.'
                : 'Credit Note submitted successfully.',
          ),
        ),
      );
      safePop(context, true);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('$error')));
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

    if (_type == null) {
      return PgPageScaffold(
        title: 'Credit Note Type',
        showBack: true,
        body: ListView(
          padding: const EdgeInsets.all(AppSpacing.screenPadding),
          children: [
            PgCard(
              onTap: () => setState(() => _type = 'sales_return'),
              child: const ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(Icons.assignment_return_outlined),
                title: Text('Sales Return'),
                subtitle: Text('Returned products with quantity and rate'),
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              onTap: () => setState(() => _type = 'rate_difference'),
              child: const ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(Icons.price_change_outlined),
                title: Text('Rate Difference'),
                subtitle: Text('Original vs revised rate for billed products'),
              ),
            ),
          ],
        ),
      );
    }

    return PgPageScaffold(
      title: _isEdit ? 'Edit Credit Note' : 'New Credit Note',
      showBack: true,
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.screenPadding),
          children: [
            PgCard(
              onTap: _isEdit ? null : () => setState(() => _type = null),
              child: Text(
                _isRateDifference ? 'Type: Rate Difference' : 'Type: Sales Return',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              onTap: _pickDealer,
              child: ListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Dealer'),
                subtitle: Text(_dealer?.name ?? 'Tap to choose a dealer'),
                trailing: const Icon(Icons.chevron_right_rounded),
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: TextFormField(
                controller: _billRefController,
                decoration: const InputDecoration(
                  labelText: 'Invoice / Bill Reference',
                  border: InputBorder.none,
                ),
                validator: (value) =>
                    (value == null || value.trim().isEmpty)
                    ? 'Bill reference is required.'
                    : null,
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              onTap: () async {
                final picked = await showDatePicker(
                  context: context,
                  initialDate: _date,
                  firstDate: DateTime(2020),
                  lastDate: DateTime.now(),
                );
                if (picked != null) setState(() => _date = picked);
              },
              child: ListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Credit Note Date'),
                subtitle: Text(DateFormat('d MMM yyyy').format(_date)),
                trailing: const Icon(Icons.calendar_today_outlined),
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            Row(
              children: [
                Text('Products', style: Theme.of(context).textTheme.titleMedium),
                const Spacer(),
                TextButton.icon(
                  onPressed: () => _addOrEditLine(),
                  icon: const Icon(Icons.add),
                  label: const Text('Add'),
                ),
              ],
            ),
            if (_lines.isEmpty)
              const PgCard(
                child: Text('Add at least one product line.'),
              )
            else
              ..._lines.map((line) {
                return PgCard(
                  margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                  onTap: () => _addOrEditLine(line),
                  child: ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(line.product.productName),
                    subtitle: Text(
                      _isRateDifference
                          ? 'Qty ${line.quantity} • ${currency.format(line.originalRate)} → ${currency.format(line.revisedRate)}'
                          : 'Qty ${line.quantity} × ${currency.format(line.rate)}',
                    ),
                    trailing: Text(currency.format(line.amount(_type!))),
                  ),
                );
              }),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: Text(
                'Amount: ${currency.format(_total)}',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: TextFormField(
                controller: _remarksController,
                maxLines: 3,
                decoration: const InputDecoration(
                  labelText: 'Remarks',
                  border: InputBorder.none,
                ),
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Supporting Document / Photo',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  if (_photoPath == null)
                    OutlinedButton.icon(
                      onPressed: _choosePhoto,
                      icon: const Icon(Icons.add_a_photo_outlined),
                      label: const Text('Add Photo'),
                    )
                  else ...[
                    ClipRRect(
                      borderRadius: BorderRadius.circular(12),
                      child: Image.file(
                        File(_photoPath!),
                        height: 160,
                        width: double.infinity,
                        fit: BoxFit.cover,
                      ),
                    ),
                    TextButton(
                      onPressed: () => setState(() => _photoPath = null),
                      child: const Text('Remove'),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.lg),
            FilledButton(
              onPressed: _submitting ? null : _submit,
              child: Text(_submitting ? 'Saving...' : 'Submit Credit Note'),
            ),
            const SizedBox(height: 24),
          ],
        ),
      ),
    );
  }
}
