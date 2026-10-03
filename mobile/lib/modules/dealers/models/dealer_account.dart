class DealerAccountSummary {
  const DealerAccountSummary({
    required this.dealerId,
    required this.dealerCode,
    required this.dealerName,
    required this.openingBalance,
    this.openingBalanceDate,
    required this.billedSales,
    required this.collectionsReceived,
    required this.currentOutstanding,
    required this.unbilledOrders,
    required this.totalExposure,
    this.creditLimitSet = false,
    this.creditBaseLimit,
    this.creditExtensionAmount = 0,
    this.creditEffectiveLimit,
    this.creditAvailableLimit,
    this.creditStatusLabel,
  });

  final int dealerId;
  final String dealerCode;
  final String dealerName;
  final double openingBalance;
  final String? openingBalanceDate;
  final double billedSales;
  final double collectionsReceived;
  final double currentOutstanding;
  final double unbilledOrders;
  final double totalExposure;
  final bool creditLimitSet;
  final double? creditBaseLimit;
  final double creditExtensionAmount;
  final double? creditEffectiveLimit;
  final double? creditAvailableLimit;
  final String? creditStatusLabel;

  factory DealerAccountSummary.fromJson(Map<String, dynamic> json) {
    final credit = json['credit_limit'] is Map
        ? Map<String, dynamic>.from(json['credit_limit'] as Map)
        : const <String, dynamic>{};

    return DealerAccountSummary(
      dealerId: int.tryParse('${json['dealer_id'] ?? json['id'] ?? 0}') ?? 0,
      dealerCode: json['dealer_code']?.toString() ?? '',
      dealerName:
          json['dealer_name']?.toString() ??
          json['firm_name']?.toString() ??
          '',
      openingBalance: _asDouble(json['opening_balance']),
      openingBalanceDate: json['opening_balance_date']?.toString(),
      billedSales: _asDouble(json['billed_sales']),
      collectionsReceived: _asDouble(json['collections_received']),
      currentOutstanding: _asDouble(json['current_outstanding']),
      unbilledOrders: _asDouble(json['unbilled_orders']),
      totalExposure: _asDouble(json['total_exposure']),
      creditLimitSet: credit['limit_set'] == true,
      creditBaseLimit: credit['base_limit'] == null
          ? null
          : _asDouble(credit['base_limit']),
      creditExtensionAmount: _asDouble(credit['extension_amount']),
      creditEffectiveLimit: credit['effective_limit'] == null
          ? null
          : _asDouble(credit['effective_limit']),
      creditAvailableLimit: credit['available_limit'] == null
          ? null
          : _asDouble(credit['available_limit']),
      creditStatusLabel: credit['status_label']?.toString(),
    );
  }
}

class DealerLedgerEntry {
  const DealerLedgerEntry({
    required this.date,
    required this.type,
    required this.particulars,
    this.reference,
    required this.debit,
    required this.credit,
    required this.balance,
    this.statusRemark,
    this.transactionType,
    this.referenceNo,
    this.sourceType,
    this.documentId,
    this.documentUrl,
    this.isClickable = false,
    this.unavailableReason,
  });

  final String date;
  final String type;
  final String particulars;
  final String? reference;
  final double debit;
  final double credit;
  final double balance;
  final String? statusRemark;
  final String? transactionType;
  final String? referenceNo;
  final String? sourceType;
  final int? documentId;
  final String? documentUrl;
  final bool isClickable;
  final String? unavailableReason;

  factory DealerLedgerEntry.fromJson(Map<String, dynamic> json) =>
      DealerLedgerEntry(
        date: json['date']?.toString() ?? '',
        type: json['type']?.toString() ?? '',
        particulars: json['particulars']?.toString() ?? '',
        reference: json['reference']?.toString(),
        debit: _asDouble(json['debit']),
        credit: _asDouble(json['credit']),
        balance: _asDouble(json['balance']),
        statusRemark: json['status_remark']?.toString(),
        transactionType: json['transaction_type']?.toString(),
        referenceNo: json['reference_no']?.toString(),
        sourceType: json['source_type']?.toString(),
        documentId: json['document_id'] == null
            ? null
            : int.tryParse('${json['document_id']}'),
        documentUrl: json['document_url']?.toString(),
        isClickable:
            json['is_clickable'] == true ||
            json['is_clickable'] == 1 ||
            json['is_clickable'] == '1',
        unavailableReason: json['unavailable_reason']?.toString(),
      );
}

class DealerLedgerData {
  const DealerLedgerData({required this.summary, required this.entries});

  final DealerAccountSummary summary;
  final List<DealerLedgerEntry> entries;

  factory DealerLedgerData.fromJson(Map<String, dynamic> json) {
    final summaryRaw = json['summary'] is Map
        ? Map<String, dynamic>.from(json['summary'] as Map)
        : json;
    final ledgerRaw = json['ledger'] is List ? json['ledger'] as List : const [];

    return DealerLedgerData(
      summary: DealerAccountSummary.fromJson(summaryRaw),
      entries: ledgerRaw
          .whereType<Map>()
          .map(
            (item) =>
                DealerLedgerEntry.fromJson(Map<String, dynamic>.from(item)),
          )
          .toList(),
    );
  }
}

class DealerAccountListItem {
  const DealerAccountListItem({
    required this.id,
    required this.dealerCode,
    required this.firmName,
    this.ownerName,
    this.mobile,
    this.district,
    this.taluka,
    this.village,
    required this.currentOutstanding,
  });

  final int id;
  final String dealerCode;
  final String firmName;
  final String? ownerName;
  final String? mobile;
  final String? district;
  final String? taluka;
  final String? village;
  final double currentOutstanding;

  factory DealerAccountListItem.fromJson(Map<String, dynamic> json) =>
      DealerAccountListItem(
        id: int.tryParse('${json['id'] ?? 0}') ?? 0,
        dealerCode: json['dealer_code']?.toString() ?? '',
        firmName: json['firm_name']?.toString() ?? '',
        ownerName: json['owner_name']?.toString(),
        mobile: json['mobile']?.toString(),
        district: json['district']?.toString(),
        taluka: json['taluka']?.toString(),
        village: json['village']?.toString(),
        currentOutstanding: _asDouble(json['current_outstanding']),
      );
}

class AssignedDealerListItem {
  const AssignedDealerListItem({
    required this.id,
    required this.firmName,
    this.dealerCode,
    this.ownerName,
    this.mobile,
    this.email,
    this.district,
    this.taluka,
    this.village,
  });

  final int id;
  final String firmName;
  final String? dealerCode;
  final String? ownerName;
  final String? mobile;
  final String? email;
  final String? district;
  final String? taluka;
  final String? village;

  factory AssignedDealerListItem.fromJson(Map<String, dynamic> json) =>
      AssignedDealerListItem(
        id: int.tryParse('${json['id'] ?? 0}') ?? 0,
        firmName: json['firm_name']?.toString() ?? '',
        dealerCode: json['dealer_code']?.toString(),
        ownerName: json['owner_name']?.toString(),
        mobile: json['mobile']?.toString(),
        email: json['email']?.toString(),
        district: json['district']?.toString(),
        taluka: json['taluka']?.toString(),
        village: json['village']?.toString(),
      );
}

class DealerAccountDetail {
  const DealerAccountDetail({
    required this.id,
    required this.dealerCode,
    required this.firmName,
    this.ownerName,
    this.mobile,
    this.district,
    this.taluka,
    this.village,
    required this.summary,
  });

  final int id;
  final String dealerCode;
  final String firmName;
  final String? ownerName;
  final String? mobile;
  final String? district;
  final String? taluka;
  final String? village;
  final DealerAccountSummary summary;

  factory DealerAccountDetail.fromJson(Map<String, dynamic> json) {
    final summaryRaw = json['account_summary'] is Map
        ? Map<String, dynamic>.from(json['account_summary'] as Map)
        : json;

    return DealerAccountDetail(
      id: int.tryParse('${json['id'] ?? 0}') ?? 0,
      dealerCode: json['dealer_code']?.toString() ?? '',
      firmName: json['firm_name']?.toString() ?? '',
      ownerName: json['owner_name']?.toString(),
      mobile: json['mobile']?.toString(),
      district: json['district']?.toString(),
      taluka: json['taluka']?.toString(),
      village: json['village']?.toString(),
      summary: DealerAccountSummary.fromJson(summaryRaw),
    );
  }
}

double _asDouble(Object? value) => double.tryParse('${value ?? 0}') ?? 0;
