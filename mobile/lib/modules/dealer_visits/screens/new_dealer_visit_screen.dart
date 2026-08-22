import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../../orders/api/dealer_api.dart';
import '../../orders/models/order_dealer.dart';
import '../api/dealer_visit_api.dart';
import '../services/dealer_visit_location_service.dart';

class NewDealerVisitScreen extends StatefulWidget {
  const NewDealerVisitScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<NewDealerVisitScreen> createState() => _NewDealerVisitScreenState();
}

class _NewDealerVisitScreenState extends State<NewDealerVisitScreen> {
  final _locationService = DealerVisitLocationService();
  final _firm = TextEditingController();
  final _owner = TextEditingController();
  final _mobile = TextEditingController();
  final _village = TextEditingController();
  final _taluka = TextEditingController();
  final _district = TextEditingController();
  final _remarks = TextEditingController();

  OrderDealer? _selectedDealer;
  bool _isProspective = false;
  String? _photoPath;
  DealerVisitLocationCapture? _location;
  bool _submitting = false;
  bool _capturingLocation = false;
  String? _locationError;
  late Future<List<OrderDealer>> _dealersFuture;

  static const Object _prospectiveDealerSentinel = Object();

  @override
  void initState() {
    super.initState();
    _dealersFuture = DealerApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    ).list();
    _captureLocation();
  }

  @override
  void dispose() {
    _firm.dispose();
    _owner.dispose();
    _mobile.dispose();
    _village.dispose();
    _taluka.dispose();
    _district.dispose();
    _remarks.dispose();
    super.dispose();
  }

  bool get _prospectiveDetailsValid {
    final mobile = _mobile.text.trim();
    return _firm.text.trim().isNotEmpty &&
        _owner.text.trim().isNotEmpty &&
        RegExp(r'^[6-9][0-9]{9}$').hasMatch(mobile) &&
        _village.text.trim().isNotEmpty &&
        _taluka.text.trim().isNotEmpty &&
        _district.text.trim().isNotEmpty;
  }

  bool get _canSubmit =>
      !_submitting &&
      !_capturingLocation &&
      _photoPath != null &&
      _location != null &&
      (_isProspective ? _prospectiveDetailsValid : _selectedDealer != null);

  Future<void> _captureLocation() async {
    setState(() {
      _capturingLocation = true;
      _locationError = null;
    });

    try {
      final location = await _locationService.capture();
      if (!mounted) return;
      setState(() => _location = location);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _location = null;
        _locationError = '$error';
      });
    } finally {
      if (mounted) setState(() => _capturingLocation = false);
    }
  }

  Future<void> _openDealerSelector() async {
    final dealers = await _dealersFuture;
    if (!mounted) return;

    final searchController = TextEditingController();
    final selected = await showModalBottomSheet<Object>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            final query = searchController.text.trim().toLowerCase();
            final filtered = dealers.where((dealer) {
              if (query.isEmpty) return true;
              return dealer.name.toLowerCase().contains(query) ||
                  (dealer.ownerName ?? '').toLowerCase().contains(query) ||
                  (dealer.mobile ?? '').contains(query);
            }).toList();

            return Padding(
              padding: EdgeInsets.only(
                left: 16,
                right: 16,
                top: 8,
                bottom: MediaQuery.viewInsetsOf(context).bottom + 16,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: searchController,
                    decoration: const InputDecoration(
                      labelText: 'Search dealer',
                      prefixIcon: Icon(Icons.search),
                    ),
                    onChanged: (_) => setModalState(() {}),
                  ),
                  const SizedBox(height: 12),
                  Flexible(
                    child: ListView.separated(
                      shrinkWrap: true,
                      itemCount: filtered.length + 1,
                      separatorBuilder: (_, _) => const Divider(height: 1),
                      itemBuilder: (context, index) {
                        if (index == 0) {
                          return ListTile(
                            leading: const Icon(Icons.add_business_outlined),
                            title: const Text('Other / New Prospective Dealer'),
                            subtitle: const Text(
                              'Save as Prospective Dealer Visit only',
                            ),
                            onTap: () => Navigator.pop(
                              context,
                              _prospectiveDealerSentinel,
                            ),
                          );
                        }

                        final dealer = filtered[index - 1];
                        return ListTile(
                          title: Text(
                            dealer.name,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                          subtitle: Text(
                            [dealer.ownerName, dealer.mobile]
                                .where(
                                  (part) => part != null && part.isNotEmpty,
                                )
                                .join(' • '),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                          onTap: () => Navigator.pop(context, dealer),
                        );
                      },
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );

    searchController.dispose();
    if (selected == _prospectiveDealerSentinel) {
      setState(() {
        _isProspective = true;
        _selectedDealer = null;
      });
      return;
    }
    if (selected is OrderDealer) {
      setState(() {
        _isProspective = false;
        _selectedDealer = selected;
      });
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

  Future<void> _replacePhoto() async => _capturePhoto();

  void _clearForm() {
    setState(() {
      _selectedDealer = null;
      _isProspective = false;
      _firm.clear();
      _owner.clear();
      _mobile.clear();
      _village.clear();
      _taluka.clear();
      _district.clear();
      _remarks.clear();
      _photoPath = null;
      _location = null;
      _locationError = null;
    });
    _captureLocation();
  }

  Future<void> _submit() async {
    if (!_canSubmit) return;

    setState(() => _submitting = true);

    try {
      await _captureLocation();
      final location = _location;
      if (location == null) {
        throw const DealerVisitLocationException(
          'Location is required to submit dealer visit.',
        );
      }

      final api = DealerVisitApi(
        ApiClient(
          SessionStore(),
          onUnauthorized: widget.auth.sessionExpired,
        ).dio,
      );

      final message = await api.submit(
        dealerId: _isProspective ? null : _selectedDealer!.id,
        isProspective: _isProspective,
        firmName: _isProspective ? _firm.text.trim() : null,
        ownerName: _isProspective ? _owner.text.trim() : null,
        mobile: _isProspective ? _mobile.text.trim() : null,
        village: _isProspective ? _village.text.trim() : null,
        taluka: _isProspective ? _taluka.text.trim() : null,
        district: _isProspective ? _district.text.trim() : null,
        remarks: _remarks.text.trim().isEmpty ? null : _remarks.text.trim(),
        latitude: location.latitude,
        longitude: location.longitude,
        accuracy: location.accuracy,
        locationCapturedAt: location.capturedAt,
        photoPath: _photoPath!,
      );

      if (!mounted) return;

      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(message)));
      _clearForm();
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
      title: 'New Dealer Visit',
      showBack: true,
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screenPadding),
        children: [
          PgCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Select Dealer',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: AppSpacing.sm),
                OutlinedButton(
                  onPressed: _submitting ? null : _openDealerSelector,
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: Text(
                      _isProspective
                          ? 'Other / New Prospective Dealer'
                          : _selectedDealer?.name ?? 'Choose dealer',
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.bodyLarge,
                    ),
                  ),
                ),
              ],
            ),
          ),
          if (_isProspective) ...[
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Prospective Dealer Details',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextField(
                    controller: _firm,
                    enabled: !_submitting,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(
                      labelText: 'Firm / Dealer Name',
                    ),
                    onChanged: (_) => setState(() {}),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextField(
                    controller: _owner,
                    enabled: !_submitting,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(
                      labelText: 'Owner Name',
                    ),
                    onChanged: (_) => setState(() {}),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextField(
                    controller: _mobile,
                    enabled: !_submitting,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(
                      labelText: 'Mobile Number',
                    ),
                    onChanged: (_) => setState(() {}),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextField(
                    controller: _village,
                    enabled: !_submitting,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(
                      labelText: 'Village',
                    ),
                    onChanged: (_) => setState(() {}),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextField(
                    controller: _taluka,
                    enabled: !_submitting,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(
                      labelText: 'Taluka',
                    ),
                    onChanged: (_) => setState(() {}),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextField(
                    controller: _district,
                    enabled: !_submitting,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(
                      labelText: 'District',
                    ),
                    onChanged: (_) => setState(() {}),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextField(
                    controller: _remarks,
                    enabled: !_submitting,
                    maxLines: 3,
                    decoration: const InputDecoration(
                      labelText: 'Remarks',
                    ),
                    onChanged: (_) => setState(() {}),
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: AppSpacing.md),
          PgCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Capture Photo',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: AppSpacing.sm),
                if (_photoPath == null)
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: _submitting ? null : _capturePhoto,
                      child: const Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.photo_camera_outlined),
                          SizedBox(width: 8),
                          Flexible(
                            child: Text(
                              'Take Photo',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                    ),
                  )
                else ...[
                  ClipRRect(
                    borderRadius: BorderRadius.circular(12),
                    child: AspectRatio(
                      aspectRatio: 16 / 9,
                      child: Image.file(
                        File(_photoPath!),
                        width: double.infinity,
                        fit: BoxFit.cover,
                      ),
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  Wrap(
                    spacing: 8,
                    runSpacing: 4,
                    children: [
                      TextButton(
                        onPressed: _submitting ? null : _replacePhoto,
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
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Capture Current Location',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: AppSpacing.sm),
                if (_capturingLocation)
                  const Row(
                    children: [
                      SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                      SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          'Capturing GPS location...',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ],
                  )
                else if (_location != null) ...[
                  Row(
                    children: [
                      Icon(
                        Icons.check_circle_outline,
                        color: AppColors.approvedFg,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'Location Captured',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.titleSmall?.copyWith(
                            color: AppColors.approvedFg,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  Text(
                    _location!.summary ??
                        'Lat ${_location!.latitude.toStringAsFixed(7)}, '
                            'Lng ${_location!.longitude.toStringAsFixed(7)}',
                  ),
                  Text(
                    'Accuracy: ${_location!.accuracy.toStringAsFixed(1)} m',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ] else
                  Text(
                    _locationError ??
                        'Location is required before submission.',
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                const SizedBox(height: AppSpacing.sm),
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton(
                    onPressed: _capturingLocation || _submitting
                        ? null
                        : _captureLocation,
                    child: const Text('Refresh Location'),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: _canSubmit ? _submit : null,
              child: _submitting
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text(
                      'Submit Dealer Visit',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
            ),
          ),
        ],
      ),
    );
  }
}
