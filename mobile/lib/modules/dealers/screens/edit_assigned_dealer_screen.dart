import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/locations/location_api.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../../field_activities/widgets/searchable_picker.dart';
import '../api/dealer_account_api.dart';
import '../models/dealer_account.dart';

class EditAssignedDealerScreen extends StatefulWidget {
  const EditAssignedDealerScreen({
    super.key,
    required this.auth,
    required this.dealerId,
  });

  final AuthController auth;
  final int dealerId;

  @override
  State<EditAssignedDealerScreen> createState() =>
      _EditAssignedDealerScreenState();
}

class _EditAssignedDealerScreenState extends State<EditAssignedDealerScreen> {
  final _formKey = GlobalKey<FormState>();
  final _owner = TextEditingController();
  final _mobile = TextEditingController();
  final _email = TextEditingController();
  final _place = TextEditingController();

  late final DealerAccountApi _api;
  late final LocationApi _locations;

  AssignedDealerListItem? _dealer;
  bool _loading = true;
  bool _saving = false;
  Object? _loadError;

  List<SearchablePickerOption> _districts = [];
  List<SearchablePickerOption> _talukas = [];
  final Map<int, MaharashtraDistrictNode> _districtById = {};
  int? _districtId;
  String _districtLabel = '';
  int? _talukaId;
  String _talukaLabel = '';
  bool _loadingDistricts = false;
  bool _districtsError = false;
  bool _loadingTalukas = false;
  bool _talukasError = false;

  @override
  void initState() {
    super.initState();
    final client = ApiClient(
      SessionStore(),
      onUnauthorized: widget.auth.sessionExpired,
    );
    _api = DealerAccountApi(client.dio);
    _locations = LocationApi(client.dio);
    _load();
  }

  @override
  void dispose() {
    _owner.dispose();
    _mobile.dispose();
    _email.dispose();
    _place.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _loadError = null;
    });
    try {
      final dealer = await _api.showAssigned(widget.dealerId);
      if (!mounted) return;
      _dealer = dealer;
      _owner.text = dealer.ownerName ?? '';
      _mobile.text = dealer.mobile ?? '';
      _email.text = dealer.email ?? '';
      _place.text = dealer.village ?? '';
      _districtLabel = dealer.district ?? '';
      _talukaLabel = dealer.taluka ?? '';
      setState(() => _loading = false);
      await _loadDistricts();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _loadError = error;
      });
    }
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

  SearchablePickerOption? _matchDistrict(String name) {
    final byLabel = _matchOption(_districts, name);
    if (byLabel != null) return byLabel;
    final needle = name.trim().toLowerCase();
    if (needle.isEmpty) return null;
    for (final option in _districts) {
      final district = _districtById[option.id];
      if (district == null) continue;
      if (district.name.toLowerCase() == needle) return option;
      final former = district.formerName?.toLowerCase().trim() ?? '';
      if (former.isNotEmpty && former == needle) return option;
    }
    return null;
  }

  Future<void> _loadDistricts() async {
    setState(() {
      _loadingDistricts = true;
      _districtsError = false;
    });
    try {
      final master = await _locations.maharashtra();
      if (!mounted) return;
      final options = <SearchablePickerOption>[];
      _districtById.clear();
      for (var i = 0; i < master.districts.length; i++) {
        final district = master.districts[i];
        if (district.name.trim().isEmpty) continue;
        final id = i + 1;
        _districtById[id] = district;
        options.add(SearchablePickerOption(id: id, label: district.label));
      }
      setState(() {
        _districts = options;
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

  Future<void> _syncLocationFromSavedNames() async {
    if (_districts.isEmpty || _districtLabel.trim().isEmpty) return;
    final district = _matchDistrict(_districtLabel);
    if (district == null) return;
    setState(() {
      _districtId = district.id;
      _districtLabel = district.label;
    });
    _applyTalukasForDistrict(district.id, preserveTaluka: true);
  }

  void _applyTalukasForDistrict(
    int districtId, {
    bool preserveTaluka = false,
  }) {
    final district = _districtById[districtId];
    final options = (district?.talukas ?? const [])
        .asMap()
        .entries
        .map(
          (entry) => SearchablePickerOption(
            id: entry.key + 1,
            label: entry.value,
          ),
        )
        .toList();
    SearchablePickerOption? saved;
    if (preserveTaluka) {
      saved = _matchOption(options, _talukaLabel);
    }
    setState(() {
      _talukas = options;
      _loadingTalukas = false;
      _talukasError = false;
      if (saved != null) {
        _talukaId = saved.id;
        _talukaLabel = saved.label;
      } else if (!preserveTaluka) {
        _talukaId = null;
        _talukaLabel = '';
      }
    });
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
    _applyTalukasForDistrict(selected.id);
  }

  Future<void> _pickTaluka() async {
    if (_districtId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Select a district first.')),
      );
      return;
    }
    if (_talukasError) {
      _applyTalukasForDistrict(_districtId!, preserveTaluka: true);
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

  String _savedDistrictName() {
    final id = _districtId;
    if (id != null) {
      final name = _districtById[id]?.name.trim() ?? '';
      if (name.isNotEmpty) return name;
    }
    return _districtLabel.trim();
  }

  Future<void> _save() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    setState(() => _saving = true);
    try {
      await _api.updateAssigned(
        dealerId: widget.dealerId,
        payload: {
          'owner_name': _owner.text.trim().isEmpty ? null : _owner.text.trim(),
          'mobile': _mobile.text.trim(),
          'email': _email.text.trim().isEmpty ? null : _email.text.trim(),
          'district': _savedDistrictName(),
          'taluka': _talukaLabel.trim(),
          'village': _place.text.trim(),
        },
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Dealer details updated.')),
      );
      context.pop(true);
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
      title: 'Edit Dealer',
      showBack: true,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _loadError != null
              ? PgErrorState(
                  message: errorMessage(_loadError),
                  onRetry: _load,
                )
              : Form(
                  key: _formKey,
                  child: ListView(
                    padding: const EdgeInsets.all(AppSpacing.screenPadding),
                    children: [
                      PgCard(
                        child: Column(
                          children: [
                            _readOnlyField(
                              'Dealer Name',
                              _dealer?.firmName ?? '—',
                            ),
                            _field(_owner, 'Owner Name', requiredField: false),
                            _field(
                              _mobile,
                              'Mobile Number',
                              keyboard: TextInputType.phone,
                              textCapitalization: TextCapitalization.none,
                              validator: (value) {
                                final text = value?.trim() ?? '';
                                if (!RegExp(r'^[6-9][0-9]{9}$').hasMatch(text)) {
                                  return 'Enter a valid 10-digit mobile number.';
                                }
                                return null;
                              },
                            ),
                            _field(
                              _email,
                              'Email ID',
                              requiredField: false,
                              keyboard: TextInputType.emailAddress,
                              textCapitalization: TextCapitalization.none,
                              validator: (value) {
                                final text = value?.trim() ?? '';
                                if (text.isEmpty) return null;
                                if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$')
                                    .hasMatch(text)) {
                                  return 'Enter a valid email ID.';
                                }
                                return null;
                              },
                            ),
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
                            _field(_place, 'Place'),
                          ],
                        ),
                      ),
                      const SizedBox(height: AppSpacing.lg),
                      FilledButton(
                        onPressed: _saving ? null : _save,
                        child: Text(_saving ? 'Saving...' : 'Save Changes'),
                      ),
                    ],
                  ),
                ),
    );
  }

  Widget _readOnlyField(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: InputDecorator(
        decoration: InputDecoration(labelText: label),
        child: Text(
          value,
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
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
    TextInputType? keyboard,
    TextCapitalization textCapitalization = TextCapitalization.words,
    String? Function(String?)? validator,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: TextFormField(
        controller: controller,
        enabled: !_saving,
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
