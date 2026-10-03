import 'package:flutter/material.dart';
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
import '../../credit_limits/models/dealer_credit_status.dart';
import '../api/manager_dealer_credit_api.dart';

class ManagerDealerCreditDetailScreen extends StatefulWidget {
  const ManagerDealerCreditDetailScreen({
    super.key,
    required this.auth,
    required this.dealerId,
  });

  final AuthController auth;
  final int dealerId;

  @override
  State<ManagerDealerCreditDetailScreen> createState() =>
      _ManagerDealerCreditDetailScreenState();
}

class _ManagerDealerCreditDetailScreenState
    extends State<ManagerDealerCreditDetailScreen> {
  final _money = NumberFormat.currency(
    locale: 'en_IN',
    symbol: '₹',
    decimalDigits: 2,
  );

  DealerCreditStatus? _credit;
  List<DealerCreditHistoryEntry> _history = const [];
  Object? _error;
  bool _loading = true;
  bool _saving = false;

  ManagerDealerCreditApi get _api => ManagerDealerCreditApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _reload();
  }

  Future<void> _reload() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final results = await Future.wait([
        _api.show(widget.dealerId),
        _api.history(widget.dealerId),
      ]);
      if (!mounted) return;
      setState(() {
        _credit = results[0] as DealerCreditStatus;
        _history = results[1] as List<DealerCreditHistoryEntry>;
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _loading = false;
      });
    }
  }

  Future<void> _setBase(DealerCreditStatus credit) async {
    final amount = TextEditingController(
      text: credit.baseLimit?.toStringAsFixed(2) ?? '',
    );
    final remark = TextEditingController();
    final saved = await _dialog(
      title: credit.limitSet ? 'Edit base limit' : 'Set base limit',
      fields: [
        TextField(
          controller: amount,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(labelText: 'Base limit'),
        ),
        TextField(
          controller: remark,
          decoration: const InputDecoration(labelText: 'Remark'),
        ),
      ],
    );
    if (saved != true || !mounted) return;
    final parsed = double.tryParse(amount.text.trim());
    if (parsed == null || parsed <= 0 || remark.text.trim().isEmpty) {
      _toast('Amount and remark are required.');
      return;
    }
    await _run(
      () => _api.setBase(
        dealerId: widget.dealerId,
        amount: parsed,
        remark: remark.text.trim(),
      ),
    );
  }

  Future<void> _extend() async {
    final amount = TextEditingController();
    final remark = TextEditingController();
    DateTime? until;
    final saved = await showDialog<bool>(
      context: context,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setLocal) {
            return AlertDialog(
              title: const Text('Temporary extension'),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: amount,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(
                      labelText: 'Extension amount',
                    ),
                  ),
                  const SizedBox(height: 12),
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: const Text('Valid until'),
                    subtitle: Text(
                      until == null
                          ? 'Select a date'
                          : DateFormat('d MMM yyyy').format(until!),
                    ),
                    trailing: const Icon(Icons.calendar_today),
                    onTap: () async {
                      final picked = await showDatePicker(
                        context: context,
                        firstDate: DateTime.now(),
                        lastDate: DateTime.now().add(const Duration(days: 3650)),
                        initialDate: until ?? DateTime.now(),
                      );
                      if (picked != null) setLocal(() => until = picked);
                    },
                  ),
                  TextField(
                    controller: remark,
                    decoration: const InputDecoration(labelText: 'Remark'),
                  ),
                ],
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(context, false),
                  child: const Text('Cancel'),
                ),
                FilledButton(
                  onPressed: () => Navigator.pop(context, true),
                  child: const Text('Save'),
                ),
              ],
            );
          },
        );
      },
    );
    if (saved != true || !mounted) return;
    final parsed = double.tryParse(amount.text.trim());
    final chosenUntil = until;
    if (parsed == null ||
        parsed <= 0 ||
        chosenUntil == null ||
        remark.text.trim().isEmpty) {
      _toast('Amount, valid until, and remark are required.');
      return;
    }
    final validUntil = DateFormat('yyyy-MM-dd').format(chosenUntil);
    await _run(
      () => _api.extend(
        dealerId: widget.dealerId,
        amount: parsed,
        validUntil: validUntil,
        remark: remark.text.trim(),
      ),
    );
  }

  Future<void> _expire() async {
    final remark = TextEditingController();
    final saved = await _dialog(
      title: 'Remove extension',
      fields: [
        TextField(
          controller: remark,
          decoration: const InputDecoration(labelText: 'Remark'),
        ),
      ],
    );
    if (saved != true || !mounted) return;
    if (remark.text.trim().isEmpty) {
      _toast('Remark is required.');
      return;
    }
    await _run(
      () => _api.expire(dealerId: widget.dealerId, remark: remark.text.trim()),
    );
  }

  Future<bool?> _dialog({
    required String title,
    required List<Widget> fields,
  }) {
    return showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: Column(mainAxisSize: MainAxisSize.min, children: fields),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Save'),
          ),
        ],
      ),
    );
  }

  Future<void> _run(Future<DealerCreditStatus> Function() action) async {
    setState(() => _saving = true);
    try {
      await action();
      if (!mounted) return;
      _toast('Credit limit saved.');
      await _reload();
    } catch (error) {
      if (!mounted) return;
      _toast(errorMessage(error));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  void _toast(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  String _amount(double? value, {required bool limitSet}) {
    if (!limitSet || value == null) return 'Not Set';
    return _money.format(value);
  }

  @override
  Widget build(BuildContext context) {
    final credit = _credit;
    return PgPageScaffold(
      title: 'Dealer Credit Limit',
      showBack: true,
      body: _loading
          ? const PgLoadingState()
          : _error != null
          ? PgErrorState(message: errorMessage(_error), onRetry: _reload)
          : RefreshIndicator(
              onRefresh: _reload,
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  Text(
                    credit?.dealerName ?? 'Dealer',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  if ((credit?.district ?? '').isNotEmpty)
                    Text(credit!.district!),
                  const SizedBox(height: AppSpacing.md),
                  PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _row(
                          'Outstanding',
                          _money.format(credit?.currentOutstanding ?? 0),
                        ),
                        _row(
                          'Base limit',
                          _amount(credit?.baseLimit, limitSet: credit?.limitSet ?? false),
                        ),
                        _row(
                          'Available',
                          _amount(
                            credit?.availableLimit,
                            limitSet: credit?.limitSet ?? false,
                          ),
                        ),
                        _row(
                          'Status',
                          credit?.limitSet == true
                              ? (credit?.statusLabel ?? '')
                              : 'No Limit Set',
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.md),
                  FilledButton(
                    onPressed: _saving || credit == null
                        ? null
                        : () => _setBase(credit),
                    child: Text(credit?.limitSet == true ? 'Edit base limit' : 'Set base limit'),
                  ),
                  if (credit?.limitSet == true) ...[
                    const SizedBox(height: 8),
                    OutlinedButton(
                      onPressed: _saving ? null : _extend,
                      child: const Text('Extend limit'),
                    ),
                  ],
                  if (credit?.extensionActive == true) ...[
                    const SizedBox(height: 8),
                    OutlinedButton(
                      onPressed: _saving ? null : _expire,
                      child: const Text('Remove extension'),
                    ),
                  ],
                  const SizedBox(height: AppSpacing.lg),
                  Text('History', style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: AppSpacing.sm),
                  if (_history.isEmpty)
                    const Text('No credit limit history yet.')
                  else
                    ..._history.map(
                      (entry) => Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: PgCard(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                entry.action,
                                style: const TextStyle(fontWeight: FontWeight.w700),
                              ),
                              if ((entry.createdAt ?? '').isNotEmpty)
                                Text(
                                  entry.createdAt!,
                                  style: const TextStyle(color: AppColors.textSecondary),
                                ),
                              if (entry.previousBase != null)
                                Text('Previous base ${_money.format(entry.previousBase)}'),
                              if (entry.newBase != null)
                                Text('New base ${_money.format(entry.newBase)}'),
                              if (entry.extensionAmount != null)
                                Text('Extension ${_money.format(entry.extensionAmount)}'),
                              if (entry.effectiveLimit != null)
                                Text('Effective ${_money.format(entry.effectiveLimit)}'),
                              if ((entry.validUntil ?? '').isNotEmpty)
                                Text('Valid until ${entry.validUntil}'),
                              if ((entry.remark ?? '').isNotEmpty)
                                Text(entry.remark!),
                              Text(
                                [
                                  entry.changedBy ?? 'System',
                                  entry.changedByRole ?? '',
                                ].where((part) => part.isNotEmpty).join(' · '),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),
    );
  }

  Widget _row(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        children: [
          Expanded(child: Text(label)),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w700)),
        ],
      ),
    );
  }
}
