import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/modules/payment_follow_ups/models/payment_follow_up.dart';

void main() {
  test('parses assigned dealer follow-up list and cycle history', () {
    final list = PaymentFollowUpListData.fromJson({
      'counts': {
        'overdue': 1,
        'due_today': 0,
        'upcoming': 2,
        'no_follow_up': 3,
        'closed': 1,
      },
      'data': [
        {
          'dealer_id': 12,
          'dealer_name': 'ABC Fertilizers',
          'village': 'Wagholi',
          'current_outstanding': 125000,
          'current_outstanding_label': '₹1,25,000',
          'last_follow_up_date': '2026-09-10',
          'next_follow_up_date': '2026-09-15',
          'status': 'upcoming',
          'status_label': 'Upcoming',
        },
      ],
    });

    expect(list.counts.overdue, 1);
    expect(list.dealers.single.dealerName, 'ABC Fertilizers');
    expect(list.dealers.single.status, 'upcoming');

    final detail = PaymentFollowUpDetail.fromJson({
      'dealer': {'firm_name': 'ABC Fertilizers', 'village': 'Wagholi'},
      'current_outstanding_label': '₹1,25,000',
      'status_label': 'Upcoming',
      'can_add_follow_up': true,
      'cycles': [
        {
          'cycle_number': 1,
          'opening_outstanding_label': '₹1,25,000',
          'status_label': 'OPEN',
          'entries': [
            {
              'entry_type': 'follow_up',
              'follow_up_date': '2026-09-10',
              'remark': 'Dealer requested 5 days',
              'outstanding_at_time_label': '₹1,25,000',
              'expected_amount_label': '₹50,000',
              'next_follow_up_date': '2026-09-15',
            },
          ],
        },
      ],
    });

    expect(detail.canAddFollowUp, isTrue);
    expect(detail.cycles.single.entries.single.remark, 'Dealer requested 5 days');
    expect(detail.cycles.single.isClosed, isFalse);
  });
}
