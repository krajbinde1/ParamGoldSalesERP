import 'dart:io';

import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:permission_handler/permission_handler.dart' as ph;
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/utils/secure_document.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../../field_activities/api/field_activity_api.dart';
import '../../field_activities/widgets/searchable_picker.dart';
import '../../manager/widgets/view_captured_location_button.dart';
import '../api/dealer_application_api.dart';

const _maharashtraState = 'Maharashtra';

const _documentTypes = <(String type, String label)>[
  ('fertilizer_license', 'Fertilizer License'),
  ('seed_license', 'Seed License'),
  ('insecticide_license', 'Insecticide License'),
  ('gst_certificate', 'GST Certificate'),
  ('shop_udyam_certificate', 'Shop / Udyam Certificate'),
  ('owner_aadhaar', 'Owner Aadhaar Card'),
  ('owner_pan', 'Owner PAN Card'),
  ('security_deposit', 'Security Deposit Document'),
];

class DealerApplicationFormScreen extends StatefulWidget {
  const DealerApplicationFormScreen({
    super.key,
    required this.auth,
    this.applicationId,
  });

  final AuthController auth;
  final int? applicationId;

  @override
  State<DealerApplicationFormScreen> createState() =>
      _DealerApplicationFormScreenState();
}

class _DealerApplicationFormScreenState
    extends State<DealerApplicationFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _firm = TextEditingController();
  final _owner = TextEditingController();
  final _mobile = TextEditingController();
  final _gst = TextEditingController();
  final _village = TextEditingController();
  final _address = TextEditingController();
  final _imagePicker = ImagePicker();
  final Map<String, String> _localPreviews = {};

  late final DealerApplicationApi _api;
  late final FieldActivityApi _masters;
  late final Dio _dio;

  int? _id;
  Map<String, dynamic>? _detail;
  double? _latitude;
  double? _longitude;
  bool _loading = false;
  bool _saving = false;
  bool _capturingLocation = false;
  String? _busyDocumentType;
  String? _locationError;
  String? _duplicateWarning;

  List<SearchablePickerOption> _districts = [];
  List<SearchablePickerOption> _talukas = [];
  final Map<int, String> _districtNameById = {};
  int? _districtId;
  String _districtLabel = '';
  int? _talukaId;
  String _talukaLabel = '';
  bool _loadingDistricts = false;
  bool _districtsError = false;
  bool _loadingTalukas = false;
  bool _talukasError = false;
  int _talukaLoadToken = 0;

  @override
  void initState() {
    super.initState();
    final client = ApiClient(
      SessionStore(),
      onUnauthorized: widget.auth.sessionExpired,
    );
    _dio = client.dio;
    _api = DealerApplicationApi(client.dio);
    _masters = FieldActivityApi(client.dio);
    _id = widget.applicationId;
    _loadDistricts();
    if (_id != null) {
      _load();
    }
  }

  @override
  void dispose() {
    _firm.dispose();
    _owner.dispose();
    _mobile.dispose();
    _gst.dispose();
    _village.dispose();
    _address.dispose();
    super.dispose();
  }

  bool get _hasShopLocation {
    final lat = _latitude;
    final lng = _longitude;
    if (lat == null || lng == null) return false;
    return lat.abs() >= 0.000001 || lng.abs() >= 0.000001;
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final detail = await _api.getById(_id!);
      if (!mounted) return;
      _applyDetail(detail);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(errorMessage(error))),
      );
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _applyDetail(Map<String, dynamic> detail) {
    _detail = detail;
    _id = int.tryParse('${detail['id']}');
    _firm.text = detail['firm_name']?.toString() ?? '';
    _owner.text = detail['owner_name']?.toString() ?? '';
    _mobile.text = detail['mobile']?.toString() ?? '';
    _gst.text = detail['gst_no']?.toString() ?? '';
    _districtLabel = detail['district']?.toString() ?? '';
    _talukaLabel = detail['taluka']?.toString() ?? '';
    _village.text = detail['village']?.toString() ?? '';
    _address.text = detail['address']?.toString() ?? '';
    _latitude = double.tryParse('${detail['latitude'] ?? ''}');
    _longitude = double.tryParse('${detail['longitude'] ?? ''}');
    setState(() {});
    _syncLocationFromSavedNames();
  }

  Map<String, dynamic> _payload() => {
        'firm_name': _firm.text.trim(),
        'owner_name': _owner.text.trim(),
        'mobile': _mobile.text.trim(),
        'gst_no': _gst.text.trim().isEmpty ? null : _gst.text.trim().toUpperCase(),
        'state': _maharashtraState,
        'district': _savedDistrictName(),
        'taluka': _talukaLabel.trim(),
        'village': _village.text.trim(),
        'address': _address.text.trim().isEmpty ? null : _address.text.trim(),
        'latitude': _latitude,
        'longitude': _longitude,
      };

  String _savedDistrictName() {
    final id = _districtId;
    if (id != null) {
      final name = _districtNameById[id]?.trim() ?? '';
      if (name.isNotEmpty) return name;
    }
    return _districtLabel.trim();
  }

  SearchablePickerOption? _matchOption(
    List<SearchablePickerOption> options,
    String name,
  ) {
    final needle = name.trim().toLowerCase();
    if (needle.isEmpty) return null;
    for (final option in options) {
      if (option.label.toLowerCase() == needle) return option;
    }
    for (final option in options) {
      final label = option.label.toLowerCase();
      if (label.startsWith('$needle (') || label.contains('($needle)')) {
        return option;
      }
    }
    return null;
  }

  Future<void> _loadDistricts() async {
    setState(() {
      _loadingDistricts = true;
      _districtsError = false;
    });
    try {
      final rows = await _masters.districts();
      if (!mounted) return;
      setState(() {
        _districtNameById
          ..clear()
          ..addEntries(
            rows
                .map((row) {
                  final id = int.tryParse('${row['id']}') ?? 0;
                  final name = row['name']?.toString().trim() ?? '';
                  return MapEntry(id, name);
                })
                .where((entry) => entry.key > 0 && entry.value.isNotEmpty),
          );
        _districts = rows
            .map(
              (row) => SearchablePickerOption(
                id: int.tryParse('${row['id']}') ?? 0,
                label: row['label']?.toString() ?? row['name']?.toString() ?? '',
              ),
            )
            .where((option) => option.id > 0 && option.label.isNotEmpty)
            .toList();
        _loadingDistricts = false;
      });
      await _syncLocationFromSavedNames();
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loadingDistricts = false;
        _districtsError = true;
      });
    }
  }

  Future<void> _loadTalukas(
    int districtId, {
    bool preserveTaluka = false,
  }) async {
    final token = ++_talukaLoadToken;
    setState(() {
      _loadingTalukas = true;
      _talukasError = false;
      _talukas = [];
      if (!preserveTaluka) {
        _talukaId = null;
        _talukaLabel = '';
      }
    });
    try {
      final rows = await _masters.talukas(districtId);
      if (!mounted || token != _talukaLoadToken) return;
      final options = rows
          .map(
            (row) => SearchablePickerOption(
              id: int.tryParse('${row['id']}') ?? 0,
              label: row['name']?.toString() ?? '',
            ),
          )
          .where((option) => option.id > 0 && option.label.isNotEmpty)
          .toList();
      SearchablePickerOption? saved;
      if (preserveTaluka) {
        saved = _matchOption(options, _talukaLabel);
      }
      setState(() {
        _talukas = options;
        _loadingTalukas = false;
        if (preserveTaluka) {
          _talukaId = saved?.id;
          if (saved != null) _talukaLabel = saved.label;
        }
      });
    } catch (_) {
      if (!mounted || token != _talukaLoadToken) return;
      setState(() {
        _loadingTalukas = false;
        _talukasError = true;
        if (!preserveTaluka) {
          _talukaId = null;
          _talukaLabel = '';
        }
      });
    }
  }

  Future<void> _syncLocationFromSavedNames() async {
    if (_districts.isEmpty || _districtLabel.trim().isEmpty) return;
    final district = _matchDistrict(_districtLabel);
    if (district == null) return;
    setState(() {
      _districtId = district.id;
      _districtLabel = district.label;
    });
    await _loadTalukas(district.id, preserveTaluka: true);
  }

  SearchablePickerOption? _matchDistrict(String name) {
    final byLabel = _matchOption(_districts, name);
    if (byLabel != null) return byLabel;
    final needle = name.trim().toLowerCase();
    if (needle.isEmpty) return null;
    for (final option in _districts) {
      if (_districtNameById[option.id]?.toLowerCase() == needle) {
        return option;
      }
    }
    return null;
  }

  Future<void> _pickDistrict() async {
    if (_districtsError) {
      await _loadDistricts();
      return;
    }
    if (_loadingDistricts) return;
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
      _talukas = [];
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
    if (_talukasError) {
      await _loadTalukas(_districtId!, preserveTaluka: true);
      return;
    }
    if (_loadingTalukas) return;
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

  Future<void> _captureShopLocation() async {
    setState(() {
      _capturingLocation = true;
      _locationError = null;
    });
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        throw const _ShopLocationException(
          'Please enable GPS to capture shop location.',
        );
      }

      final status = await ph.Permission.locationWhenInUse.request();
      if (!status.isGranted) {
        throw _ShopLocationException(
          status.isPermanentlyDenied
              ? 'Location permission is required. Enable it in app settings.'
              : 'Location permission is required to capture shop location.',
        );
      }

      var geoPermission = await Geolocator.checkPermission();
      if (geoPermission == LocationPermission.denied) {
        geoPermission = await Geolocator.requestPermission();
      }
      if (geoPermission == LocationPermission.denied ||
          geoPermission == LocationPermission.deniedForever) {
        throw const _ShopLocationException(
          'Location permission is required. Enable it in app settings.',
        );
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 25),
        ),
      );

      if (position.latitude.abs() < 0.000001 &&
          position.longitude.abs() < 0.000001) {
        throw const _ShopLocationException(
          'A valid shop GPS location could not be captured. Please try again outdoors.',
        );
      }

      if (!mounted) return;
      setState(() {
        _latitude = position.latitude;
        _longitude = position.longitude;
        _locationError = null;
      });

      if (_id != null && (_formKey.currentState?.validate() ?? false)) {
        await _saveDraft(showMessage: false);
      }

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Shop location captured.')),
      );
    } on _ShopLocationException catch (error) {
      if (!mounted) return;
      setState(() => _locationError = error.message);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(error.message)),
      );
    } catch (error) {
      if (!mounted) return;
      final message = error is LocationServiceDisabledException
          ? 'Please enable GPS to capture shop location.'
          : error is PermissionDeniedException
              ? 'Location permission is required. Enable it in app settings.'
              : 'Unable to capture shop location. Please try again outdoors.';
      setState(() => _locationError = message);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(message)),
      );
    } finally {
      if (mounted) setState(() => _capturingLocation = false);
    }
  }

  Future<bool> _saveDraft({bool showMessage = true}) async {
    if (!(_formKey.currentState?.validate() ?? false)) return false;
    setState(() => _saving = true);
    try {
      final result = await _api.save(id: _id, payload: _payload());
      final data = Map<String, dynamic>.from(result['data'] as Map);
      _applyDetail(data);
      final warning = result['duplicate_warning'] == true;
      _duplicateWarning = warning
          ? 'A similar dealer already exists. You can still submit; Admin will review.'
          : null;
      if (showMessage && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              result['message']?.toString() ?? 'Draft saved.',
            ),
          ),
        );
      }
      return true;
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(errorMessage(error))),
        );
      }
      return false;
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<bool> _ensureDraftForDocuments() async {
    if (_id != null) return true;
    if (!(_formKey.currentState?.validate() ?? false)) {
      if (!mounted) return false;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Please fill dealer details first. The draft will be saved automatically when you upload a document.',
          ),
        ),
      );
      return false;
    }
    return _saveDraft(showMessage: false);
  }

  Future<void> _requestCameraPermission() async {
    final camera = await ph.Permission.camera.request();
    if (camera.isGranted) return;
    throw _ShopLocationException(
      camera.isPermanentlyDenied
          ? 'Camera permission is required. Enable it in app settings.'
          : 'Camera permission is required to take a document photo.',
    );
  }

  Future<String?> _takeDocumentPhoto() async {
    await _requestCameraPermission();
    while (mounted) {
      final image = await _imagePicker.pickImage(
        source: ImageSource.camera,
        preferredCameraDevice: CameraDevice.rear,
        imageQuality: 85,
      );
      if (image == null || !mounted) return null;
      final action = await showDialog<String>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Preview'),
          content: ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: Image.file(
              File(image.path),
              height: 240,
              width: double.infinity,
              fit: BoxFit.cover,
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, 'retake'),
              child: const Text('Retake'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, 'use'),
              child: const Text('Use Photo'),
            ),
          ],
        ),
      );
      if (action == 'use') return image.path;
      if (action != 'retake') return null;
    }
    return null;
  }

  Future<String?> _pickDocumentFile() async {
    final picked = await FilePicker.pickFile(
      type: FileType.custom,
      allowedExtensions: const ['pdf', 'jpg', 'jpeg', 'png'],
    );
    return picked?.path;
  }

  Future<void> _uploadPath(Map<String, dynamic> slot, String path) async {
    final file = File(path);
    if (!await file.exists()) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Selected file is not available.')),
      );
      return;
    }
    final bytes = await file.length();
    if (bytes > 5 * 1024 * 1024) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('File must be 5 MB or smaller.')),
      );
      return;
    }

    final type = slot['document_type'].toString();
    final saved = await _ensureDraftForDocuments();
    if (!saved || _id == null || !mounted) return;

    setState(() => _busyDocumentType = type);
    try {
      final result = await _api.uploadDocument(
        applicationId: _id!,
        documentType: type,
        filePath: path,
      );
      if (!mounted) return;
      _localPreviews[type] = path;
      _applyDetail(Map<String, dynamic>.from(result['application'] as Map));
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(result['message']?.toString() ?? 'Uploaded.')),
      );
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(errorMessage(error))),
        );
      }
    } finally {
      if (mounted) setState(() => _busyDocumentType = null);
    }
  }

  Future<void> _takePhotoAndUpload(Map<String, dynamic> slot) async {
    try {
      final path = await _takeDocumentPhoto();
      if (path == null || !mounted) return;
      await _uploadPath(slot, path);
    } on _ShopLocationException catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(error.message)),
      );
    }
  }

  Future<void> _pickFileAndUpload(Map<String, dynamic> slot) async {
    try {
      final path = await _pickDocumentFile();
      if (path == null || !mounted) return;
      await _uploadPath(slot, path);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Unable to open files. ${errorMessage(error)}',
          ),
        ),
      );
    }
  }

  Future<void> _replaceDocument(Map<String, dynamic> slot) async {
    final source = await showModalBottomSheet<String>(
      context: context,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Take Photo'),
              onTap: () => Navigator.pop(context, 'camera'),
            ),
            ListTile(
              leading: const Icon(Icons.upload_file_outlined),
              title: const Text('Upload File'),
              onTap: () => Navigator.pop(context, 'file'),
            ),
          ],
        ),
      ),
    );
    if (source == 'camera') {
      await _takePhotoAndUpload(slot);
    } else if (source == 'file') {
      await _pickFileAndUpload(slot);
    }
  }

  Future<void> _removeDocument(Map<String, dynamic> slot) async {
    final documentId = int.tryParse('${slot['id'] ?? ''}');
    if (_id == null || documentId == null) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Remove document'),
        content: Text(
          'Remove ${slot['document_name'] ?? 'this document'}?',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Remove'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;

    final type = slot['document_type']?.toString();
    setState(() => _busyDocumentType = type);
    try {
      final result = await _api.deleteDocument(
        applicationId: _id!,
        documentId: documentId,
      );
      if (!mounted) return;
      if (type != null) _localPreviews.remove(type);
      _applyDetail(Map<String, dynamic>.from(result['data'] as Map));
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(errorMessage(error))),
        );
      }
    } finally {
      if (mounted) setState(() => _busyDocumentType = null);
    }
  }

  Future<void> _submit() async {
    if (!_hasShopLocation) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Please capture dealer shop location before submitting.',
          ),
        ),
      );
      return;
    }
    final saved = await _saveDraft(showMessage: false);
    if (!saved || _id == null) return;
    setState(() => _saving = true);
    try {
      final result = await _api.submit(_id!);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            result['message']?.toString() ?? 'Submitted for approval.',
          ),
        ),
      );
      context.pop();
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(errorMessage(error))),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  List<Map<String, dynamic>> get _documentSlots {
    final uploaded = <String, Map<String, dynamic>>{};
    final raw = _detail?['documents'];
    if (raw is List) {
      for (final item in raw) {
        if (item is! Map) continue;
        final row = Map<String, dynamic>.from(item);
        final type = row['document_type']?.toString();
        if (type != null && type.isNotEmpty) {
          uploaded[type] = row;
        }
      }
    }
    return [
      for (final entry in _documentTypes)
        uploaded[entry.$1] ??
            {
              'id': null,
              'document_type': entry.$1,
              'document_name': entry.$2,
              'original_filename': null,
              'uploaded': false,
              'mime_type': null,
              'is_pdf': false,
              'is_image': false,
              'view_path': null,
            },
    ];
  }

  @override
  Widget build(BuildContext context) {
    final busy = _saving || _capturingLocation || _busyDocumentType != null;
    return PgPageScaffold(
      auth: widget.auth,
      title: widget.applicationId == null ? 'Create Dealer' : 'Edit Dealer',
      showBack: true,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgCard(
                    child: Column(
                      children: [
                        _field(_firm, 'Dealer / Firm Name'),
                        _field(_owner, 'Owner Name'),
                        _field(
                          _mobile,
                          'Mobile Number',
                          keyboard: TextInputType.phone,
                          validator: (value) {
                            final text = value?.trim() ?? '';
                            if (!RegExp(r'^[6-9][0-9]{9}$').hasMatch(text)) {
                              return 'Enter a valid 10-digit mobile number.';
                            }
                            return null;
                          },
                        ),
                        _field(
                          _gst,
                          'GST Number',
                          requiredField: false,
                          textCapitalization: TextCapitalization.characters,
                        ),
                        _readOnlyField('State *', _maharashtraState),
                        _locationPickerField(
                          label: 'District',
                          value: _districtLabel,
                          placeholder: _loadingDistricts
                              ? 'Loading districts...'
                              : 'Select District',
                          loading: _loadingDistricts,
                          errorText: _districtsError
                              ? 'Unable to load districts. Tap to retry.'
                              : null,
                          onTap: _pickDistrict,
                          validator: () => _districtLabel.trim().isEmpty
                              ? 'District is required.'
                              : null,
                        ),
                        _locationPickerField(
                          label: 'Taluka',
                          value: _talukaLabel,
                          placeholder: _loadingTalukas
                              ? 'Loading talukas...'
                              : _districtId == null
                                  ? 'Select a district first'
                                  : 'Select Taluka',
                          loading: _loadingTalukas,
                          errorText: _talukasError
                              ? 'Unable to load talukas. Tap to retry.'
                              : null,
                          onTap: _pickTaluka,
                          validator: () => _talukaLabel.trim().isEmpty
                              ? 'Taluka is required.'
                              : null,
                        ),
                        _field(_village, 'Village / Location'),
                        _field(
                          _address,
                          'Full Address',
                          requiredField: false,
                          maxLines: 3,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.md),
                  PgCard(child: _shopLocationSection()),
                  if (_duplicateWarning != null) ...[
                    const SizedBox(height: AppSpacing.md),
                    PgCard(
                      child: Text(
                        _duplicateWarning!,
                        style: const TextStyle(color: AppColors.warning),
                      ),
                    ),
                  ],
                  const SizedBox(height: AppSpacing.md),
                  Text(
                    'Supporting Documents',
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Take a photo or upload a PDF / image for each document. The draft is saved automatically on the first upload.',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AppColors.textSecondary,
                        ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  for (final slot in _documentSlots)
                    _documentCard(slot, busy: busy),
                  const SizedBox(height: AppSpacing.lg),
                  OutlinedButton(
                    onPressed: busy ? null : () => _saveDraft(),
                    child: Text(
                      _saving ? 'Saving...' : 'Save Draft',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  FilledButton(
                    onPressed: busy ? null : _submit,
                    child: const Text(
                      'Submit for Approval',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.xxl),
                ],
              ),
            ),
    );
  }

  Widget _shopLocationSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Shop Location',
          style: Theme.of(context).textTheme.titleSmall,
        ),
        const SizedBox(height: 8),
        if (_capturingLocation) const LinearProgressIndicator(),
        if (_hasShopLocation) ...[
          Row(
            children: [
              const Icon(Icons.check_circle, color: AppColors.success, size: 20),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Shop Location Captured',
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        color: AppColors.success,
                        fontWeight: FontWeight.w700,
                      ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text('Latitude: ${_latitude!.toStringAsFixed(6)}'),
          Text('Longitude: ${_longitude!.toStringAsFixed(6)}'),
          const SizedBox(height: 8),
        ],
        ViewCapturedLocationButton(
          latitude: _latitude,
          longitude: _longitude,
        ),
        if (_locationError != null) ...[
          const SizedBox(height: 8),
          Text(
            _locationError!,
            style: const TextStyle(color: AppColors.error),
          ),
        ],
        SizedBox(
          width: double.infinity,
          child: FilledButton.tonal(
            onPressed: _capturingLocation ? null : _captureShopLocation,
            child: const Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.my_location),
                SizedBox(width: 8),
                Flexible(
                  child: Text(
                    'Capture Shop Location',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.center,
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _documentCard(Map<String, dynamic> slot, {required bool busy}) {
    final type = slot['document_type']?.toString() ?? '';
    final uploaded = slot['uploaded'] == true;
    final isBusy = _busyDocumentType == type;
    final filename = slot['original_filename']?.toString() ?? '';
    final mime = slot['mime_type']?.toString() ?? '';
    final localPath = _localPreviews[type];
    final isPdf = slot['is_pdf'] == true ||
        mime.toLowerCase().contains('pdf') ||
        filename.toLowerCase().endsWith('.pdf');
    final isImage = slot['is_image'] == true ||
        (!isPdf &&
            (mime.toLowerCase().startsWith('image/') ||
                filename.toLowerCase().endsWith('.jpg') ||
                filename.toLowerCase().endsWith('.jpeg') ||
                filename.toLowerCase().endsWith('.png') ||
                (localPath != null && !localPath.toLowerCase().endsWith('.pdf'))));

    return PgCard(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            slot['document_name']?.toString() ?? '-',
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.titleSmall,
          ),
          const SizedBox(height: 8),
          if (isBusy)
            const LinearProgressIndicator()
          else if (!uploaded) ...[
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: OutlinedButton.icon(
                    onPressed: busy ? null : () => _takePhotoAndUpload(slot),
                    icon: const Icon(Icons.photo_camera_outlined, size: 18),
                    label: const Text('Take Photo'),
                  ),
                ),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: OutlinedButton.icon(
                    onPressed: busy ? null : () => _pickFileAndUpload(slot),
                    icon: const Icon(Icons.upload_file_outlined, size: 18),
                    label: const Text('Upload File'),
                  ),
                ),
              ],
            ),
          ] else ...[
            Row(
              children: [
                _documentThumb(
                  isPdf: isPdf,
                  isImage: isImage,
                  localPath: localPath,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        '✓ Uploaded',
                        style: TextStyle(
                          color: AppColors.success,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      if (filename.isNotEmpty)
                        Text(
                          filename,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 4,
              children: [
                TextButton(
                  onPressed: busy
                      ? null
                      : () => openSecureDocument(
                            context,
                            dio: _dio,
                            title: slot['document_name']?.toString() ??
                                filename,
                            mimeType: mime,
                            viewPath: slot['view_path']?.toString(),
                            documentId: int.tryParse('${slot['id'] ?? ''}'),
                          ),
                  child: const Text('View'),
                ),
                TextButton(
                  onPressed: busy ? null : () => _replaceDocument(slot),
                  child: const Text('Replace'),
                ),
                TextButton(
                  onPressed: busy ? null : () => _removeDocument(slot),
                  child: const Text('Remove'),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _documentThumb({
    required bool isPdf,
    required bool isImage,
    String? localPath,
  }) {
    if (isImage && localPath != null && File(localPath).existsSync()) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(8),
        child: Image.file(
          File(localPath),
          width: 48,
          height: 48,
          fit: BoxFit.cover,
        ),
      );
    }
    return Container(
      width: 48,
      height: 48,
      decoration: BoxDecoration(
        color: AppColors.background,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: AppColors.border),
      ),
      child: Icon(
        isPdf ? Icons.picture_as_pdf_outlined : Icons.image_outlined,
        color: isPdf ? AppColors.error : AppColors.primary,
      ),
    );
  }

  Widget _readOnlyField(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: InputDecorator(
        decoration: InputDecoration(labelText: label),
        child: Text(value),
      ),
    );
  }

  Widget _locationPickerField({
    required String label,
    required String value,
    required String placeholder,
    required VoidCallback onTap,
    required String? Function() validator,
    bool loading = false,
    String? errorText,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: FormField<String>(
        key: ValueKey('$label-$value-${errorText ?? ''}-$loading'),
        validator: (_) => errorText ?? validator(),
        builder: (state) {
          final display = errorText ??
              (value.trim().isEmpty ? placeholder : value);
          return InkWell(
            onTap: onTap,
            child: InputDecorator(
              decoration: InputDecoration(
                labelText: '$label *',
                errorText: state.errorText,
                suffixIcon: loading
                    ? const Padding(
                        padding: EdgeInsets.all(12),
                        child: SizedBox.square(
                          dimension: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      )
                    : const Icon(Icons.expand_more),
              ),
              child: Text(
                display,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: value.trim().isEmpty && errorText == null
                      ? AppColors.textMuted
                      : null,
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _field(
    TextEditingController controller,
    String label, {
    bool requiredField = true,
    int maxLines = 1,
    TextInputType? keyboard,
    TextCapitalization textCapitalization = TextCapitalization.words,
    String? Function(String?)? validator,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: TextFormField(
        controller: controller,
        maxLines: maxLines,
        keyboardType: keyboard,
        textCapitalization: textCapitalization,
        decoration: InputDecoration(
          labelText: requiredField ? '$label *' : label,
        ),
        validator: validator ??
            (value) {
              if (!requiredField) return null;
              if ((value ?? '').trim().isEmpty) return '$label is required.';
              return null;
            },
      ),
    );
  }
}

class _ShopLocationException implements Exception {
  const _ShopLocationException(this.message);
  final String message;

  @override
  String toString() => message;
}
