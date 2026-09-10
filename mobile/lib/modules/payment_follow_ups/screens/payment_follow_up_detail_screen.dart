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
import '../../../core/widgets/design/pg_status_badge.dart';
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

  PgStatusTone _statusTone(String? status) {
    final key = (status ?? '')
        .toLowerCase()
        .replaceAll(RegExp(r'[^a-z0-9]+'), '_');
    return switch (key) {
      'overdue' || 'missed' => PgStatusTone.rejected,
      'due_today' || 'pending' => PgStatusTone.pending,
      'closed' || 'kept' || 'open' => PgStatusTone.paid,
      'upcoming' => PgStatusTone.info,
      _ => PgStatusTone.neutral,
    };
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

            final openCycles = detail.cycles
                .where((cycle) => !cycle.isClosed)
                .toList();
            final previousCycles = detail.cycles
                .where((cycle) => cycle.isClosed)
                .toList()
                .reversed
                .toList();
            final hasOpenCycle = openCycles.isNotEmpty;
            final blockedMessage =
                (detail.nextFollowUpAvailableMessage ?? '').trim();
            final canFollowUpAgain = detail.canAddFollowUp && hasOpenCycle;
            final canAddFirst = detail.canAddFollowUp && !hasOpenCycle;

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
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                      if ((detail.village ?? '').isNotEmpty) ...[
                        const SizedBox(height: 4),
                        Text(
                          detail.village!,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.bodyMedium
                              ?.copyWith(color: AppColors.textSecondary),
                        ),
                      ],
                      const SizedBox(height: AppSpacing.md),
                      Text(
                        'Current Due',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              fontWeight: FontWeight.w700,
                            ),
                      ),
                      FittedBox(
                        fit: BoxFit.scaleDown,
                        alignment: Alignment.centerLeft,
                        child: Text(
                          detail.currentOutstandingLabel,
                          maxLines: 1,
                          style: Theme.of(context).textTheme.headlineSmall
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      Text(
                        'Last payment: ${detail.lastPaymentAmountLabel ?? '—'} on ${_formatDate(detail.lastPaymentDate)}',
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      const SizedBox(height: 8),
                      PgStatusBadge(
                        label: detail.statusLabel,
                        tone: _statusTone(detail.statusLabel),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                if (hasOpenCycle || canAddFirst) ...[
                  FilledButton(
                    onPressed: (canFollowUpAgain || canAddFirst) && !_saving
                        ? () => setState(() => _showForm = !_showForm)
                        : null,
                    child: Text(
                      hasOpenCycle ? 'Follow-up Again' : 'Add Follow-up',
                    ),
                  ),
                  if (hasOpenCycle &&
                      !detail.canAddFollowUp &&
                      blockedMessage.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Text(
                      blockedMessage,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: AppColors.warning,
                            fontWeight: FontWeight.w700,
                          ),
                    ),
                  ],
                ],
                if (_showForm && (canFollowUpAgain || canAddFirst)) ...[
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
                if (openCycles.isNotEmpty) ...[
                  const SizedBox(height: AppSpacing.lg),
                  Text(
                    'Current Open Cycle',
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  ...openCycles.map(
                    (cycle) => _cycleCard(cycle, isCurrent: true),
                  ),
                ],
                const SizedBox(height: AppSpacing.lg),
                Text(
                  'Previous Follow-up History',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
                const SizedBox(height: AppSpacing.sm),
                if (previousCycles.isEmpty)
                  const PgEmptyState(
                    message: 'No previous follow-up history yet.',
                    icon: Icon(Icons.history),
                  )
                else
                  ...previousCycles.map(
                    (cycle) => _cycleCard(cycle, isCurrent: false),
                  ),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _cycleCard(PaymentFollowUpCycle cycle, {required bool isCurrent}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: PgCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Text(
                    'Cycle #${cycle.cycleNumber}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                ),
                const SizedBox(width: 8),
                Flexible(
                  child: Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    alignment: WrapAlignment.end,
                    children: [
                      if (isCurrent)
                        const PgStatusBadge(
                          label: 'CURRENT',
                          tone: PgStatusTone.info,
                        ),
                      PgStatusBadge(
                        label: cycle.statusLabel,
                        tone: _statusTone(
                          cycle.displayStatus.isEmpty
                              ? cycle.statusLabel
                              : cycle.displayStatus,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            _CycleSummaryTable(
              rows: [
                (
                  'Current Due',
                  cycle.currentDueLabel ?? cycle.openingOutstandingLabel,
                  true,
                ),
                ('Follow-ups', '${cycle.followUpCount}', false),
                ('Commitments', '${cycle.commitmentCount}', false),
                ('Missed', '${cycle.missedCount}', false),
              ],
            ),
            if (cycle.entries.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.md),
              const Divider(height: 1, color: AppColors.border),
              const SizedBox(height: AppSpacing.md),
              ...cycle.entries.map(_entryCard),
            ],
            if (cycle.isClosed)
              Text(
                'Closing Outstanding: ${cycle.closingOutstandingLabel ?? '—'}',
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
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

  Widget _entryCard(PaymentFollowUpEntry entry) {
    final isPayment = entry.isPaymentReceived;
    final title = isPayment
        ? 'Payment Received'
        : 'Follow-up #${entry.followUpNumber ?? '—'}';
    final statusLabel = isPayment
        ? 'PAYMENT'
        : (entry.commitmentStatusLabel ?? 'FOLLOW-UP');

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: const Color(0xFFF8FAFC),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppColors.border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                ),
                const SizedBox(width: 8),
                PgStatusBadge(
                  label: statusLabel,
                  tone: isPayment
                      ? PgStatusTone.paid
                      : _statusTone(entry.commitmentStatus),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              entry.followUpAtLabel ?? _formatDate(entry.followUpDate),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                    fontWeight: FontWeight.w600,
                  ),
            ),
            if (!isPayment && entry.remark.trim().isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(
                entry.remark,
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ],
            const SizedBox(height: 10),
            if (isPayment)
              _MetricWrap(
                items: [
                  (
                    'Amount',
                    entry.paymentAmountLabel ?? entry.expectedAmountLabel ?? '—'
                  ),
                  ('Date', _formatDate(entry.paymentDate ?? entry.followUpDate)),
                  (
                    'Updated Current Due',
                    entry.updatedCurrentDueLabel ?? entry.outstandingLabel
                  ),
                ],
              )
            else
              _MetricWrap(
                items: [
                  ('Current Due', entry.outstandingLabel),
                  (
                    'Commitment Amount',
                    (entry.expectedAmountLabel ?? '').trim().isEmpty
                        ? '—'
                        : entry.expectedAmountLabel!
                  ),
                  (
                    'Commitment Date',
                    _formatDate(entry.nextFollowUpDate),
                  ),
                  ('Status', entry.commitmentStatusLabel ?? '—'),
                ],
              ),
          ],
        ),
      ),
    );
  }
}

class _CycleSummaryTable extends StatelessWidget {
  const _CycleSummaryTable({required this.rows});

  final List<(String, String, bool)> rows;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        for (var index = 0; index < rows.length; index++) ...[
          if (index > 0) const SizedBox(height: 8),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 110,
                child: Text(
                  rows[index].$1,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: AppColors.textSecondary,
                        fontWeight: FontWeight.w700,
                      ),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Align(
                  alignment: Alignment.centerRight,
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerRight,
                    child: Text(
                      rows[index].$2,
                      maxLines: 1,
                      style: (rows[index].$3
                              ? Theme.of(context).textTheme.titleMedium
                              : Theme.of(context).textTheme.bodyLarge)
                          ?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ],
      ],
    );
  }
}

class _MetricWrap extends StatelessWidget {
  const _MetricWrap({required this.items});

  final List<(String, String)> items;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final item in items)
          ConstrainedBox(
            constraints: const BoxConstraints(minWidth: 96, maxWidth: 160),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  item.$1,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                        color: AppColors.textMuted,
                        fontWeight: FontWeight.w700,
                      ),
                ),
                Text(
                  item.$2,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}
