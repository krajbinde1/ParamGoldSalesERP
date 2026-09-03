import 'package:flutter/material.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../../orders/models/order_dealer.dart';
import '../../orders/models/order_draft.dart';
import '../../orders/models/order_line_item.dart';
import '../../orders/models/product.dart';
import '../../orders/widgets/order_invoice_products_table.dart';
import '../../orders/widgets/order_line_item_card.dart';
import '../api/manager_api.dart';

class ManagerEditOrderScreen extends StatefulWidget {
  const ManagerEditOrderScreen({
    super.key,
    required this.auth,
    required this.orderId,
    required this.order,
  });

  final AuthController auth;
  final int orderId;
  final Map<String, dynamic> order;

  @override
  State<ManagerEditOrderScreen> createState() => _ManagerEditOrderScreenState();
}

class _ManagerEditOrderScreenState extends State<ManagerEditOrderScreen> {
  final _remarksController = TextEditingController();
  final _items = <OrderLineItem>[];
  final _itemKeys = <int, GlobalKey>{};

  late Future<List<Product>> _productsFuture;
  late OrderDealer _dealer;
  bool _saving = false;

  ManagerApi get _api => ManagerApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _productsFuture = _loadProducts();
    _dealer = _dealerFromOrder(widget.order);
    _remarksController.text = widget.order['remarks']?.toString() ?? '';

    final rawItems = (widget.order['line_items'] as List?) ??
        (widget.order['items'] as List?) ??
        const [];
    for (final raw in rawItems) {
      final item = Map<String, dynamic>.from(raw as Map);
      final line = OrderLineItem.fromOrderJson(item);
      if (line.productId > 0) {
        _items.add(line);
        _itemKeys[line.productId] = GlobalKey();
      }
    }
  }

  @override
  void dispose() {
    _remarksController.dispose();
    super.dispose();
  }

  Future<List<Product>> _loadProducts() async {
    final rows = await _api.listProducts();
    return rows
        .map((row) => Product.fromJson(row))
        .where((product) => product.id > 0)
        .toList();
  }

  OrderDealer _dealerFromOrder(Map<String, dynamic> order) {
    final dealer = order['dealer'];
    if (dealer is Map) {
      return OrderDealer.fromJson(Map<String, dynamic>.from(dealer));
    }
    return OrderDealer(
      id: int.tryParse('${order['dealer_id'] ?? 0}') ?? 0,
      name: order['dealer_name']?.toString() ?? '-',
    );
  }

  OrderSummaryTotals get _summary => OrderSummaryTotals.fromItems(_items);

  bool get _canSave =>
      _dealer.id > 0 && _items.isNotEmpty && _items.every((item) => item.isValid);

  Future<void> _addProduct() async {
    final products = await _productsFuture;
    if (!mounted) return;

    final searchController = TextEditingController();
    final selected = await showModalBottomSheet<Product>(
      context: context,
      isScrollControlled: true,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            final query = searchController.text.trim().toLowerCase();
            final filtered = products.where((product) {
              if (query.isEmpty) return true;
              return product.productName.toLowerCase().contains(query) ||
                  product.productCode.toLowerCase().contains(query);
            }).toList();

            return SafeArea(
              child: SizedBox(
                height: MediaQuery.sizeOf(context).height * 0.75,
                child: Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.all(AppSpacing.screenPadding),
                      child: TextField(
                        controller: searchController,
                        decoration: const InputDecoration(
                          labelText: 'Search products',
                          border: OutlineInputBorder(),
                          prefixIcon: Icon(Icons.search),
                        ),
                        onChanged: (_) => setModalState(() {}),
                      ),
                    ),
                    Expanded(
                      child: ListView.builder(
                        itemCount: filtered.length,
                        itemBuilder: (context, index) {
                          final product = filtered[index];
                          return ListTile(
                            title: Text(product.productName),
                            subtitle: Text(
                              '${product.productCode} • ₹${product.dealerPrice}',
                            ),
                            onTap: () => Navigator.pop(context, product),
                          );
                        },
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );

    if (selected == null) return;
    setState(() {
      final existingIndex =
          _items.indexWhere((item) => item.productId == selected.id);
      if (existingIndex >= 0) {
        _items[existingIndex].caseQuantity += 1;
      } else {
        _items.add(OrderLineItem.fromProduct(selected));
        _itemKeys[selected.id] = GlobalKey();
      }
    });
  }

  Future<void> _save() async {
    if (!_canSave || _saving) return;

    setState(() => _saving = true);
    try {
      final draft = OrderDraft(
        orderId: widget.orderId,
        dealer: _dealer,
        items: _items,
        remarks: _remarksController.text,
      );
      await _api.updateOrder(widget.orderId, draft.toSubmitJson());
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Order updated successfully.')),
      );
      safePop(context, true);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final summary = _summary;

    return PgPageScaffold(
      title: 'Edit Order',
      showBack: true,
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          PgCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Dealer', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: AppSpacing.sm),
                PgInvoiceRow(label: 'Dealer Name', value: _dealer.name),
                if ((_dealer.dealerCode ?? '').isNotEmpty)
                  PgInvoiceRow(label: 'Dealer Code', value: _dealer.dealerCode!),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          Row(
            children: [
              Expanded(
                child: Text(
                  'Products',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              TextButton.icon(
                onPressed: _saving ? null : _addProduct,
                icon: const Icon(Icons.add),
                label: const Text('Add Product'),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          if (_items.isEmpty)
            const PgCard(child: Text('Add at least one product.'))
          else
            OrderInvoiceProductsCard(
              showTitle: false,
              lines: _items
                  .map(OrderInvoiceLine.fromLineItem)
                  .toList(growable: false),
              onEdit: (index) => _editItem(_items[index]),
              onDelete: (index) => setState(() {
                final productId = _items[index].productId;
                _items.removeWhere((row) => row.productId == productId);
                _itemKeys.remove(productId);
              }),
            ),
          const SizedBox(height: AppSpacing.md),
          TextField(
            controller: _remarksController,
            maxLines: 3,
            decoration: const InputDecoration(
              labelText: 'Remarks',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          PgCard(
            child: OrderInvoiceSummaryBlock(
              showTitle: false,
              subtotal: summary.subtotal,
              discount: summary.totalDiscount,
              gst: summary.totalGst,
              grandTotal: summary.grandTotal,
            ),
          ),
          const SizedBox(height: AppSpacing.md),
          FilledButton(
            onPressed: _canSave && !_saving ? _save : null,
            child: Text(_saving ? 'Saving...' : 'Save Changes'),
          ),
          const SizedBox(height: AppSpacing.xl),
        ],
      ),
    );
  }

  Future<void> _editItem(OrderLineItem item) async {
    await showOrderLineItemEditor(
      context: context,
      item: item,
      onChanged: () => setState(() {}),
      onRemove: () => setState(() {
        _items.removeWhere((row) => row.productId == item.productId);
        _itemKeys.remove(item.productId);
      }),
    );
    if (mounted) setState(() {});
  }
}
