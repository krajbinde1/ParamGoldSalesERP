class OrderDealer {
  const OrderDealer({
    required this.id,
    required this.name,
    this.dealerCode,
    this.ownerName,
    this.mobile,
    this.address,
    this.village,
    this.taluka,
    this.district,
    this.state,
  });

  final int id;
  final String name;
  final String? dealerCode;
  final String? ownerName;
  final String? mobile;
  final String? address;
  final String? village;
  final String? taluka;
  final String? district;
  final String? state;

  factory OrderDealer.fromJson(Map<String, dynamic> json) => OrderDealer(
    id: int.tryParse('${json['id'] ?? ''}') ?? 0,
    name: json['firm_name']?.toString() ?? '',
    dealerCode: json['dealer_code']?.toString(),
    ownerName: json['owner_name']?.toString(),
    mobile: json['mobile']?.toString(),
    address: json['address']?.toString(),
    village: json['village']?.toString(),
    taluka: json['taluka']?.toString(),
    district: json['district']?.toString(),
    state: json['state']?.toString(),
  );
}
