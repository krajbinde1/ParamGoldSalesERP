import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/company_transport_api.dart';

class CompanyTransportExpenseDetailScreen extends StatefulWidget {
  const CompanyTransportExpenseDetailScreen({
    super.key,
    required this.auth,
    required this.entryId,
  });

  final AuthController auth;
  final int entryId;

  @override
  State<CompanyTransportExpenseDetailScreen> createState() =>
      _CompanyTransportExpenseDetailScreenState();
}

class _CompanyTransportExpenseDetailScreenState
    extends State<CompanyTransportExpenseDetailScreen> {
  late Future<Map<String, dynamic>> _future;

  CompanyTransportApi get _api => CompanyTransportApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  @override
  void initState() {
    super.initState();
    _future = _api.entry(widget.entryId);
  }

  Widget _row(BuildContext context, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
            ),
          ),
          Expanded(
            child: Text(
              value.isEmpty ? '—' : value,
              textAlign: TextAlign.right,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Expense Details',
        auth: widget.auth,
        showBack: true,
        onBack: () => smartBack(context),
      ),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return PgEmptyState(
              message: errorMessage(snapshot.error!),
            );
          }
          final entry = snapshot.data ?? const {};
          final attachment = entry['attachment_url']?.toString() ?? '';

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            children: [
              PgCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${entry['particulars'] ?? 'Transport Expense'}',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: 12),
                    _row(context, 'Date', '${entry['transaction_date_label'] ?? entry['transaction_date'] ?? ''}'),
                    _row(context, 'Amount', '${entry['debit_label'] ?? entry['amount'] ?? ''}'),
                    _row(context, 'Expense Type', '${entry['expense_type_label'] ?? ''}'),
                    _row(context, 'Transport Type', '${entry['transport_type_label'] ?? ''}'),
                    _row(context, 'Vehicle No.', '${entry['vehicle_number'] ?? ''}'),
                    _row(context, 'Paid To', '${entry['paid_to'] ?? ''}'),
                    _row(context, 'Order No.', '${entry['order_no'] ?? ''}'),
                    _row(context, 'Payment Mode', '${entry['payment_mode_label'] ?? ''}'),
                    _row(context, 'Remark', '${entry['remark'] ?? ''}'),
                    _row(context, 'Entered By', '${entry['entered_by_name'] ?? ''}'),
                    _row(context, 'Role', '${entry['entered_by_role'] ?? ''}'),
                    _row(context, 'Entered At', '${entry['created_at'] ?? ''}'),
                  ],
                ),
              ),
              if (attachment.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.md),
                FilledButton.tonalIcon(
                  onPressed: () => launchUrl(Uri.parse(attachment)),
                  icon: const Icon(Icons.attach_file),
                  label: const Text('View attachment'),
                ),
              ],
            ],
          );
        },
      ),
    );
  }
}
