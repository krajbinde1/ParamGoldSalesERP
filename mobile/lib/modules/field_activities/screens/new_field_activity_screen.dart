import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import '../../../core/navigation/navigation_guard.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../../orders/api/product_api.dart';
import '../../orders/models/product.dart';
import '../api/field_activity_api.dart';
import '../services/field_activity_location_service.dart';
import '../widgets/searchable_picker.dart';

class _ProductRecommendation {
  int? productId;
  String productLabel = '';
  final dosage = TextEditingController();
  final remark = TextEditingController();

  void dispose() {
    dosage.dispose();
    remark.dispose();
  }
}

class NewFieldActivityScreen extends StatefulWidget {
  const NewFieldActivityScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<NewFieldActivityScreen> createState() => _NewFieldActivityScreenState();
}

class _NewFieldActivityScreenState extends State<NewFieldActivityScreen> {
  final _formKey = GlobalKey<FormState>();
  final _farmerNameController = TextEditingController();
  final _farmerMobileController = TextEditingController();
  final _villageController = TextEditingController();
  final _remarkController = TextEditingController();
  final _locationService = FieldActivityLocationService();

  late final FieldActivityApi _api;
  late final ProductApi _productApi;

  List<SearchablePickerOption> _districts = [];
  List<SearchablePickerOption> _talukas = [];
  List<SearchablePickerOption> _crops = [];
  List<Product> _products = [];

  int? _districtId;
  String _districtLabel = '';
  int? _talukaId;
  String _talukaLabel = '';
  int? _cropId;
  String _cropLabel = '';

  final List<_ProductRecommendation> _recommendations = [_ProductRecommendation()];

  String? _photoPath;
  double? _latitude;
  double? _longitude;
  bool _submitting = false;
  bool _capturingLocation = false;
  bool _lookingUp = false;
  String? _locationError;
  String? _farmerHint;
  Timer? _lookupTimer;

  @override
  void initState() {
    super.initState();
    final dio = ApiClient(
      SessionStore(),
      onUnauthorized: widget.auth.sessionExpired,
    ).dio;
    _api = FieldActivityApi(dio);
    _productApi = ProductApi(dio);
    _captureLocation();
    _loadMasters();
  }

  @override
  void dispose() {
    _lookupTimer?.cancel();
    _farmerNameController.dispose();
    _farmerMobileController.dispose();
    _villageController.dispose();
    _remarkController.dispose();
    for (final row in _recommendations) {
      row.dispose();
    }
    super.dispose();
  }

  Future<void> _loadMasters() async {
    try {
      final districts = await _api.districts();
      final crops = await _api.crops();
      final products = await _productApi.list();
      if (!mounted) return;
      setState(() {
        _districts = districts
            .map(
              (row) => SearchablePickerOption(
                id: int.tryParse('${row['id']}') ?? 0,
                label: row['label']?.toString() ?? row['name']?.toString() ?? '',
              ),
            )
            .where((row) => row.id > 0)
            .toList();
        _crops = crops
            .map(
              (row) => SearchablePickerOption(
                id: int.tryParse('${row['id']}') ?? 0,
                label: row['name']?.toString() ?? '',
              ),
            )
            .where((row) => row.id > 0)
            .toList();
        _products = products;
      });
    } catch (_) {}
  }

  Future<void> _loadTalukas(int districtId) async {
    final talukas = await _api.talukas(districtId);
    if (!mounted) return;
    setState(() {
      _talukas = talukas
          .map(
            (row) => SearchablePickerOption(
              id: int.tryParse('${row['id']}') ?? 0,
              label: row['name']?.toString() ?? '',
            ),
          )
          .where((row) => row.id > 0)
          .toList();
      if (_talukaId != null &&
          !_talukas.any((option) => option.id == _talukaId)) {
        _talukaId = null;
        _talukaLabel = '';
      }
    });
  }

  void _onMobileChanged(String value) {
    setState(() {});
    _lookupTimer?.cancel();
    final mobile = value.trim();
    if (!RegExp(r'^[6-9][0-9]{9}$').hasMatch(mobile)) {
      setState(() => _farmerHint = null);
      return;
    }
    _lookupTimer = Timer(const Duration(milliseconds: 450), () {
      _lookupFarmer(mobile);
    });
  }

  Future<void> _lookupFarmer(String mobile) async {
    setState(() => _lookingUp = true);
    try {
      final result = await _api.lookupFarmer(mobile);
      if (!mounted) return;
      if (result == null) {
        setState(() => _farmerHint = 'New farmer. Enter remaining details.');
        return;
      }
      final farmer = Map<String, dynamic>.from(result['data'] as Map? ?? {});
      final last = result['last_activity'] is Map
          ? Map<String, dynamic>.from(result['last_activity'] as Map)
          : null;
      _farmerNameController.text = farmer['name']?.toString() ?? _farmerNameController.text;
      _villageController.text = farmer['village']?.toString() ?? _villageController.text;
      final districtId = int.tryParse('${farmer['district_id'] ?? ''}');
      final talukaId = int.tryParse('${farmer['taluka_id'] ?? ''}');
      if (districtId != null) {
        _districtId = districtId;
        _districtLabel = farmer['district_name']?.toString() ?? '';
        await _loadTalukas(districtId);
      }
      if (talukaId != null) {
        _talukaId = talukaId;
        _talukaLabel = farmer['taluka_name']?.toString() ?? '';
      }
      final lastCrop = last?['crop_name']?.toString();
      final products = (last?['products'] as List?)?.join(', ');
      setState(() {
        _farmerHint = [
          'Existing farmer found. Details filled — confirm before submit.',
          if (lastCrop != null && lastCrop.isNotEmpty) 'Last crop: $lastCrop',
          if (products != null && products.isNotEmpty) 'Last products: $products',
        ].join('\n');
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _farmerHint = null);
    } finally {
      if (mounted) setState(() => _lookingUp = false);
    }
  }

  bool get _canSubmit =>
      !_submitting &&
      !_capturingLocation &&
      _photoPath != null &&
      _latitude != null &&
      _longitude != null &&
      _farmerNameController.text.trim().isNotEmpty &&
      RegExp(r'^[6-9][0-9]{9}$').hasMatch(_farmerMobileController.text.trim()) &&
      _villageController.text.trim().isNotEmpty &&
      _districtId != null &&
      _talukaId != null &&
      _cropId != null &&
      _recommendations.any((row) => row.productId != null);

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

  Future<void> _pickDistrict() async {
    final selected = await showSearchablePicker(
      context: context,
      title: 'Select District',
      options: _districts,
      selectedId: _districtId,
    );
    if (selected == null || !mounted) return;
    setState(() {
      _districtId = selected.id;
      _districtLabel = selected.label;
      _talukaId = null;
      _talukaLabel = '';
    });
    await _loadTalukas(selected.id);
  }

  Future<void> _pickTaluka() async {
    if (_districtId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Select a district first.')),
      );
      return;
    }
    final selected = await showSearchablePicker(
      context: context,
      title: 'Select Taluka',
      options: _talukas,
      selectedId: _talukaId,
    );
    if (selected == null || !mounted) return;
    setState(() {
      _talukaId = selected.id;
      _talukaLabel = selected.label;
    });
  }

  Future<void> _pickCrop() async {
    final selected = await showSearchablePicker(
      context: context,
      title: 'Select Crop',
      options: _crops,
      selectedId: _cropId,
    );
    if (selected == null || !mounted) return;
    setState(() {
      _cropId = selected.id;
      _cropLabel = selected.label;
    });
  }

  Future<void> _pickProduct(_ProductRecommendation row) async {
    final options = _products
        .map(
          (product) => SearchablePickerOption(
            id: product.id,
            label: '${product.productName} (${product.productCode})',
          ),
        )
        .toList();
    final selected = await showSearchablePicker(
      context: context,
      title: 'Select Product',
      options: options,
      selectedId: row.productId,
    );
    if (selected == null || !mounted) return;
    setState(() {
      row.productId = selected.id;
      row.productLabel = selected.label;
    });
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
      final recs = _recommendations
          .where((row) => row.productId != null)
          .map(
            (row) => {
              'product_id': row.productId,
              'dosage': row.dosage.text,
              'remark': row.remark.text,
            },
          )
          .toList();
      final message = await _api.submit(
        farmerName: _farmerNameController.text,
        farmerMobile: _farmerMobileController.text,
        districtId: _districtId!,
        talukaId: _talukaId!,
        village: _villageController.text,
        cropId: _cropId!,
        latitude: _latitude!,
        longitude: _longitude!,
        photoPath: _photoPath!,
        recommendations: recs,
        remark: _remarkController.text,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
      safePop(context, true);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$error')));
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
                  Text('Farmer Details', style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _farmerMobileController,
                    keyboardType: TextInputType.phone,
                    maxLength: 10,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: InputDecoration(
                      labelText: 'Farmer Mobile Number *',
                      counterText: '',
                      suffixIcon: _lookingUp
                          ? const Padding(
                              padding: EdgeInsets.all(12),
                              child: SizedBox(
                                width: 16,
                                height: 16,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              ),
                            )
                          : null,
                    ),
                    validator: (value) {
                      if (!RegExp(r'^[6-9][0-9]{9}$').hasMatch(value?.trim() ?? '')) {
                        return 'Enter a valid 10-digit Indian mobile number.';
                      }
                      return null;
                    },
                    onChanged: _onMobileChanged,
                  ),
                  if (_farmerHint != null) ...[
                    const SizedBox(height: 6),
                    Text(_farmerHint!, style: Theme.of(context).textTheme.bodySmall),
                  ],
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _farmerNameController,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(labelText: 'Farmer Name *'),
                    validator: (value) =>
                        (value == null || value.trim().isEmpty) ? 'Farmer name is required.' : null,
                    onChanged: (_) => setState(() {}),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  _pickerField('District *', _districtLabel, _pickDistrict),
                  const SizedBox(height: AppSpacing.sm),
                  _pickerField('Taluka *', _talukaLabel, _pickTaluka),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _villageController,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(labelText: 'Village Name *'),
                    validator: (value) =>
                        (value == null || value.trim().isEmpty) ? 'Village is required.' : null,
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
                  Text('Crop & Recommendations', style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: AppSpacing.sm),
                  _pickerField('Crop *', _cropLabel, _pickCrop),
                  const SizedBox(height: AppSpacing.md),
                  for (var i = 0; i < _recommendations.length; i++) ...[
                    _recommendationCard(i),
                    const SizedBox(height: AppSpacing.sm),
                  ],
                  OutlinedButton.icon(
                    onPressed: () => setState(() {
                      _recommendations.add(_ProductRecommendation());
                    }),
                    icon: const Icon(Icons.add),
                    label: const Text('Add Product Recommendation'),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _remarkController,
                    maxLines: 2,
                    decoration: const InputDecoration(
                      labelText: 'Activity Remark (optional)',
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
                  Text('Location', style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: AppSpacing.sm),
                  if (_capturingLocation)
                    const Text('Capturing GPS location...')
                  else if (_latitude != null && _longitude != null)
                    Text(
                      'Latitude: ${_latitude!.toStringAsFixed(7)}\n'
                      'Longitude: ${_longitude!.toStringAsFixed(7)}',
                    )
                  else
                    Text(
                      _locationError ?? 'Location is required before submission.',
                      style: TextStyle(color: Theme.of(context).colorScheme.error),
                    ),
                  TextButton(
                    onPressed: _capturingLocation || _submitting ? null : _captureLocation,
                    child: const Text('Refresh Location'),
                  ),
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            PgCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Photo Attachment', style: Theme.of(context).textTheme.titleMedium),
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
                    TextButton(
                      onPressed: _capturePhoto,
                      child: const Text('Retake'),
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

  Widget _pickerField(String label, String value, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      child: InputDecorator(
        decoration: InputDecoration(labelText: label),
        child: Text(value.isEmpty ? 'Select' : value),
      ),
    );
  }

  Widget _recommendationCard(int index) {
    final row = _recommendations[index];
    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        border: Border.all(color: AppColors.border),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        children: [
          Row(
            children: [
              Expanded(child: Text('Product ${index + 1}')),
              if (_recommendations.length > 1)
                IconButton(
                  onPressed: () => setState(() {
                    row.dispose();
                    _recommendations.removeAt(index);
                  }),
                  icon: const Icon(Icons.close),
                ),
            ],
          ),
          _pickerField(
            'Recommended Product *',
            row.productLabel,
            () => _pickProduct(row),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextFormField(
            controller: row.dosage,
            decoration: const InputDecoration(
              labelText: 'Dosage / Quantity (optional)',
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextFormField(
            controller: row.remark,
            decoration: const InputDecoration(
              labelText: 'Recommendation Remark (optional)',
            ),
          ),
        ],
      ),
    );
  }
}
