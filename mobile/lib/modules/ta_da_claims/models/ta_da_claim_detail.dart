import 'package:intl/intl.dart';

class TaDaClaimDetail {
  const TaDaClaimDetail({
    required this.id,
    this.employeeName,
    required this.claimDate,
    required this.fromLocation,
    required this.toLocation,
    required this.route,
    required this.travelKm,
    required this.perKmRate,
    required this.travelAmount,
    required this.daAmount,
    required this.otherExpense,
    required this.totalAmount,
    this.billPhotoUrl,
    this.employeeRemarks,
    this.adminRemark,
    required this.status,
    required this.statusLabel,
  });

  final int id;
  final String? employeeName;
  final DateTime claimDate;
  final String fromLocation;
  final String toLocation;
  final String route;
  final double travelKm;
  final double perKmRate;
  final double travelAmount;
  final double daAmount;
  final double otherExpense;
  final double totalAmount;
  final String? billPhotoUrl;
  final String? employeeRemarks;
  final String? adminRemark;
  final String status;
  final String statusLabel;

  factory TaDaClaimDetail.fromJson(Map<String, dynamic> json) =>
      TaDaClaimDetail(
        id: int.tryParse('${json['id'] ?? ''}') ?? 0,
        employeeName: json['employee_name']?.toString(),
        claimDate: DateFormat(
          'yyyy-MM-dd',
        ).parse(json['claim_date']?.toString() ?? DateTime.now().toString()),
        fromLocation: json['from_location']?.toString() ?? '-',
        toLocation: json['to_location']?.toString() ?? '-',
        route: json['route']?.toString() ?? '-',
        travelKm: double.tryParse('${json['travel_km'] ?? ''}') ?? 0,
        perKmRate: double.tryParse('${json['per_km_rate'] ?? ''}') ?? 0,
        travelAmount: double.tryParse('${json['travel_amount'] ?? ''}') ?? 0,
        daAmount: double.tryParse('${json['da_amount'] ?? ''}') ?? 0,
        otherExpense: double.tryParse('${json['other_expense'] ?? ''}') ?? 0,
        totalAmount: double.tryParse('${json['total_amount'] ?? ''}') ?? 0,
        billPhotoUrl: json['bill_photo_url']?.toString(),
        employeeRemarks: json['employee_remarks']?.toString(),
        adminRemark: json['admin_remark']?.toString(),
        status: json['status']?.toString() ?? 'pending',
        statusLabel: json['status_label']?.toString() ?? 'Pending',
      );
}
