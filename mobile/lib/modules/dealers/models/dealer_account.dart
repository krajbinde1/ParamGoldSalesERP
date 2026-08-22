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

  factory DealerAccountSummary.fromJson(Map<String, dynamic> json) =>
      DealerAccountSummary(
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
      );
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
  });

  final String date;
  final String type;
  final String particulars;
  final String? reference;
  final double debit;
  final double credit;
  final double balance;
  final String? statusRemark;

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
    this.village,
    required this.currentOutstanding,
  });

  final int id;
  final String dealerCode;
  final String firmName;
  final String? ownerName;
  final String? village;
  final double currentOutstanding;

  factory DealerAccountListItem.fromJson(Map<String, dynamic> json) =>
      DealerAccountListItem(
        id: int.tryParse('${json['id'] ?? 0}') ?? 0,
        dealerCode: json['dealer_code']?.toString() ?? '',
        firmName: json['firm_name']?.toString() ?? '',
        ownerName: json['owner_name']?.toString(),
        village: json['village']?.toString(),
        currentOutstanding: _asDouble(json['current_outstanding']),
      );
}

class DealerAccountDetail {
  const DealerAccountDetail({
    required this.id,
    required this.dealerCode,
    required this.firmName,
    this.ownerName,
    this.mobile,
    this.village,
    required this.summary,
  });

  final int id;
  final String dealerCode;
  final String firmName;
  final String? ownerName;
  final String? mobile;
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
      village: json['village']?.toString(),
      summary: DealerAccountSummary.fromJson(summaryRaw),
    );
  }
}

double _asDouble(Object? value) => double.tryParse('${value ?? 0}') ?? 0;
