String shortOrderNo(String? orderNo) {
  final value = (orderNo ?? '').trim();
  if (value.isEmpty) return '-';

  final match = RegExp(r'^([A-Za-z]+)-(\d{8})-(\d+)$').firstMatch(value);
  if (match != null) {
    return '${match.group(1)}-${match.group(3)}';
  }

  return value;
}

String productionOrderListNo(Map<String, dynamic> order) {
  final short = order['short_order_no']?.toString().trim();
  if (short != null && short.isNotEmpty) {
    return short;
  }

  return shortOrderNo(
    order['order_no']?.toString() ?? order['order_number']?.toString(),
  );
}
