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
import '../../orders/models/order_line_item.dart';
import '../../orders/models/product.dart';
import '../../orders/widgets/order_line_item_card.dart';
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
  final _itemKeys = <int, GlobalKey>{};
  final _money = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 2,
  );

  String? _type;
  String? _moveTo;
  OrderDealer? _dealer;
  OrderDealer? _destinationDealer;
  DateTime _date = DateTime.now();
  String? _photoPath;
  bool _submitting = false;
  final List<_CreditNoteLineDraft> _rateLines = [];
  final List<OrderLineItem> _returnItems = [];

  late Future<List<OrderDealer>> _dealersFuture;
  late Future<List<Product>> _productsFuture;

  bool get _isEdit => widget.initial != null;
  bool get _isRateDifference => _type == 'rate_difference';
  bool get _isSalesReturn => _type == 'sales_return';
  bool get _moveToDealer => _moveTo == 'dealer';

  List<String> get _allowedTypes {
    if (widget.managerMode && !_isEdit) {
      return const ['rate_difference'];
    }
    if (!widget.managerMode && !_isEdit) {
      return const ['sales_return'];
    }
    return [if (_type != null) _type!];
  }

  bool get _canChangeType => !_isEdit && _allowedTypes.length > 1;

  OrderSummaryTotals get _returnSummary =>
      OrderSummaryTotals.fromItems(_returnItems);

  double get _total {
    if (_isSalesReturn) return _returnSummary.grandTotal;
    return _rateLines.fold(0, (sum, line) => sum + line.amount(_type ?? ''));
  }

  bool get _canSubmitReturn =>
      _dealer != null &&
      _moveTo != null &&
      (!_moveToDealer || _destinationDealer != null) &&
      _returnItems.isNotEmpty &&
      _returnItems.every((item) => item.isValid);

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
      _moveTo = initial.moveTo;
      _dealer = initial.dealer;
      _destinationDealer = initial.destinationDealer;
      _billRefController.text = initial.billReference ?? '';
      _remarksController.text = initial.remarks ?? '';
      if (initial.creditNoteDate != null) {
        _date = initial.creditNoteDate!;
      }
      if (initial.type == 'sales_return') {
        for (final item in initial.items) {
          final line = initial.lineAsOrderItem(item);
          if (line != null) {
            _returnItems.add(line);
            _itemKeys[line.productId] = GlobalKey();
          }
        }
      } else {
        for (final item in initial.items) {
          _rateLines.add(
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
    } else if (widget.managerMode) {
      _type = 'rate_difference';
    } else {
      _type = 'sales_return';
    }
  }

  @override
  void dispose() {
    _billRefController.dispose();
    _remarksController.dispose();
    super.dispose();
  }

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

  Future<void> _pickDealer({required bool destination}) async {
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
            if (destination && _dealer != null && dealer.id == _dealer!.id) {
              return false;
            }
            if (query.isEmpty) return true;
            return dealer.name.toLowerCase().contains(query) ||
                (dealer.ownerName ?? '').toLowerCase().contains(query) ||
                (dealer.village ?? '').toLowerCase().contains(query) ||
                (dealer.mobile ?? '').contains(query);
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
                  Text(
                    destination ? 'Select destination dealer' : 'Select dealer',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: searchController,
                    decoration: const InputDecoration(
                      hintText: 'Search dealer',
                      prefixIcon: Icon(Icons.search),
                      border: OutlineInputBorder(),
                    ),
                    onChanged: (_) => setModalState(() {}),
                  ),
                  const SizedBox(height: 12),
                  Expanded(
                    child: filtered.isEmpty
                        ? const Center(child: Text('No dealers found.'))
                        : ListView.separated(
                            itemCount: filtered.length,
                            separatorBuilder: (_, _) =>
                                const Divider(height: 1),
                            itemBuilder: (context, index) {
                              final dealer = filtered[index];
                              return ListTile(
                                title: Text(dealer.name),
                                subtitle: Text(
                                  [
                                        dealer.ownerName,
                                        dealer.village,
                                        dealer.mobile,
                                      ]
                                      .whereType<String>()
                                      .where((v) => v.isNotEmpty)
                                      .join(' • '),
                                ),
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
    if (selected == null) return;
    setState(() {
      if (destination) {
        _destinationDealer = selected;
      } else {
        _dealer = selected;
        if (_destinationDealer?.id == selected.id) {
          _destinationDealer = null;
        }
      }
    });
  }

  Future<void> _openProductSelector() async {
    final products = await _productsFuture;
    if (!mounted) return;

    if (products.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Unable to load products. Please try again.'),
        ),
      );
      return;
    }

    final searchController = TextEditingController();
    final selected = await showModalBottomSheet<Product>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            final filtered = products
                .where((product) => product.matchesQuery(searchController.text))
                .toList();

            return Padding(
              padding: EdgeInsets.only(
                left: 16,
                right: 16,
                top: 8,
                bottom: MediaQuery.viewInsetsOf(context).bottom + 16,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    'Select Product',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: searchController,
                    decoration: const InputDecoration(
                      labelText: 'Search by product name or code',
                      prefixIcon: Icon(Icons.search),
                      border: OutlineInputBorder(),
                    ),
                    onChanged: (_) => setModalState(() {}),
                  ),
                  const SizedBox(height: 12),
                  Flexible(
                    child: filtered.isEmpty
                        ? const Padding(
                            padding: EdgeInsets.all(24),
                            child: Text('No products found.'),
                          )
                        : ListView.separated(
                            shrinkWrap: true,
                            itemCount: filtered.length,
                            separatorBuilder: (_, _) =>
                                const Divider(height: 1),
                            itemBuilder: (context, index) {
                              final product = filtered[index];
                              return ListTile(
                                title: Text(product.productName),
                                subtitle: Text(product.productCode),
                                trailing: Text(
                                  _money.format(product.dealerPrice),
                                ),
                                onTap: () => Navigator.pop(context, product),
                              );
                            },
                          ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );

    searchController.dispose();
    if (!mounted || selected == null) return;
    _addOrFocusProduct(selected);
  }

  void _addOrFocusProduct(Product product) {
    final existingIndex = _returnItems.indexWhere(
      (item) => item.productId == product.id,
    );
    setState(() {
      if (existingIndex >= 0) {
        _returnItems[existingIndex].caseQuantity += 1;
      } else {
        _returnItems.add(OrderLineItem.fromProduct(product));
        _itemKeys[product.id] = GlobalKey();
      }
    });
  }

  void _removeReturnItem(int productId) {
    setState(() {
      _returnItems.removeWhere((item) => item.productId == productId);
      _itemKeys.remove(productId);
    });
  }

  Future<void> _addOrEditRateLine([_CreditNoteLineDraft? existing]) async {
    final products = await _productsFuture;
    if (!mounted) return;
    Product? product = existing?.product;
    final qtyController = TextEditingController(
      text: existing == null ? '1' : '${existing.quantity}',
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
              child: Column(
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
                      border: OutlineInputBorder(),
                    ),
                    onChanged: (_) => setModalState(() {}),
                  ),
                  const SizedBox(height: 8),
                  Expanded(
                    child: ListView(
                      children: [
                        ...filtered.map(
                          (item) => ListTile(
                            selected: product?.id == item.id,
                            title: Text(item.productName),
                            subtitle: Text(
                              '${item.productCode} • ₹${item.dealerPrice}',
                            ),
                            onTap: () {
                              product = item;
                              if (originalController.text.isEmpty) {
                                originalController.text = item.dealerPrice
                                    .toString();
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
                          decoration: const InputDecoration(
                            labelText: 'Quantity',
                          ),
                        ),
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
                        TextField(
                          controller: reasonController,
                          decoration: const InputDecoration(
                            labelText: 'Reason / Remarks',
                          ),
                        ),
                        const SizedBox(height: 16),
                        FilledButton(
                          onPressed: () {
                            final selected = product;
                            final qty =
                                double.tryParse(qtyController.text.trim()) ?? 0;
                            if (selected == null || qty <= 0) return;
                            Navigator.pop(context, true);
                          },
                          child: const Text('Save line'),
                        ),
                      ],
                    ),
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
        originalRate: double.tryParse(originalController.text.trim()) ?? 0,
        revisedRate: double.tryParse(revisedController.text.trim()) ?? 0,
        reason: reasonController.text.trim(),
      );
      setState(() {
        if (existing == null) {
          _rateLines.add(line);
        } else {
          final index = _rateLines.indexOf(existing);
          if (index >= 0) _rateLines[index] = line;
        }
      });
    }

    qtyController.dispose();
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

  List<Map<String, dynamic>> _payloadItems() {
    if (_isSalesReturn) {
      return _returnItems
          .map(
            (item) => {
              'product_id': item.productId,
              'case_quantity': item.caseQuantity,
              'rate_per_no': item.ratePerNo,
              'rate_type': item.rateType.apiValue,
              'discount_value': item.discountValue,
              'gst_percentage': item.gstPercent,
            },
          )
          .toList();
    }
    return _rateLines
        .map(
          (line) => CreditNoteLine(
            productId: line.product.id,
            productName: line.product.productName,
            quantity: line.quantity,
            amount: line.amount(_type!),
            originalRate: line.originalRate,
            revisedRate: line.revisedRate,
            reason: line.reason,
          ).toPayload(_type!),
        )
        .toList();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate() || _submitting) return;
    if (_type == null || _dealer == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Select type and returning dealer.')),
      );
      return;
    }
    if (_isSalesReturn) {
      if (_moveTo == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Select Move To Factory or Dealer.')),
        );
        return;
      }
      if (_moveToDealer && _destinationDealer == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Select the destination dealer.')),
        );
        return;
      }
      if (!_canSubmitReturn) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Add valid products with cases, rate, and GST.'),
          ),
        );
        return;
      }
    } else if (_rateLines.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Add at least one product line.')),
      );
      return;
    }

    setState(() => _submitting = true);
    try {
      final dio = ApiClient(
        SessionStore(),
        onUnauthorized: widget.auth.sessionExpired,
      ).dio;
      final items = _payloadItems();

      if (widget.managerMode) {
        if (widget.initial != null) {
          await ManagerCreditNoteApi(dio).update(
            id: widget.initial!.id,
            type: _type!,
            dealerId: _dealer!.id,
            billReference: _billRefController.text.trim(),
            creditNoteDate: _date,
            items: items,
            remarks: _remarksController.text,
            documentPath: _photoPath,
            moveTo: _isSalesReturn ? _moveTo : null,
            destinationDealerId: _moveToDealer ? _destinationDealer?.id : null,
          );
        } else {
          await ManagerCreditNoteApi(dio).submit(
            type: _type!,
            dealerId: _dealer!.id,
            billReference: _billRefController.text.trim(),
            creditNoteDate: _date,
            items: items,
            remarks: _remarksController.text,
            documentPath: _photoPath,
          );
        }
      } else {
        await CreditNoteApi(dio).submit(
          type: _type!,
          dealerId: _dealer!.id,
          billReference: _billRefController.text.trim(),
          creditNoteDate: DateTime.now(),
          items: items,
          remarks: _remarksController.text,
          documentPath: _photoPath,
          creditNoteId: widget.initial?.id,
          moveTo: _moveTo,
          destinationDealerId: _moveToDealer ? _destinationDealer?.id : null,
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
    if (_type == null) {
      final showSalesReturn = _allowedTypes.contains('sales_return');
      final showRateDifference = _allowedTypes.contains('rate_difference');
      return PgPageScaffold(
        title: 'Credit Note Type',
        showBack: true,
        body: ListView(
          padding: const EdgeInsets.all(AppSpacing.screenPadding),
          children: [
            if (showSalesReturn)
              PgCard(
                onTap: () => setState(() => _type = 'sales_return'),
                child: const ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(Icons.assignment_return_outlined),
                  title: Text('Sales Return'),
                  subtitle: Text('Returned products with quantity and rate'),
                ),
              ),
            if (showSalesReturn && showRateDifference)
              const SizedBox(height: AppSpacing.md),
            if (showRateDifference)
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
              onTap: _canChangeType ? () => setState(() => _type = null) : null,
              child: Text(
                _isRateDifference
                    ? 'Type: Rate Difference'
                    : 'Type: Sales Return',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              onTap: () => _pickDealer(destination: false),
              child: ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(
                  _isSalesReturn ? 'Returning Dealer' : 'Dealer',
                ),
                subtitle: Text(_dealer?.name ?? 'Tap to choose a dealer'),
                trailing: const Icon(Icons.chevron_right_rounded),
              ),
            ),
            if (_isSalesReturn) ...[
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Move To',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    RadioListTile<String>(
                      contentPadding: EdgeInsets.zero,
                      value: 'factory',
                      groupValue: _moveTo,
                      title: const Text('Move to Factory'),
                      subtitle: const Text(
                        'Sales Manager, then Production Manager. Stock after production approval.',
                      ),
                      onChanged: (value) => setState(() {
                        _moveTo = value;
                        _destinationDealer = null;
                      }),
                    ),
                    RadioListTile<String>(
                      contentPadding: EdgeInsets.zero,
                      value: 'dealer',
                      groupValue: _moveTo,
                      title: const Text('Move to Dealer'),
                      subtitle: const Text(
                        'Create an order for another dealer after Sales Manager approval.',
                      ),
                      onChanged: (value) => setState(() {
                        _moveTo = value;
                      }),
                    ),
                  ],
                ),
              ),
              if (_moveToDealer) ...[
                const SizedBox(height: AppSpacing.md),
                PgCard(
                  onTap: () => _pickDealer(destination: true),
                  child: ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: const Text('Destination Dealer'),
                    subtitle: Text(
                      _destinationDealer?.name ??
                          'Tap to choose destination dealer',
                    ),
                    trailing: const Icon(Icons.chevron_right_rounded),
                  ),
                ),
              ],
            ],
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: TextFormField(
                controller: _billRefController,
                decoration: const InputDecoration(
                  labelText: 'Invoice / Bill Reference',
                  border: InputBorder.none,
                ),
                validator: (value) => (value == null || value.trim().isEmpty)
                    ? 'Bill reference is required.'
                    : null,
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: ListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Credit Note Date'),
                subtitle: Text(
                  _isSalesReturn
                      ? '${DateFormat('d MMM yyyy').format(DateTime.now())} (today)'
                      : DateFormat('d MMM yyyy').format(_date),
                ),
                trailing: _isSalesReturn
                    ? null
                    : const Icon(Icons.calendar_today_outlined),
                onTap: _isSalesReturn
                    ? null
                    : () async {
                        final picked = await showDatePicker(
                          context: context,
                          initialDate: _date,
                          firstDate: DateTime(2020),
                          lastDate: DateTime.now(),
                        );
                        if (picked != null) setState(() => _date = picked);
                      },
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            if (_isSalesReturn) ...[
              PgCard(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              'Products',
                              style: Theme.of(context).textTheme.titleMedium,
                            ),
                          ),
                          FilledButton.tonalIcon(
                            onPressed: _dealer == null
                                ? null
                                : _openProductSelector,
                            icon: const Icon(Icons.add),
                            label: const Text('Add Product'),
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      if (_returnItems.isEmpty)
                        const Text('No products added yet.')
                      else
                        Column(
                          children: [
                            for (var i = 0; i < _returnItems.length; i++)
                              KeyedSubtree(
                                key: _itemKeys[_returnItems[i].productId],
                                child: OrderLineItemCard(
                                  item: _returnItems[i],
                                  serialNumber: i + 1,
                                  onChanged: () => setState(() {}),
                                  onRemove: () => _removeReturnItem(
                                    _returnItems[i].productId,
                                  ),
                                ),
                              ),
                          ],
                        ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Return Summary',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: 12),
                      _SummaryRow(
                        label: 'Subtotal',
                        value: _money.format(
                          _returnSummary.amountWithoutGstSubtotal,
                        ),
                      ),
                      const SizedBox(height: 8),
                      _SummaryRow(
                        label: 'CGST',
                        value: _money.format(_returnSummary.cgst),
                      ),
                      const SizedBox(height: 8),
                      _SummaryRow(
                        label: 'SGST',
                        value: _money.format(_returnSummary.sgst),
                      ),
                      const SizedBox(height: 8),
                      _SummaryRow(
                        label: 'Grand Total',
                        value: _money.format(_returnSummary.grandTotal),
                        emphasized: true,
                      ),
                    ],
                  ),
                ),
              ),
            ] else ...[
              Row(
                children: [
                  Text(
                    'Products',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const Spacer(),
                  TextButton.icon(
                    onPressed: () => _addOrEditRateLine(),
                    icon: const Icon(Icons.add),
                    label: const Text('Add'),
                  ),
                ],
              ),
              if (_rateLines.isEmpty)
                const PgCard(child: Text('Add at least one product line.'))
              else
                ..._rateLines.map((line) {
                  return PgCard(
                    margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                    onTap: () => _addOrEditRateLine(line),
                    child: ListTile(
                      contentPadding: EdgeInsets.zero,
                      title: Text(line.product.productName),
                      subtitle: Text(
                        'Qty ${line.quantity} • ${_money.format(line.originalRate)} → ${_money.format(line.revisedRate)}',
                      ),
                      trailing: Text(_money.format(line.amount(_type!))),
                    ),
                  );
                }),
              const SizedBox(height: AppSpacing.md),
              PgCard(
                child: Text(
                  'Amount: ${_money.format(_total)}',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
            ],
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

class _SummaryRow extends StatelessWidget {
  const _SummaryRow({
    required this.label,
    required this.value,
    this.emphasized = false,
  });
  final String label;
  final String value;
  final bool emphasized;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Expanded(
        child: Text(
          label,
          style: emphasized
              ? Theme.of(
                  context,
                ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)
              : null,
        ),
      ),
      Text(
        value,
        style: Theme.of(
          context,
        ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
      ),
    ],
  );
}
