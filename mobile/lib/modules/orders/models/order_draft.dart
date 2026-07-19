import 'order_dealer.dart';
import 'order_line_item.dart';
import 'product.dart';

class OrderDraft {
  const OrderDraft({
    required this.dealer,
    required this.items,
    required this.remarks,
    this.orderId,
  });

  final int? orderId;
  final OrderDealer dealer;
  final List<OrderLineItem> items;
  final String remarks;

  bool get isEditing => orderId != null;

  bool get canSubmit => items.isNotEmpty && items.every((item) => item.isValid);

  OrderSummaryTotals get summary => OrderSummaryTotals.fromItems(items);

  Map<String, dynamic> toSubmitJson() => {
    'dealer_id': dealer.id,
    'remarks': remarks.trim().isEmpty ? null : remarks.trim(),
    'items': items
        .map(
          (item) => {
            'product_id': item.productId,
            'case_quantity': item.caseQuantity,
            'rate_per_no': item.ratePerNo,
            'discount_type': 'percentage',
            'discount_value': item.isDiscountEnabled ? item.discountValue : 0,
            'gst_percentage': Product.normalizeGst(item.gstPercent).toInt(),
          },
        )
        .toList(),
  };
}
