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
import '../api/field_activity_api.dart';
import '../services/field_activity_location_service.dart';

class NewFieldActivityScreen extends StatefulWidget {
  const NewFieldActivityScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<NewFieldActivityScreen> createState() => _NewFieldActivityScreenState();
}

class _NewFieldActivityScreenState extends State<NewFieldActivityScreen> {
  final _formKey = GlobalKey<FormState>();
  final _farmerNameController = TextEditingController();
  final _villageController = TextEditingController();
  final _talukaController = TextEditingController();
  final _locationService = FieldActivityLocationService();

  String? _photoPath;
  double? _latitude;
  double? _longitude;
  bool _submitting = false;
  bool _capturingLocation = false;
  String? _locationError;

  @override
  void initState() {
    super.initState();
    _captureLocation();
  }

  @override
  void dispose() {
    _farmerNameController.dispose();
    _villageController.dispose();
    _talukaController.dispose();
    super.dispose();
  }

  bool get _canSubmit =>
      !_submitting &&
      !_capturingLocation &&
      _photoPath != null &&
      _latitude != null &&
      _longitude != null &&
      _farmerNameController.text.trim().isNotEmpty &&
      _villageController.text.trim().isNotEmpty &&
      _talukaController.text.trim().isNotEmpty;

  Future<void> _captureLocation() async {
    setState(() {
      _capturingLocation = true;
      _locationError = null;
    });

    try {
      final location = await _locationService.capture();
      if (!mounted) return;
      setState(() {
        _latitude = location.latitude;
        _longitude = location.longitude;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _latitude = null;
        _longitude = null;
        _locationError = '$error';
      });
    } finally {
      if (mounted) setState(() => _capturingLocation = false);
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

  void _clearForm() {
    _farmerNameController.clear();
    _villageController.clear();
    _talukaController.clear();
    setState(() {
      _photoPath = null;
      _latitude = null;
      _longitude = null;
      _locationError = null;
    });
    _captureLocation();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate() || !_canSubmit) return;

    setState(() => _submitting = true);

    try {
      await _captureLocation();
      if (_latitude == null || _longitude == null) {
        throw const FieldActivityLocationException(
          'Location is required to submit field activity.',
        );
      }

      final api = FieldActivityApi(
        ApiClient(
          SessionStore(),
          onUnauthorized: widget.auth.sessionExpired,
        ).dio,
      );

      final message = await api.submit(
        farmerName: _farmerNameController.text,
        village: _villageController.text,
        taluka: _talukaController.text,
        latitude: _latitude!,
        longitude: _longitude!,
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
      title: 'New Field Activity',
      showBack: true,
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.screenPadding),
          children: [
            PgCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Farmer Details',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _farmerNameController,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(labelText: 'Farmer Name'),
                    validator: (value) {
                      if (value == null || value.trim().isEmpty) {
                        return 'Farmer name is required.';
                      }
                      return null;
                    },
                    onChanged: (_) => setState(() {}),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _villageController,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(labelText: 'Village'),
                    validator: (value) {
                      if (value == null || value.trim().isEmpty) {
                        return 'Village is required.';
                      }
                      return null;
                    },
                    onChanged: (_) => setState(() {}),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _talukaController,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(labelText: 'Taluka'),
                    validator: (value) {
                      if (value == null || value.trim().isEmpty) {
                        return 'Taluka is required.';
                      }
                      return null;
                    },
                    onChanged: (_) => setState(() {}),
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
                    'Location',
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
                        Text('Capturing GPS location...'),
                      ],
                    )
                  else if (_latitude != null && _longitude != null) ...[
                    Row(
                      children: [
                        Icon(Icons.location_on_outlined, color: AppColors.primary),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'Latitude: ${_latitude!.toStringAsFixed(7)}\n'
                            'Longitude: ${_longitude!.toStringAsFixed(7)}',
                          ),
                        ),
                      ],
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
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Photo Attachment',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  if (_photoPath == null)
                    OutlinedButton.icon(
                      onPressed: _capturePhoto,
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
                          onPressed: _capturePhoto,
                          child: const Text('Retake'),
                        ),
                        TextButton(
                          onPressed: () => setState(() => _photoPath = null),
                          child: const Text('Remove'),
                        ),
                      ],
                    ),
                  ],
                ],
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
                    : const Text('Submit Field Activity'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
