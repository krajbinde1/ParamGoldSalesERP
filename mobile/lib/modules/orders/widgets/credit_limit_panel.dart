import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../core/design/app_colors.dart';
import '../../credit_limits/models/dealer_credit_status.dart';

class CreditLimitPanel extends StatelessWidget {
  const CreditLimitPanel({super.key, required this.credit});

  final DealerCreditStatus credit;

  static final _money = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 2,
  );

  @override
  Widget build(BuildContext context) {
    if (!credit.limitSet) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Credit Limit: Not Set',
            style: TextStyle(fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          _line('Outstanding', _money.format(credit.currentOutstanding)),
          _line('Pending exposure', _money.format(credit.pendingExposure)),
          _line('Effective limit', 'Not Set'),
          _line('Available', 'Not Set'),
        ],
      );
    }

    final rows = <Widget>[
      _line('Outstanding', _money.format(credit.currentOutstanding)),
      _line('Pending exposure', _money.format(credit.pendingExposure)),
      _line(
        'Effective limit',
        credit.effectiveLimit == null
            ? 'Not Set'
            : _money.format(credit.effectiveLimit),
      ),
      _line(
        'Available',
        credit.availableLimit == null
            ? 'Not Set'
            : _money.format(credit.availableLimit),
      ),
    ];

    if (!credit.blocksOrder) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: rows,
      );
    }

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFFEF2F2),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.error),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Credit Limit Exceeded',
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
              color: AppColors.error,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 8),
          _line('Outstanding', _money.format(credit.currentOutstanding)),
          _line('Order amount', _money.format(credit.newOrderAmount)),
          _line('Projected', _money.format(credit.projectedExposure)),
          _line(
            'Allowed limit',
            credit.effectiveLimit == null
                ? 'Not Set'
                : _money.format(credit.effectiveLimit),
          ),
          _line('Exceeded by', _money.format(credit.exceededBy)),
          const SizedBox(height: 8),
          const Text(
            'Contact Manager for Limit Extension',
            style: TextStyle(
              color: AppColors.error,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }

  Widget _line(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        children: [
          Expanded(child: Text(label)),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w700)),
        ],
      ),
    );
  }
}
