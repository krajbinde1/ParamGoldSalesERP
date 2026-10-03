import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../credit_limits/models/dealer_credit_status.dart';
import '../api/dealer_api.dart';
import '../api/dealer_credit_api.dart';
import '../api/product_api.dart';
import '../models/order_draft.dart';
import '../models/order_dealer.dart';
import '../models/order_line_item.dart';
import '../models/product.dart';
import '../widgets/credit_limit_panel.dart';
import '../widgets/order_line_item_card.dart';

class NewOrderScreen extends StatefulWidget {
  const NewOrderScreen({super.key, this.initialDraft});

  final OrderDraft? initialDraft;

  @override
  State<NewOrderScreen> createState() => _NewOrderScreenState();
}

class _NewOrderScreenState extends State<NewOrderScreen> {
  final _remarksController = TextEditingController();
  final _scrollController = ScrollController();
  final _itemKeys = <int, GlobalKey>{};
  final _items = <OrderLineItem>[];
  final _money = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 2,
  );

  OrderDealer? _selectedDealer;
  int? _editingOrderId;
  late Future<List<Product>> _productsFuture;
  late Future<List<OrderDealer>> _dealersFuture;
  DealerCreditStatus? _credit;
  bool _checkingCredit = false;
  Timer? _creditTimer;

  bool get _isEditing => _editingOrderId != null;

  bool get _dealerSelected => _selectedDealer != null;

  bool get _canSubmit =>
      _dealerSelected &&
      _items.isNotEmpty &&
      _items.every((item) => item.isValid);

  @override
  void initState() {
    super.initState();
    final dio = ApiClient(SessionStore()).dio;
    _productsFuture = ProductApi(dio).list();
    _dealersFuture = DealerApi(dio).list();

    final draft = widget.initialDraft;
    if (draft != null) {
      _editingOrderId = draft.orderId;
      _selectedDealer = draft.dealer;
      _remarksController.text = draft.remarks;
      _items.addAll(draft.items);
      for (final item in draft.items) {
        _itemKeys[item.productId] = GlobalKey();
      }
    }
    if (_selectedDealer != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _scheduleCreditCheck());
    }
  }

  @override
  void dispose() {
    _creditTimer?.cancel();
    _remarksController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _scheduleCreditCheck() {
    _creditTimer?.cancel();
    if (_selectedDealer == null) {
      setState(() => _credit = null);
      return;
    }
    _creditTimer = Timer(const Duration(milliseconds: 350), () {
      _loadCredit();
    });
  }

  Future<DealerCreditStatus?> _loadCredit() async {
    final dealer = _selectedDealer;
    if (dealer == null) return null;
    final credit = await DealerCreditApi(ApiClient(SessionStore()).dio).check(
      dealerId: dealer.id,
      orderAmount: _summary.grandTotal,
      excludeOrderId: _editingOrderId,
    );
    if (!mounted || _selectedDealer?.id != dealer.id) return credit;
    setState(() => _credit = credit);
    return credit;
  }

  OrderSummaryTotals get _summary => OrderSummaryTotals.fromItems(_items);

  Future<void> _openDealerSelector() async {
    final dealers = await _dealersFuture;
    if (!mounted) return;

    final searchController = TextEditingController();

    final selected = await showModalBottomSheet<OrderDealer>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            final query = searchController.text.trim().toLowerCase();
            final filtered = dealers.where((dealer) {
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
                top: 8,
                bottom: MediaQuery.viewInsetsOf(context).bottom + 16,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    'Select Dealer',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: searchController,
                    decoration: const InputDecoration(
                      labelText: 'Search dealer',
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
                            child: Text('No dealers found.'),
                          )
                        : ListView.separated(
                            shrinkWrap: true,
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
            );
          },
        );
      },
    );

    searchController.dispose();
    if (!mounted || selected == null) return;
    setState(() => _selectedDealer = selected);
    _scheduleCreditCheck();
  }

  Future<void> _openProductSelector() async {
    final products = await _productsFuture;
    if (!mounted) return;

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
                                  NumberFormat.currency(
                                    locale: 'en_IN',
                                    symbol: '₹',
                                    decimalDigits: 2,
                                  ).format(product.dealerPrice),
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
    final existingIndex = _items.indexWhere(
      (item) => item.productId == product.id,
    );

    setState(() {
      if (existingIndex >= 0) {
        _items[existingIndex].caseQuantity += 1;
      } else {
        _items.add(OrderLineItem.fromProduct(product));
        _itemKeys[product.id] = GlobalKey();
      }
    });
    _scheduleCreditCheck();
  }

  void _removeItem(int productId) {
    setState(() {
      _items.removeWhere((item) => item.productId == productId);
      _itemKeys.remove(productId);
    });
    _scheduleCreditCheck();
  }

  void _refreshItems() {
    setState(() {});
    _scheduleCreditCheck();
  }

  Future<void> _openReview() async {
    if (!_canSubmit || _selectedDealer == null || _checkingCredit) return;
    setState(() => _checkingCredit = true);
    try {
      final credit = await _loadCredit();
      if (!mounted) return;
      if (credit != null && credit.blocksOrder) return;
    } catch (_) {
      // The server checks the limit again when the order is submitted.
    } finally {
      if (mounted) setState(() => _checkingCredit = false);
    }
    if (!mounted || !_canSubmit || _selectedDealer == null) return;
    final draft = OrderDraft(
      orderId: _editingOrderId,
      dealer: _selectedDealer!,
      items: _items,
      remarks: _remarksController.text,
    );
    final reviewPath = _isEditing
        ? '/orders/${_editingOrderId!}/edit/review'
        : '/orders/new/review';
    context.push(reviewPath, extra: draft);
  }

  @override
  Widget build(BuildContext context) {
    final summary = _summary;

    return PgPageScaffold(
      title: _isEditing ? 'Edit Order' : 'New Order',
      showBack: true,
      body: ListView(
        controller: _scrollController,
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          PgCard(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Dealer',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 12),
                  InkWell(
                    borderRadius: BorderRadius.circular(12),
                    onTap: _openDealerSelector,
                    child: InputDecorator(
                      decoration: const InputDecoration(
                        border: OutlineInputBorder(),
                        suffixIcon: Icon(Icons.search),
                      ),
                      child: Text(
                        _selectedDealer?.name ?? 'Select dealer',
                        style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                          color: _selectedDealer == null
                              ? Theme.of(context).hintColor
                              : null,
                        ),
                      ),
                    ),
                  ),
                  if (_selectedDealer != null && _credit != null) ...[
                    const SizedBox(height: 12),
                    CreditLimitPanel(credit: _credit!),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
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
                        onPressed: _dealerSelected
                            ? _openProductSelector
                            : null,
                        icon: const Icon(Icons.add),
                        label: const Text('Add Product'),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  if (_items.isEmpty)
                    IgnorePointer(
                      ignoring: !_dealerSelected,
                      child: Opacity(
                        opacity: _dealerSelected ? 1 : 0.5,
                        child: Container(
                          width: double.infinity,
                          padding: const EdgeInsets.all(24),
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(12),
                            color: Theme.of(
                              context,
                            ).colorScheme.surfaceContainerHighest,
                          ),
                          child: const Text(
                            'No products added yet.',
                            textAlign: TextAlign.center,
                          ),
                        ),
                      ),
                    )
                  else
                    Column(
                      children: [
                        for (var i = 0; i < _items.length; i++)
                          KeyedSubtree(
                            key: _itemKeys[_items[i].productId],
                            child: OrderLineItemCard(
                              item: _items[i],
                              serialNumber: i + 1,
                              onChanged: _refreshItems,
                              onRemove: () =>
                                  _removeItem(_items[i].productId),
                            ),
                          ),
                      ],
                    ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          PgCard(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Order Summary',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 12),
                  _SummaryRow(
                    label: 'Subtotal',
                    value: _money.format(summary.amountWithoutGstSubtotal),
                  ),
                  const SizedBox(height: 8),
                  _SummaryRow(
                    label: 'CGST',
                    value: _money.format(summary.cgst),
                  ),
                  const SizedBox(height: 8),
                  _SummaryRow(
                    label: 'SGST',
                    value: _money.format(summary.sgst),
                  ),
                  const SizedBox(height: 8),
                  _SummaryRow(
                    label: 'Grand Total',
                    value: _money.format(summary.grandTotal),
                    emphasized: true,
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          PgCard(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: TextField(
                controller: _remarksController,
                readOnly: !_dealerSelected,
                maxLines: 3,
                decoration: const InputDecoration(
                  labelText: 'Remarks',
                  border: OutlineInputBorder(),
                ),
              ),
            ),
          ),
          const SizedBox(height: 20),
          SizedBox(
            width: double.infinity,
            height: 52,
            child: FilledButton(
              onPressed: _canSubmit && !_checkingCredit ? _openReview : null,
              child: Text(_isEditing ? 'Save Changes' : 'Submit Order'),
            ),
          ),
        ],
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
                  ? Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                      )
                  : null,
            ),
          ),
          Text(
            value,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                ),
          ),
        ],
      );
}
