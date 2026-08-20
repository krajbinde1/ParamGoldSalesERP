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

  OrderDealer? _selectedDealer;
  String? _photoPath;
  DealerVisitLocationCapture? _location;
  bool _submitting = false;
  bool _capturingLocation = false;
  String? _locationError;
  late Future<List<OrderDealer>> _dealersFuture;

  @override
  void initState() {
    super.initState();
    _dealersFuture = DealerApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    ).list();
    _captureLocation();
  }

  bool get _canSubmit =>
      !_submitting &&
      !_capturingLocation &&
      _selectedDealer != null &&
      _photoPath != null &&
      _location != null;

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
    final selected = await showModalBottomSheet<OrderDealer>(
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
                      itemCount: filtered.length,
                      separatorBuilder: (_, _) => const Divider(height: 1),
                      itemBuilder: (context, index) {
                        final dealer = filtered[index];
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
    if (selected != null) setState(() => _selectedDealer = selected);
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
        dealerId: _selectedDealer!.id,
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
                      _selectedDealer?.name ?? 'Choose dealer',
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.bodyLarge,
                    ),
                  ),
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
