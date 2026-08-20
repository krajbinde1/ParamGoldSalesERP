import 'dart:io';

import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/utils/secure_document.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../../dealer_visits/services/dealer_visit_location_service.dart';
import '../../manager/widgets/view_captured_location_button.dart';
import '../api/dealer_application_api.dart';

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
  final _state = TextEditingController();
  final _district = TextEditingController();
  final _taluka = TextEditingController();
  final _village = TextEditingController();
  final _address = TextEditingController();
  final _locationService = DealerVisitLocationService();
  final _imagePicker = ImagePicker();

  late final DealerApplicationApi _api;
  late final Dio _dio;

  int? _id;
  Map<String, dynamic>? _detail;
  double? _latitude;
  double? _longitude;
  bool _loading = false;
  bool _saving = false;
  bool _capturingLocation = false;
  String? _locationError;
  String? _duplicateWarning;

  @override
  void initState() {
    super.initState();
    final client = ApiClient(
      SessionStore(),
      onUnauthorized: widget.auth.sessionExpired,
    );
    _dio = client.dio;
    _api = DealerApplicationApi(client.dio);
    _id = widget.applicationId;
    if (_id != null) {
      _load();
    } else {
      _captureLocation();
    }
  }

  @override
  void dispose() {
    _firm.dispose();
    _owner.dispose();
    _mobile.dispose();
    _gst.dispose();
    _state.dispose();
    _district.dispose();
    _taluka.dispose();
    _village.dispose();
    _address.dispose();
    super.dispose();
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
    _state.text = detail['state']?.toString() ?? '';
    _district.text = detail['district']?.toString() ?? '';
    _taluka.text = detail['taluka']?.toString() ?? '';
    _village.text = detail['village']?.toString() ?? '';
    _address.text = detail['address']?.toString() ?? '';
    _latitude = double.tryParse('${detail['latitude'] ?? ''}');
    _longitude = double.tryParse('${detail['longitude'] ?? ''}');
    setState(() {});
  }

  Map<String, dynamic> _payload() => {
        'firm_name': _firm.text.trim(),
        'owner_name': _owner.text.trim(),
        'mobile': _mobile.text.trim(),
        'gst_no': _gst.text.trim().isEmpty ? null : _gst.text.trim().toUpperCase(),
        'state': _state.text.trim(),
        'district': _district.text.trim(),
        'taluka': _taluka.text.trim(),
        'village': _village.text.trim(),
        'address': _address.text.trim().isEmpty ? null : _address.text.trim(),
        'latitude': _latitude,
        'longitude': _longitude,
      };

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
        if (_village.text.trim().isEmpty && (location.summary ?? '').isNotEmpty) {
          _village.text = location.summary!;
        }
      });
    } catch (error) {
      if (!mounted) return;
      setState(() => _locationError = '$error');
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

  Future<void> _pickAndUpload(Map<String, dynamic> slot) async {
    final source = await showModalBottomSheet<String>(
      context: context,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Camera'),
              onTap: () => Navigator.pop(context, 'camera'),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Gallery'),
              onTap: () => Navigator.pop(context, 'gallery'),
            ),
            ListTile(
              leading: const Icon(Icons.picture_as_pdf_outlined),
              title: const Text('PDF / File'),
              onTap: () => Navigator.pop(context, 'file'),
            ),
          ],
        ),
      ),
    );
    if (source == null || !mounted) return;

    String? path;
    if (source == 'file') {
      final picked = await FilePicker.platform.pickFiles(
        type: FileType.custom,
        allowedExtensions: const ['pdf', 'jpg', 'jpeg', 'png'],
      );
      path = picked?.files.single.path;
    } else {
      final image = await _imagePicker.pickImage(
        source: source == 'camera' ? ImageSource.camera : ImageSource.gallery,
        imageQuality: 85,
      );
      path = image?.path;
    }
    if (path == null || !mounted) return;

    final file = File(path);
    final bytes = await file.length();
    if (bytes > 5 * 1024 * 1024) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('File must be 5 MB or smaller.')),
      );
      return;
    }

    if (_id == null) {
      final saved = await _saveDraft(showMessage: false);
      if (!saved) return;
    }

    setState(() => _saving = true);
    try {
      final result = await _api.uploadDocument(
        applicationId: _id!,
        documentType: slot['document_type'].toString(),
        filePath: path,
      );
      if (!mounted) return;
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
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _submit() async {
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

  List<Map<String, dynamic>> get _documents {
    final raw = _detail?['documents'];
    if (raw is! List) return const [];
    return raw.map((item) => Map<String, dynamic>.from(item as Map)).toList();
  }

  @override
  Widget build(BuildContext context) {
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
                        _field(_state, 'State'),
                        _field(_district, 'District'),
                        _field(_taluka, 'Taluka'),
                        _field(_village, 'Village / Location'),
                        _field(_address, 'Full Address', requiredField: false, maxLines: 3),
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.md),
                  PgCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'GPS Location',
                          style: Theme.of(context).textTheme.titleSmall,
                        ),
                        const SizedBox(height: 8),
                        if (_capturingLocation)
                          const LinearProgressIndicator()
                        else
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
                        TextButton.icon(
                          onPressed: _capturingLocation ? null : _captureLocation,
                          icon: const Icon(Icons.my_location),
                          label: const Text('Capture current location'),
                        ),
                      ],
                    ),
                  ),
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
                  const SizedBox(height: AppSpacing.sm),
                  if (_documents.isEmpty)
                    const Text(
                      'Save the dealer details first, then upload each document separately (PDF, JPG, JPEG, PNG — max 5 MB).',
                    ),
                  for (final slot in _documents)
                    PgCard(
                      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(slot['document_name']?.toString() ?? '-'),
                                Text(
                                  slot['uploaded'] == true
                                      ? 'Uploaded'
                                      : 'Not Uploaded',
                                  style: Theme.of(context).textTheme.bodySmall,
                                ),
                              ],
                            ),
                          ),
                          if (slot['uploaded'] == true)
                            TextButton(
                              onPressed: () => openSecureDocument(
                                context,
                                dio: _dio,
                                title: slot['document_name']?.toString() ?? 'Document',
                                mimeType: slot['mime_type']?.toString(),
                                viewPath: slot['view_path']?.toString(),
                                documentId: int.tryParse('${slot['id'] ?? ''}'),
                              ),
                              child: const Text('View'),
                            ),
                          TextButton(
                            onPressed: _saving
                                ? null
                                : () => _pickAndUpload(slot),
                            child: Text(slot['uploaded'] == true ? 'Replace' : 'Upload'),
                          ),
                        ],
                      ),
                    ),
                  const SizedBox(height: AppSpacing.lg),
                  OutlinedButton(
                    onPressed: _saving ? null : () => _saveDraft(),
                    child: Text(_saving ? 'Saving...' : 'Save Draft'),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  FilledButton(
                    onPressed: _saving ? null : _submit,
                    child: const Text('Submit for Approval'),
                  ),
                  const SizedBox(height: AppSpacing.xxl),
                ],
              ),
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
        decoration: InputDecoration(labelText: requiredField ? '$label *' : label),
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
