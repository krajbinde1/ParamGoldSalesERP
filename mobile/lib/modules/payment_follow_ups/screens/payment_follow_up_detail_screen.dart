import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/payment_follow_up_api.dart';
import '../models/payment_follow_up.dart';

final _date = DateFormat('dd MMM yyyy');

class PaymentFollowUpDetailScreen extends StatefulWidget {
  const PaymentFollowUpDetailScreen({
    super.key,
    required this.auth,
    required this.dealerId,
  });

  final AuthController auth;
  final int dealerId;

  @override
  State<PaymentFollowUpDetailScreen> createState() =>
      _PaymentFollowUpDetailScreenState();
}

class _PaymentFollowUpDetailScreenState
    extends State<PaymentFollowUpDetailScreen> {
  late Future<PaymentFollowUpDetail> _future;
  final _remark = TextEditingController();
  final _expected = TextEditingController();
  DateTime? _nextDate;
  bool _saving = false;
  bool _showForm = false;

  PaymentFollowUpApi get _api => PaymentFollowUpApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.show(widget.dealerId);
  }

  @override
  void dispose() {
    _remark.dispose();
    _expected.dispose();
    super.dispose();
  }

  Future<void> _reload() async {
    setState(() => _future = _api.show(widget.dealerId));
    await _future;
  }

  String _formatDate(String? value) {
    if (value == null || value.isEmpty) return '—';
    final parsed = DateTime.tryParse(value);
    return parsed == null ? value : _date.format(parsed);
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _nextDate ?? now,
      firstDate: DateTime(now.year, now.month, now.day),
      lastDate: DateTime(now.year + 2),
    );
    if (picked != null) {
      setState(() => _nextDate = picked);
    }
  }

  Future<void> _save() async {
    final remark = _remark.text.trim();
    if (remark.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Follow-up remark is required.')),
      );
      return;
    }
    if (_nextDate == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Next follow-up date is required.')),
      );
      return;
    }

    final expectedText = _expected.text.trim().replaceAll(',', '');
    final expected = expectedText.isEmpty ? null : double.tryParse(expectedText);
    if (expectedText.isNotEmpty && (expected == null || expected <= 0)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Enter a valid expected amount.')),
      );
      return;
    }

    setState(() => _saving = true);
    try {
      final detail = await _api.addFollowUp(
        dealerId: widget.dealerId,
        remark: remark,
        expectedAmount: expected,
        nextFollowUpDate: DateFormat('yyyy-MM-dd').format(_nextDate!),
      );
      if (!mounted) return;
      _remark.clear();
      _expected.clear();
      _nextDate = null;
      setState(() {
        _showForm = false;
        _future = Future.value(detail);
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Follow-up saved.')),
      );
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      auth: widget.auth,
      title: 'Payment Follow-up',
      showBack: true,
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<PaymentFollowUpDetail>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting &&
                !snapshot.hasData) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: const [PgLoadingState()],
              );
            }

            if (snapshot.hasError) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgErrorState(
                    message: errorMessage(snapshot.error),
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final detail = snapshot.data;
            if (detail == null) {
              return const PgEmptyState(message: 'Dealer not found.');
            }

            final hasOpenCycle = detail.cycles.any((cycle) => !cycle.isClosed);

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                PgCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        detail.dealerName,
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                      if ((detail.village ?? '').isNotEmpty) ...[
                        const SizedBox(height: 4),
                        Text(
                          detail.village!,
                          style: Theme.of(context).textTheme.bodyMedium
                              ?.copyWith(color: AppColors.textSecondary),
                        ),
                      ],
                      const SizedBox(height: AppSpacing.md),
                      Text(
                        'Current Outstanding',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      Text(
                        detail.currentOutstandingLabel,
                        style: Theme.of(context).textTheme.headlineSmall
                            ?.copyWith(fontWeight: FontWeight.w800),
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      Text(
                        'Last payment: ${detail.lastPaymentAmountLabel ?? '—'} on ${_formatDate(detail.lastPaymentDate)}',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      const SizedBox(height: 4),
                      Text(
                        detail.statusLabel,
                        style: Theme.of(context).textTheme.labelLarge?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                if (detail.canAddFollowUp)
                  FilledButton(
                    onPressed: () => setState(() => _showForm = !_showForm),
                    child: Text(
                      hasOpenCycle ? 'Follow-up Again' : 'Add Follow-up',
                    ),
                  ),
                if (_showForm) ...[
                  const SizedBox(height: AppSpacing.md),
                  PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        TextFormField(
                          controller: _remark,
                          maxLines: 3,
                          decoration: const InputDecoration(
                            labelText: 'Follow-up Remark *',
                          ),
                        ),
                        const SizedBox(height: AppSpacing.md),
                        TextFormField(
                          controller: _expected,
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          inputFormatters: [
                            FilteringTextInputFormatter.allow(
                              RegExp(r'[0-9.]'),
                            ),
                          ],
                          decoration: const InputDecoration(
                            labelText: 'Expected Payment Amount',
                            prefixText: '₹ ',
                          ),
                        ),
                        const SizedBox(height: AppSpacing.md),
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          title: const Text('Next Payment / Follow-up Date *'),
                          subtitle: Text(
                            _nextDate == null
                                ? 'Select date'
                                : _date.format(_nextDate!),
                          ),
                          trailing: const Icon(Icons.calendar_today_outlined),
                          onTap: _pickDate,
                        ),
                        const SizedBox(height: AppSpacing.md),
                        FilledButton(
                          onPressed: _saving ? null : _save,
                          child: Text(_saving ? 'Saving…' : 'Save Follow-up'),
                        ),
                      ],
                    ),
                  ),
                ],
                const SizedBox(height: AppSpacing.lg),
                Text(
                  'Previous Follow-up History',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: AppSpacing.sm),
                if (detail.cycles.isEmpty)
                  const PgEmptyState(
                    message: 'No follow-up history yet.',
                    icon: Icon(Icons.history),
                  )
                else
                  ...detail.cycles.reversed.map(_cycleCard),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _cycleCard(PaymentFollowUpCycle cycle) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: PgCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'PAYMENT FOLLOW-UP CYCLE #${cycle.cycleNumber}',
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 4),
            Text('Opening Outstanding: ${cycle.openingOutstandingLabel}'),
            const SizedBox(height: AppSpacing.sm),
            ...cycle.entries.map(
              (entry) => Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${_formatDate(entry.followUpDate)}  ·  ${entry.isPaymentReceived ? 'Payment Received' : 'Follow-up'}',
                      style: Theme.of(context).textTheme.labelLarge?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    Text(entry.remark),
                    Text(
                      [
                        'Outstanding: ${entry.outstandingLabel}',
                        if ((entry.expectedAmountLabel ?? '').isNotEmpty)
                          'Expected: ${entry.expectedAmountLabel}',
                        if ((entry.nextFollowUpDate ?? '').isNotEmpty)
                          'Next: ${_formatDate(entry.nextFollowUpDate)}',
                      ].join('  ·  '),
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            if (cycle.isClosed)
              Text(
                'Closing Outstanding: ${cycle.closingOutstandingLabel ?? '—'}  ·  ${cycle.statusLabel}',
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: AppColors.success,
                  fontWeight: FontWeight.w700,
                ),
              ),
          ],
        ),
      ),
    );
  }
}
