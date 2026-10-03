class DealerCreditStatus {
  const DealerCreditStatus({
    required this.dealerId,
    required this.limitSet,
    required this.status,
    required this.statusLabel,
    required this.currentOutstanding,
    required this.pendingExposure,
    required this.newOrderAmount,
    required this.projectedExposure,
    this.baseLimit,
    required this.extensionAmount,
    required this.extensionActive,
    this.extensionValidUntil,
    this.effectiveLimit,
    this.availableLimit,
    this.utilizationPercent,
    required this.exceededBy,
    required this.blocksOrder,
    required this.message,
    this.dealerCode,
    this.dealerName,
    this.employeeName,
    this.managerName,
    this.district,
    this.lastUpdatedBy,
    this.lastUpdatedAt,
  });

  final int dealerId;
  final bool limitSet;
  final String status;
  final String statusLabel;
  final double currentOutstanding;
  final double pendingExposure;
  final double newOrderAmount;
  final double projectedExposure;
  final double? baseLimit;
  final double extensionAmount;
  final bool extensionActive;
  final String? extensionValidUntil;
  final double? effectiveLimit;
  final double? availableLimit;
  final double? utilizationPercent;
  final double exceededBy;
  final bool blocksOrder;
  final String message;
  final String? dealerCode;
  final String? dealerName;
  final String? employeeName;
  final String? managerName;
  final String? district;
  final String? lastUpdatedBy;
  final String? lastUpdatedAt;

  factory DealerCreditStatus.fromJson(Map<String, dynamic> json) {
    return DealerCreditStatus(
      dealerId: int.tryParse('${json['dealer_id'] ?? 0}') ?? 0,
      limitSet: json['limit_set'] == true,
      status: json['status']?.toString() ?? '',
      statusLabel: json['status_label']?.toString() ?? '',
      currentOutstanding: _asDouble(json['current_outstanding']),
      pendingExposure: _asDouble(json['pending_exposure']),
      newOrderAmount: _asDouble(json['new_order_amount']),
      projectedExposure: _asDouble(json['projected_exposure']),
      baseLimit: json['base_limit'] == null ? null : _asDouble(json['base_limit']),
      extensionAmount: _asDouble(json['extension_amount']),
      extensionActive: json['extension_active'] == true,
      extensionValidUntil: json['extension_valid_until']?.toString(),
      effectiveLimit: json['effective_limit'] == null
          ? null
          : _asDouble(json['effective_limit']),
      availableLimit: json['available_limit'] == null
          ? null
          : _asDouble(json['available_limit']),
      utilizationPercent: json['utilization_percent'] == null
          ? null
          : _asDouble(json['utilization_percent']),
      exceededBy: _asDouble(json['exceeded_by']),
      blocksOrder: json['blocks_order'] == true,
      message: json['message']?.toString() ?? '',
      dealerCode: json['dealer_code']?.toString(),
      dealerName: json['dealer_name']?.toString(),
      employeeName: json['employee_name']?.toString(),
      managerName: json['manager_name']?.toString(),
      district: json['district']?.toString(),
      lastUpdatedBy: json['last_updated_by']?.toString(),
      lastUpdatedAt: json['last_updated_at']?.toString(),
    );
  }
}

class DealerCreditHistoryEntry {
  const DealerCreditHistoryEntry({
    required this.id,
    required this.action,
    this.previousBase,
    this.newBase,
    this.extensionAmount,
    this.effectiveLimit,
    this.validUntil,
    this.remark,
    this.changedBy,
    this.changedByRole,
    this.createdAt,
  });

  final int id;
  final String action;
  final double? previousBase;
  final double? newBase;
  final double? extensionAmount;
  final double? effectiveLimit;
  final String? validUntil;
  final String? remark;
  final String? changedBy;
  final String? changedByRole;
  final String? createdAt;

  factory DealerCreditHistoryEntry.fromJson(Map<String, dynamic> json) {
    return DealerCreditHistoryEntry(
      id: int.tryParse('${json['id'] ?? 0}') ?? 0,
      action: json['action']?.toString() ?? '',
      previousBase: json['previous_base'] == null
          ? null
          : _asDouble(json['previous_base']),
      newBase: json['new_base'] == null ? null : _asDouble(json['new_base']),
      extensionAmount: json['extension_amount'] == null
          ? null
          : _asDouble(json['extension_amount']),
      effectiveLimit: json['effective_limit'] == null
          ? null
          : _asDouble(json['effective_limit']),
      validUntil: json['valid_until']?.toString(),
      remark: json['remark']?.toString(),
      changedBy: json['changed_by']?.toString(),
      changedByRole: json['changed_by_role']?.toString(),
      createdAt: json['created_at']?.toString(),
    );
  }
}

double _asDouble(Object? value) => double.tryParse('${value ?? 0}') ?? 0;
