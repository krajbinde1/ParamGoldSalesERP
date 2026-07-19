class OrderSubmitResult {
  const OrderSubmitResult({
    required this.message,
    required this.orderId,
    required this.orderNo,
    required this.status,
    required this.grandTotal,
  });

  final String message;
  final int orderId;
  final String orderNo;
  final String status;
  final double grandTotal;

  factory OrderSubmitResult.fromJson(Map<String, dynamic> json) =>
      OrderSubmitResult(
        message: json['message']?.toString() ?? 'Order submitted successfully.',
        orderId: int.tryParse('${json['order_id'] ?? ''}') ?? 0,
        orderNo: json['order_no']?.toString() ?? '',
        status: json['status']?.toString() ?? '',
        grandTotal: double.tryParse('${json['grand_total'] ?? 0}') ?? 0,
      );
}
