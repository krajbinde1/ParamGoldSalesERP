class OrderDealer {
  const OrderDealer({
    required this.id,
    required this.name,
    this.ownerName,
    this.village,
    this.mobile,
  });

  final int id;
  final String name;
  final String? ownerName;
  final String? village;
  final String? mobile;

  factory OrderDealer.fromJson(Map<String, dynamic> json) => OrderDealer(
    id: int.tryParse('${json['id'] ?? ''}') ?? 0,
    name: json['firm_name']?.toString() ?? '',
    ownerName: json['owner_name']?.toString(),
    village: json['village']?.toString(),
    mobile: json['mobile']?.toString(),
  );
}
