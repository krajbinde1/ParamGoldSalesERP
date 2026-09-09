class PaymentFollowUpListData {
  const PaymentFollowUpListData({
    required this.counts,
    required this.dealers,
  });

  factory PaymentFollowUpListData.fromJson(Map<String, dynamic> json) {
    final countsRaw = json['counts'] is Map
        ? Map<String, dynamic>.from(json['counts'] as Map)
        : const <String, dynamic>{};
    final rows = json['data'] is List ? json['data'] as List : const [];

    return PaymentFollowUpListData(
      counts: PaymentFollowUpCounts.fromJson(countsRaw),
      dealers: rows
          .whereType<Map>()
          .map((row) => PaymentFollowUpDealerRow.fromJson(
                Map<String, dynamic>.from(row),
              ))
          .toList(),
    );
  }

  final PaymentFollowUpCounts counts;
  final List<PaymentFollowUpDealerRow> dealers;
}

class PaymentFollowUpCounts {
  const PaymentFollowUpCounts({
    required this.overdue,
    required this.dueToday,
    required this.upcoming,
    required this.noFollowUp,
    required this.closed,
  });

  factory PaymentFollowUpCounts.fromJson(Map<String, dynamic> json) {
    int asInt(Object? value) => int.tryParse('${value ?? 0}') ?? 0;

    return PaymentFollowUpCounts(
      overdue: asInt(json['overdue']),
      dueToday: asInt(json['due_today']),
      upcoming: asInt(json['upcoming']),
      noFollowUp: asInt(json['no_follow_up']),
      closed: asInt(json['closed']),
    );
  }

  final int overdue;
  final int dueToday;
  final int upcoming;
  final int noFollowUp;
  final int closed;
}

class PaymentFollowUpDealerRow {
  const PaymentFollowUpDealerRow({
    required this.dealerId,
    required this.dealerName,
    this.village,
    required this.currentOutstanding,
    required this.currentOutstandingLabel,
    this.lastFollowUpDate,
    this.nextFollowUpDate,
    required this.status,
    required this.statusLabel,
  });

  factory PaymentFollowUpDealerRow.fromJson(Map<String, dynamic> json) {
    return PaymentFollowUpDealerRow(
      dealerId: int.tryParse('${json['dealer_id'] ?? 0}') ?? 0,
      dealerName: json['dealer_name']?.toString() ?? '',
      village: json['village']?.toString(),
      currentOutstanding:
          double.tryParse('${json['current_outstanding'] ?? 0}') ?? 0,
      currentOutstandingLabel:
          json['current_outstanding_label']?.toString() ?? '',
      lastFollowUpDate: json['last_follow_up_date']?.toString(),
      nextFollowUpDate: json['next_follow_up_date']?.toString(),
      status: json['status']?.toString() ?? 'no_follow_up',
      statusLabel: json['status_label']?.toString() ?? 'No Follow-up',
    );
  }

  final int dealerId;
  final String dealerName;
  final String? village;
  final double currentOutstanding;
  final String currentOutstandingLabel;
  final String? lastFollowUpDate;
  final String? nextFollowUpDate;
  final String status;
  final String statusLabel;
}

class PaymentFollowUpDetail {
  const PaymentFollowUpDetail({
    required this.dealerName,
    this.village,
    required this.currentOutstandingLabel,
    this.lastPaymentDate,
    this.lastPaymentAmountLabel,
    required this.statusLabel,
    required this.canAddFollowUp,
    required this.cycles,
  });

  factory PaymentFollowUpDetail.fromJson(Map<String, dynamic> json) {
    final dealer = json['dealer'] is Map
        ? Map<String, dynamic>.from(json['dealer'] as Map)
        : const <String, dynamic>{};
    final cycles = json['cycles'] is List ? json['cycles'] as List : const [];

    return PaymentFollowUpDetail(
      dealerName: dealer['firm_name']?.toString() ?? '',
      village: dealer['village']?.toString(),
      currentOutstandingLabel:
          json['current_outstanding_label']?.toString() ?? '',
      lastPaymentDate: json['last_payment_date']?.toString(),
      lastPaymentAmountLabel: json['last_payment_amount_label']?.toString(),
      statusLabel: json['status_label']?.toString() ?? '',
      canAddFollowUp: json['can_add_follow_up'] == true,
      cycles: cycles
          .whereType<Map>()
          .map(
            (row) => PaymentFollowUpCycle.fromJson(
              Map<String, dynamic>.from(row),
            ),
          )
          .toList(),
    );
  }

  final String dealerName;
  final String? village;
  final String currentOutstandingLabel;
  final String? lastPaymentDate;
  final String? lastPaymentAmountLabel;
  final String statusLabel;
  final bool canAddFollowUp;
  final List<PaymentFollowUpCycle> cycles;
}

class PaymentFollowUpCycle {
  const PaymentFollowUpCycle({
    required this.cycleNumber,
    required this.openingOutstandingLabel,
    required this.statusLabel,
    this.closedDate,
    this.paymentReceivedAmountLabel,
    this.closingOutstandingLabel,
    required this.entries,
  });

  factory PaymentFollowUpCycle.fromJson(Map<String, dynamic> json) {
    final entries = json['entries'] is List ? json['entries'] as List : const [];

    return PaymentFollowUpCycle(
      cycleNumber: int.tryParse('${json['cycle_number'] ?? 0}') ?? 0,
      openingOutstandingLabel:
          json['opening_outstanding_label']?.toString() ?? '',
      statusLabel: json['status_label']?.toString() ?? '',
      closedDate: json['closed_date']?.toString(),
      paymentReceivedAmountLabel:
          json['payment_received_amount_label']?.toString(),
      closingOutstandingLabel: json['closing_outstanding_label']?.toString(),
      entries: entries
          .whereType<Map>()
          .map(
            (row) => PaymentFollowUpEntry.fromJson(
              Map<String, dynamic>.from(row),
            ),
          )
          .toList(),
    );
  }

  final int cycleNumber;
  final String openingOutstandingLabel;
  final String statusLabel;
  final String? closedDate;
  final String? paymentReceivedAmountLabel;
  final String? closingOutstandingLabel;
  final List<PaymentFollowUpEntry> entries;

  bool get isClosed => statusLabel.toUpperCase() == 'CLOSED';
}

class PaymentFollowUpEntry {
  const PaymentFollowUpEntry({
    required this.entryType,
    required this.followUpDate,
    required this.remark,
    required this.outstandingLabel,
    this.expectedAmountLabel,
    this.nextFollowUpDate,
    this.employeeName,
  });

  factory PaymentFollowUpEntry.fromJson(Map<String, dynamic> json) {
    return PaymentFollowUpEntry(
      entryType: json['entry_type']?.toString() ?? 'follow_up',
      followUpDate: json['follow_up_date']?.toString() ?? '',
      remark: json['remark']?.toString() ?? '',
      outstandingLabel: json['outstanding_at_time_label']?.toString() ?? '',
      expectedAmountLabel: json['expected_amount_label']?.toString(),
      nextFollowUpDate: json['next_follow_up_date']?.toString(),
      employeeName: json['employee_name']?.toString(),
    );
  }

  final String entryType;
  final String followUpDate;
  final String remark;
  final String outstandingLabel;
  final String? expectedAmountLabel;
  final String? nextFollowUpDate;
  final String? employeeName;

  bool get isPaymentReceived => entryType == 'payment_received';
}
