import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../modules/auth/providers/auth_controller.dart';
import '../../modules/director/api/director_api.dart';
import '../api/api_client.dart';
import '../api/api_errors.dart';
import '../design/app_colors.dart';
import '../design/app_spacing.dart';
import '../storage/session_store.dart';
import '../utils/bill_document.dart';
import '../widgets/prompt_dialog.dart';
import 'notification_payload.dart';
import 'push_notification_service.dart';

/// Incoming-call style full-screen alert for critical ERP approvals.
///
/// Approval/rejection always uses authenticated APIs — never trusted FCM data.
class CriticalApprovalAlertScreen extends StatefulWidget {
  const CriticalApprovalAlertScreen({
    super.key,
    required this.payload,
    this.auth,
  });

  final NotificationPayload payload;
  final AuthController? auth;

  @override
  State<CriticalApprovalAlertScreen> createState() =>
      _CriticalApprovalAlertScreenState();
}

class _CriticalApprovalAlertScreenState
    extends State<CriticalApprovalAlertScreen> {
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      PushNotificationService.instance
          .cancelNotification(widget.payload.notificationId);
    });
  }

  bool get _isPayment =>
      widget.payload.type == 'payment_approval_required' ||
      widget.payload.type == 'payment_request_reminder' ||
      widget.payload.type == 'payment_request_created' ||
      widget.payload.type == 'payment_request_first_approved';

  bool get _isReminder => widget.payload.type == 'payment_request_reminder';

  bool get _isNewOrder => widget.payload.type == 'new_order';
  bool get _isOrderApproved => widget.payload.type == 'order_approved';
  bool get _isOrderBilled => widget.payload.type == 'order_billed';

  int get _pendingCount {
    return int.tryParse('${widget.payload.raw['pending_count'] ?? ''}') ??
        (widget.payload.orderId != null ||
                widget.payload.raw['payment_request_id'] != null
            ? 1
            : 0);
  }

  String get _eventTitle {
    if (_isPayment) {
      return _isReminder
          ? 'PAYMENT APPROVAL REMINDER'
          : 'PAYMENT APPROVAL REQUIRED';
    }
    final t = widget.payload.title.trim();
    if (t.isNotEmpty) return t;
    return switch (widget.payload.type) {
      'new_order' => 'New Order for Approval',
      'order_approved' => 'Order Approved',
      'order_billed' => 'Order Billed',
      _ => 'Critical Alert',
    };
  }

  String get _referenceNo {
    if (_isPayment) {
      final count = _pendingCount;
      if (count > 0) {
        return count == 1 ? '1 Payment Request' : '$count Payment Requests';
      }
    }
    final orderNo = widget.payload.orderNo?.trim();
    if (orderNo != null && orderNo.isNotEmpty) return orderNo;
    final requestNo = widget.payload.requestNo?.trim();
    if (requestNo != null && requestNo.isNotEmpty) return requestNo;
    return '—';
  }

  String get _partyName {
    final dealer = widget.payload.dealerName?.trim();
    final village = widget.payload.raw['dealer_village']?.toString().trim();
    if (dealer != null && dealer.isNotEmpty) {
      if (village != null && village.isNotEmpty) {
        return '$dealer\n$village';
      }
      return dealer;
    }
    final vendor = widget.payload.vendorName?.trim();
    if (vendor != null && vendor.isNotEmpty) return vendor;
    if (_isPayment) return 'Multiple vendors';
    return '—';
  }

  String get _amountLabel {
    final amount = widget.payload.amount?.trim() ??
        widget.payload.raw['pending_amount']?.toString();
    if (amount == null || amount.isEmpty) return '—';
    final parsed = double.tryParse(amount.replaceAll(',', ''));
    if (parsed == null) return amount;
    return NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: parsed == parsed.roundToDouble() ? 0 : 2,
    ).format(parsed);
  }

  String get _dateTimeLabel {
    final raw = widget.payload.eventAt?.trim();
    if (raw == null || raw.isEmpty) {
      return DateFormat('dd MMM yyyy, hh:mm a').format(DateTime.now());
    }
    final dt = DateTime.tryParse(raw);
    if (dt == null) return raw;
    return DateFormat('dd MMM yyyy, hh:mm a').format(dt.toLocal());
  }

  String get _statusLabel {
    if (_isPayment) {
      final stage = widget.payload.raw['approval_stage']?.toString().trim();
      if (stage != null && stage.isNotEmpty) return stage;
      final status = widget.payload.raw['status']?.toString();
      if (status == 'pending_first_approval') return 'First Approval';
      if (status == 'pending_second_approval') return 'Second Approval';
    }
    final s = widget.payload.statusLabel?.trim();
    if (s != null && s.isNotEmpty) return s;
    return '—';
  }

  void _ignore() {
    if (Navigator.of(context).canPop()) {
      context.pop();
    } else {
      context.go('/dashboard');
    }
  }

  void _viewPayment() {
    context.go('/director/payment-requests?filter=pending');
  }

  void _review() {
    final route = widget.payload.reviewRoute;
    if (route == null || route.isEmpty) {
      _ignore();
      return;
    }
    context.go(route);
  }

  void _reject() {
    final id = widget.payload.orderId;
    if (id == null) {
      _ignore();
      return;
    }
    context.go('/manager/orders/$id?action=reject');
  }

  Future<void> _viewBill() async {
    final billUrl = widget.payload.billUrl;
    if (billUrl != null && billUrl.isNotEmpty) {
      await openBillDocument(context, url: billUrl);
      if (!mounted) return;
    }
    final route = widget.payload.reviewRoute;
    if (route != null && route.isNotEmpty) {
      context.go(route);
      return;
    }
    _ignore();
  }

  Future<void> _approvePayment() async {
    final count = _pendingCount;
    final paymentId =
        int.tryParse('${widget.payload.raw['payment_request_id'] ?? ''}');

    // Multiple pending → open approval list with selection ready.
    if (count > 1 || paymentId == null) {
      context.go(
        '/director/payment-requests?filter=pending&select_all=1&action=approve',
      );
      return;
    }

    // Single eligible request → confirm, then authenticated approve API.
    final confirmed = await confirmAction(
      context,
      title: 'Approve Payment Request',
      message:
          'Approve this payment request totaling $_amountLabel?\n\nThis uses your authenticated session — not the notification payload.',
    );
    if (!confirmed || !mounted) return;

    final auth = widget.auth;
    if (auth == null) {
      context.go('/director/payment-requests/$paymentId');
      return;
    }

    setState(() => _busy = true);
    try {
      final api = DirectorApi(
        ApiClient(SessionStore(), onUnauthorized: auth.sessionExpired).dio,
      );
      await api.approvePaymentRequest(paymentId);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Payment request approved')),
      );
      context.go('/director/payment-requests?filter=pending');
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
      // Fall back to list so director can still act.
      context.go('/director/payment-requests?filter=pending');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final showSales = !_isPayment &&
        (widget.payload.salesPersonName?.trim().isNotEmpty ?? false);

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light.copyWith(
        statusBarColor: Colors.transparent,
      ),
      child: Scaffold(
        backgroundColor: AppColors.primary,
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.lg,
              vertical: AppSpacing.md,
            ),
            child: Column(
              children: [
                const SizedBox(height: AppSpacing.lg),
                Container(
                  width: 72,
                  height: 72,
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.15),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(
                    Icons.notifications_active_rounded,
                    color: Colors.white,
                    size: 36,
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                Text(
                  'ParamGold',
                  style: Theme.of(context).textTheme.labelLarge?.copyWith(
                        color: Colors.white.withValues(alpha: 0.85),
                        letterSpacing: 1.2,
                        fontWeight: FontWeight.w600,
                      ),
                ),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  _eventTitle,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                      ),
                ),
                if (_isPayment && _isReminder) ...[
                  const SizedBox(height: AppSpacing.sm),
                  Text(
                    '$_referenceNo\n$_amountLabel pending for your approval',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: Colors.white.withValues(alpha: 0.9),
                        ),
                  ),
                ],
                const SizedBox(height: AppSpacing.lg),
                Expanded(
                  child: Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(AppSpacing.lg),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: SingleChildScrollView(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          if (_isPayment) ...[
                            _InfoRow(
                              label: 'Pending Requests',
                              value: _pendingCount > 0
                                  ? '$_pendingCount'
                                  : _referenceNo,
                            ),
                            _InfoRow(
                              label: 'Total Pending Amount',
                              value: _amountLabel,
                            ),
                            _InfoRow(
                              label: 'Approval Stage',
                              value: _statusLabel,
                            ),
                          ] else ...[
                            _InfoRow(label: 'Order No', value: _referenceNo),
                            _InfoRow(label: 'Dealer', value: _partyName),
                            if (showSales)
                              _InfoRow(
                                label: 'Sales Person',
                                value: widget.payload.salesPersonName!.trim(),
                              ),
                            _InfoRow(label: 'Amount', value: _amountLabel),
                            _InfoRow(
                              label: 'Date / Time',
                              value: _dateTimeLabel,
                            ),
                            _InfoRow(label: 'Status', value: _statusLabel),
                          ],
                        ],
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                ..._buildActions(),
                const SizedBox(height: AppSpacing.sm),
              ],
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _buildActions() {
    if (_isPayment) {
      return [
        _PrimaryButton(
          label: 'VIEW',
          onPressed: _busy ? null : _viewPayment,
        ),
        const SizedBox(height: AppSpacing.sm),
        Row(
          children: [
            Expanded(
              child: _SecondaryButton(
                label: 'IGNORE',
                onPressed: _busy ? null : _ignore,
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: _PrimaryButton(
                label: _busy ? '...' : 'APPROVE',
                onPressed: _busy ? null : _approvePayment,
              ),
            ),
          ],
        ),
      ];
    }

    if (_isNewOrder) {
      return [
        _PrimaryButton(label: 'Review', onPressed: _review),
        const SizedBox(height: AppSpacing.sm),
        Row(
          children: [
            Expanded(
              child: _SecondaryButton(label: 'Ignore', onPressed: _ignore),
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: _DangerButton(label: 'Reject', onPressed: _reject),
            ),
          ],
        ),
      ];
    }

    if (_isOrderBilled) {
      return [
        _PrimaryButton(label: 'View Bill', onPressed: _viewBill),
        const SizedBox(height: AppSpacing.sm),
        _SecondaryButton(label: 'Ignore', onPressed: _ignore),
      ];
    }

    if (_isOrderApproved) {
      return [
        _PrimaryButton(label: 'Review Order', onPressed: _review),
        const SizedBox(height: AppSpacing.sm),
        _SecondaryButton(label: 'Ignore', onPressed: _ignore),
      ];
    }

    return [
      _PrimaryButton(label: 'Review', onPressed: _review),
      const SizedBox(height: AppSpacing.sm),
      _SecondaryButton(label: 'Ignore', onPressed: _ignore),
    ];
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label.toUpperCase(),
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: AppColors.textMuted,
                  letterSpacing: 0.6,
                  fontWeight: FontWeight.w600,
                ),
          ),
          const SizedBox(height: 4),
          Text(
            value,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  color: AppColors.textPrimary,
                  fontWeight: FontWeight.w600,
                ),
          ),
        ],
      ),
    );
  }
}

class _PrimaryButton extends StatelessWidget {
  const _PrimaryButton({required this.label, required this.onPressed});

  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      height: 52,
      child: FilledButton(
        onPressed: onPressed,
        style: FilledButton.styleFrom(
          backgroundColor: Colors.white,
          foregroundColor: AppColors.primary,
          disabledBackgroundColor: Colors.white70,
          textStyle: const TextStyle(
            fontWeight: FontWeight.w700,
            fontSize: 16,
          ),
        ),
        child: Text(label),
      ),
    );
  }
}

class _SecondaryButton extends StatelessWidget {
  const _SecondaryButton({required this.label, required this.onPressed});

  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      height: 48,
      child: OutlinedButton(
        onPressed: onPressed,
        style: OutlinedButton.styleFrom(
          foregroundColor: Colors.white,
          side: BorderSide(color: Colors.white.withValues(alpha: 0.7)),
          textStyle: const TextStyle(fontWeight: FontWeight.w600),
        ),
        child: Text(label),
      ),
    );
  }
}

class _DangerButton extends StatelessWidget {
  const _DangerButton({required this.label, required this.onPressed});

  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 48,
      child: OutlinedButton(
        onPressed: onPressed,
        style: OutlinedButton.styleFrom(
          foregroundColor: Colors.white,
          backgroundColor: AppColors.error.withValues(alpha: 0.25),
          side: const BorderSide(color: Colors.white70),
          textStyle: const TextStyle(fontWeight: FontWeight.w700),
        ),
        child: Text(label),
      ),
    );
  }
}
