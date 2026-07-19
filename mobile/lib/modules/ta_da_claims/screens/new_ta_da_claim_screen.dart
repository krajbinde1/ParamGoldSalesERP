import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/ta_da_claim_api.dart';
import '../models/ta_da_travel_summary.dart';
import '../widgets/ta_da_claim_calendar.dart';

class NewTaDaClaimScreen extends StatefulWidget {
  const NewTaDaClaimScreen({
    super.key,
    required this.auth,
    this.initialClaimDate,
  });
  final AuthController auth;
  final DateTime? initialClaimDate;

  @override
  State<NewTaDaClaimScreen> createState() => _NewTaDaClaimScreenState();
}

class _NewTaDaClaimScreenState extends State<NewTaDaClaimScreen> {
  final _formKey = GlobalKey<FormState>();
  final _fromLocationController = TextEditingController();
  final _toLocationController = TextEditingController();
  final _daAmountController = TextEditingController(text: '0');
  final _otherAmountController = TextEditingController(text: '0');
  final _remarksController = TextEditingController();

  DateTime _claimDate = DateTime.now();
  final Set<DateTime> _blockedDates = {};
  TaDaTravelSummary? _travelSummary;
  String? _photoPath;
  bool _submitting = false;
  bool _loadingTravelSummary = true;
  String? _travelSummaryError;

  NumberFormat get _currency =>
      NumberFormat.currency(locale: 'en_IN', symbol: '₹', decimalDigits: 2);

  double get _daAmount => double.tryParse(_daAmountController.text.trim()) ?? 0;

  double get _otherAmount =>
      double.tryParse(_otherAmountController.text.trim()) ?? 0;

  double get _travelAmount => _travelSummary?.travelAmount ?? 0;

  double get _totalAmount =>
      _travelAmount +
      _daAmount.clamp(0, double.infinity) +
      _otherAmount.clamp(0, double.infinity);

  bool get _routeAvailable =>
      _travelSummary != null &&
      _travelSummary!.routeAvailable &&
      _travelSummary!.travelKm > 0;

  bool get _canSubmit =>
      !_submitting &&
      !_loadingTravelSummary &&
      _routeAvailable &&
      _photoPath != null &&
      !_blockedDates.contains(_claimDate) &&
      _fromLocationController.text.trim().isNotEmpty &&
      _toLocationController.text.trim().isNotEmpty;

  TaDaClaimApi get _api => TaDaClaimApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _claimDate = TaDaClaimCalendar.dateOnly(
      widget.initialClaimDate ?? DateTime.now(),
    );
    _loadBlockedDatesForMonth(_claimDate.year, _claimDate.month);
    _loadTravelSummary();
  }

  @override
  void dispose() {
    _fromLocationController.dispose();
    _toLocationController.dispose();
    _daAmountController.dispose();
    _otherAmountController.dispose();
    _remarksController.dispose();
    super.dispose();
  }

  Future<void> _loadTravelSummary() async {
    setState(() {
      _loadingTravelSummary = true;
      _travelSummaryError = null;
      _travelSummary = null;
    });

    try {
      final summary = await _api.fetchTravelSummary(claimDate: _claimDate);
      if (!mounted) return;
      setState(() => _travelSummary = summary);
    } on DioException catch (error) {
      if (!mounted) return;
      setState(() {
        _travelSummary = null;
        _travelSummaryError =
            error.message ?? 'Route distance is unavailable for this date.';
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _travelSummary = null;
        _travelSummaryError = '$error';
      });
    } finally {
      if (mounted) setState(() => _loadingTravelSummary = false);
    }
  }

  Future<void> _loadBlockedDatesForMonth(int year, int month) async {
    try {
      final calendar = await _api.loadCalendar(month: month, year: year);

      if (!mounted) return;
      setState(() {
        _blockedDates.addAll(calendar.claimsByDate.keys);
      });
    } catch (_) {}
  }

  Future<void> _pickClaimDate() async {
    await _loadBlockedDatesForMonth(_claimDate.year, _claimDate.month);

    if (!mounted) return;

    final picked = await showDatePicker(
      context: context,
      initialDate: _claimDate,
      firstDate: DateTime(2020),
      lastDate: TaDaClaimCalendar.today,
      selectableDayPredicate: (date) {
        final normalized = TaDaClaimCalendar.dateOnly(date);
        return !normalized.isAfter(TaDaClaimCalendar.today) &&
            !_blockedDates.contains(normalized);
      },
    );
    if (picked != null) {
      setState(() => _claimDate = TaDaClaimCalendar.dateOnly(picked));
      await _loadBlockedDatesForMonth(picked.year, picked.month);
      await _loadTravelSummary();
    }
  }

  Future<void> _capturePhoto() async {
    final image = await ImagePicker().pickImage(
      source: ImageSource.camera,
      imageQuality: 78,
      maxWidth: 1440,
    );
    if (image == null) return;
    setState(() => _photoPath = image.path);
  }

  void _recalculate() => setState(() {});

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate() || !_canSubmit) return;

    setState(() => _submitting = true);

    try {
      final message = await _api.submit(
        claimDate: _claimDate,
        fromLocation: _fromLocationController.text,
        toLocation: _toLocationController.text,
        daAmount: _daAmount,
        otherExpense: _otherAmount,
        employeeRemarks: _remarksController.text,
        photoPath: _photoPath!,
      );

      if (!mounted) return;

      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(message)));
      safePop(context, true);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('$error')));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      title: 'New TA/DA Claim',
      showBack: true,
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.screenPadding),
          children: [
            PgCard(
              onTap: _submitting ? null : _pickClaimDate,
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Claim Date',
                          style: Theme.of(context).textTheme.labelLarge,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          DateFormat('d MMM yyyy').format(_claimDate),
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                      ],
                    ),
                  ),
                  const Icon(Icons.calendar_today_outlined),
                ],
              ),
            ),
            if (_blockedDates.contains(_claimDate))
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.sm),
                child: Text(
                  'A claim already exists for this date. Choose another date.',
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            if (_loadingTravelSummary)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: AppSpacing.sm),
                child: LinearProgressIndicator(),
              ),
            if (_travelSummaryError != null)
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.sm),
                child: Text(
                  _travelSummaryError!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Route Details',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _fromLocationController,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(labelText: 'From Location'),
                    validator: (value) {
                      if (value == null || value.trim().isEmpty) {
                        return 'From location is required.';
                      }
                      return null;
                    },
                    onChanged: (_) => _recalculate(),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _toLocationController,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(labelText: 'To Location'),
                    validator: (value) {
                      if (value == null || value.trim().isEmpty) {
                        return 'To location is required.';
                      }
                      return null;
                    },
                    onChanged: (_) => _recalculate(),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  PgInvoiceRow(
                    label: 'Travel KM (from route)',
                    value: _loadingTravelSummary
                        ? 'Loading...'
                        : _routeAvailable
                        ? _travelSummary!.travelKm.toStringAsFixed(2)
                        : '-',
                  ),
                  PgInvoiceRow(
                    label: 'Per KM Rate',
                    value: _loadingTravelSummary
                        ? 'Loading...'
                        : _routeAvailable
                        ? _currency.format(_travelSummary!.perKmRate)
                        : '-',
                  ),
                  PgInvoiceRow(
                    label: 'Travel Amount',
                    value: _loadingTravelSummary
                        ? 'Loading...'
                        : _routeAvailable
                        ? _currency.format(_travelAmount)
                        : '-',
                  ),
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Amounts',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _daAmountController,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    inputFormatters: [
                      FilteringTextInputFormatter.allow(RegExp(r'^\d*\.?\d{0,2}')),
                    ],
                    decoration: const InputDecoration(labelText: 'DA Amount'),
                    validator: (value) {
                      final amount = double.tryParse(value?.trim() ?? '');
                      if (amount == null || amount < 0) {
                        return 'DA amount cannot be negative.';
                      }
                      return null;
                    },
                    onChanged: (_) => _recalculate(),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _otherAmountController,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    inputFormatters: [
                      FilteringTextInputFormatter.allow(RegExp(r'^\d*\.?\d{0,2}')),
                    ],
                    decoration: const InputDecoration(labelText: 'Other Amount'),
                    validator: (value) {
                      final amount = double.tryParse(value?.trim() ?? '');
                      if (amount == null || amount < 0) {
                        return 'Other amount cannot be negative.';
                      }
                      return null;
                    },
                    onChanged: (_) => _recalculate(),
                  ),
                  const Divider(height: AppSpacing.lg),
                  PgInvoiceRow(
                    label: 'Total Claim Amount',
                    value: _currency.format(_totalAmount),
                    isTotal: true,
                  ),
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Bill Photo',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  if (_photoPath == null)
                    FilledButton.icon(
                      onPressed: _submitting ? null : _capturePhoto,
                      icon: const Icon(Icons.photo_camera_outlined),
                      label: const Text('Take Photo'),
                    )
                  else ...[
                    ClipRRect(
                      borderRadius: BorderRadius.circular(12),
                      child: Image.file(
                        File(_photoPath!),
                        height: 180,
                        width: double.infinity,
                        fit: BoxFit.cover,
                      ),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    Row(
                      children: [
                        TextButton(
                          onPressed: _submitting ? null : _capturePhoto,
                          child: const Text('Retake'),
                        ),
                        TextButton(
                          onPressed: _submitting
                              ? null
                              : () => setState(() => _photoPath = null),
                          child: const Text('Remove'),
                        ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: TextFormField(
                controller: _remarksController,
                maxLines: 3,
                decoration: const InputDecoration(
                  labelText: 'Remark',
                  border: InputBorder.none,
                ),
              ),
            ),
            const SizedBox(height: AppSpacing.lg),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: FilledButton(
                onPressed: _canSubmit ? _submit : null,
                child: _submitting
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Submit Claim'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
