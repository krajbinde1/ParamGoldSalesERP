import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../../field_activities/models/field_activity_detail.dart';
import '../../field_activities/widgets/searchable_picker.dart';
import '../api/manager_api.dart';

class ManagerFieldActivitiesScreen extends StatefulWidget {
  const ManagerFieldActivitiesScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<ManagerFieldActivitiesScreen> createState() =>
      _ManagerFieldActivitiesScreenState();
}

class _ManagerFieldActivitiesScreenState
    extends State<ManagerFieldActivitiesScreen> {
  late final ManagerApi _api;

  List<Map<String, dynamic>> _employees = [];
  List<SearchablePickerOption> _districts = [];
  List<SearchablePickerOption> _talukas = [];
  List<SearchablePickerOption> _crops = [];
  List<SearchablePickerOption> _products = [];

  int? _employeeId;
  int? _districtId;
  String _districtLabel = '';
  int? _talukaId;
  String _talukaLabel = '';
  int? _cropId;
  String _cropLabel = '';
  int? _productId;
  String _productLabel = '';
  DateTime? _dateFrom;
  DateTime? _dateTo;

  Future<ManagerFieldActivityListResult>? _future;
  bool _loadingMore = false;
  List<Map<String, dynamic>> _rows = [];
  int _page = 1;
  int _lastPage = 1;
  int _total = 0;

  @override
  void initState() {
    super.initState();
    _api = ManagerApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    );
    final now = DateTime.now();
    _dateFrom = DateTime(now.year, now.month, 1);
    _dateTo = now;
    _loadFilters();
    _reload();
  }

  Future<void> _loadFilters() async {
    try {
      final employees = await _api.listEmployeePerformance();
      final districts = await _api.fieldActivityDistricts();
      final crops = await _api.fieldActivityCrops();
      final products = await _api.listProducts();
      if (!mounted) return;
      setState(() {
        _employees = employees.employees;
        _districts = districts
            .map(
              (row) => SearchablePickerOption(
                id: int.tryParse('${row['id']}') ?? 0,
                label: row['label']?.toString() ?? row['name']?.toString() ?? '-',
              ),
            )
            .where((option) => option.id > 0)
            .toList();
        _crops = crops
            .map(
              (row) => SearchablePickerOption(
                id: int.tryParse('${row['id']}') ?? 0,
                label: row['name']?.toString() ?? '-',
              ),
            )
            .where((option) => option.id > 0)
            .toList();
        _products = products
            .map(
              (row) => SearchablePickerOption(
                id: int.tryParse('${row['id']}') ?? 0,
                label: row['product_name']?.toString() ?? '-',
              ),
            )
            .where((option) => option.id > 0)
            .toList();
      });
    } catch (_) {
      // List still loads; filters stay empty.
    }
  }

  String? get _dateFromParam =>
      _dateFrom == null ? null : DateFormat('yyyy-MM-dd').format(_dateFrom!);

  String? get _dateToParam =>
      _dateTo == null ? null : DateFormat('yyyy-MM-dd').format(_dateTo!);

  void _reload() {
    _page = 1;
    _rows = [];
    setState(() {
      _future = _fetchPage(1, replace: true);
    });
  }

  Future<ManagerFieldActivityListResult> _fetchPage(
    int page, {
    required bool replace,
  }) async {
    final result = await _api.listFieldActivities(
      page: page,
      employeeId: _employeeId,
      districtId: _districtId,
      talukaId: _talukaId,
      cropId: _cropId,
      productId: _productId,
      dateFrom: _dateFromParam,
      dateTo: _dateToParam,
    );
    if (!mounted) return result;
    setState(() {
      _page = result.currentPage;
      _lastPage = result.lastPage;
      _total = result.total;
      _rows = replace ? result.rows : [..._rows, ...result.rows];
    });
    return result;
  }

  Future<void> _refresh() async {
    _reload();
    await _future;
  }

  Future<void> _loadMore() async {
    if (_loadingMore || _page >= _lastPage) return;
    setState(() => _loadingMore = true);
    try {
      await _fetchPage(_page + 1, replace: false);
    } finally {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  Future<void> _pickDateRange() async {
    final from = await showDatePicker(
      context: context,
      initialDate: _dateFrom ?? DateTime.now(),
      firstDate: DateTime(2020),
      lastDate: DateTime.now(),
    );
    if (!mounted || from == null) return;
    final to = await showDatePicker(
      context: context,
      initialDate: _dateTo ?? from,
      firstDate: from,
      lastDate: DateTime.now(),
    );
    if (!mounted || to == null) return;
    setState(() {
      _dateFrom = from;
      _dateTo = to;
    });
    _reload();
  }

  Future<void> _pickDistrict() async {
    final selected = await showSearchablePicker(
      context: context,
      title: 'District',
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
    try {
      final talukas = await _api.fieldActivityTalukas(selected.id);
      if (!mounted) return;
      setState(() {
        _talukas = talukas
            .map(
              (row) => SearchablePickerOption(
                id: int.tryParse('${row['id']}') ?? 0,
                label: row['name']?.toString() ?? '-',
              ),
            )
            .where((option) => option.id > 0)
            .toList();
      });
    } catch (_) {}
    _reload();
  }

  Future<void> _pickTaluka() async {
    if (_districtId == null) return;
    final selected = await showSearchablePicker(
      context: context,
      title: 'Taluka',
      options: _talukas,
      selectedId: _talukaId,
    );
    if (selected == null || !mounted) return;
    setState(() {
      _talukaId = selected.id;
      _talukaLabel = selected.label;
    });
    _reload();
  }

  Future<void> _pickCrop() async {
    final selected = await showSearchablePicker(
      context: context,
      title: 'Crop',
      options: _crops,
      selectedId: _cropId,
    );
    if (selected == null || !mounted) return;
    setState(() {
      _cropId = selected.id;
      _cropLabel = selected.label;
    });
    _reload();
  }

  Future<void> _pickProduct() async {
    final selected = await showSearchablePicker(
      context: context,
      title: 'Recommended Product',
      options: _products,
      selectedId: _productId,
    );
    if (selected == null || !mounted) return;
    setState(() {
      _productId = selected.id;
      _productLabel = selected.label;
    });
    _reload();
  }

  Widget _filterChip({
    required String label,
    required VoidCallback onTap,
    VoidCallback? onClear,
  }) {
    return Padding(
      padding: const EdgeInsets.only(right: 8, bottom: 8),
      child: InputChip(
        label: Text(label),
        onPressed: onTap,
        onDeleted: onClear,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: RoleAppBar(title: 'Field Activities', auth: widget.auth),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<ManagerFieldActivityListResult>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting &&
                _rows.isEmpty) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: const [PgLoadingState()],
              );
            }

            if (snapshot.hasError && _rows.isEmpty) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgErrorState(
                    message: errorMessage(snapshot.error),
                    onRetry: _refresh,
                  ),
                ],
              );
            }

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                Text(
                  '$_total team field activities',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: AppSpacing.sm),
                InputDecorator(
                  decoration: const InputDecoration(
                    labelText: 'Employee',
                    border: OutlineInputBorder(),
                  ),
                  child: DropdownButtonHideUnderline(
                    child: DropdownButton<int?>(
                      value: _employeeId,
                      isExpanded: true,
                      items: [
                        const DropdownMenuItem<int?>(
                          value: null,
                          child: Text('All employees'),
                        ),
                        ..._employees.map(
                          (employee) => DropdownMenuItem<int?>(
                            value: int.tryParse('${employee['employee_id']}'),
                            child: Text(
                              employee['employee_name']?.toString() ?? '-',
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ),
                      ],
                      onChanged: (value) {
                        setState(() => _employeeId = value);
                        _reload();
                      },
                    ),
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                Wrap(
                  children: [
                    _filterChip(
                      label: _districtLabel.isEmpty
                          ? 'District'
                          : _districtLabel,
                      onTap: _pickDistrict,
                      onClear: _districtId == null
                          ? null
                          : () {
                              setState(() {
                                _districtId = null;
                                _districtLabel = '';
                                _talukaId = null;
                                _talukaLabel = '';
                                _talukas = [];
                              });
                              _reload();
                            },
                    ),
                    _filterChip(
                      label: _talukaLabel.isEmpty ? 'Taluka' : _talukaLabel,
                      onTap: _pickTaluka,
                      onClear: _talukaId == null
                          ? null
                          : () {
                              setState(() {
                                _talukaId = null;
                                _talukaLabel = '';
                              });
                              _reload();
                            },
                    ),
                    _filterChip(
                      label: _cropLabel.isEmpty ? 'Crop' : _cropLabel,
                      onTap: _pickCrop,
                      onClear: _cropId == null
                          ? null
                          : () {
                              setState(() {
                                _cropId = null;
                                _cropLabel = '';
                              });
                              _reload();
                            },
                    ),
                    _filterChip(
                      label: _productLabel.isEmpty
                          ? 'Product'
                          : _productLabel,
                      onTap: _pickProduct,
                      onClear: _productId == null
                          ? null
                          : () {
                              setState(() {
                                _productId = null;
                                _productLabel = '';
                              });
                              _reload();
                            },
                    ),
                    _filterChip(
                      label: _dateFrom != null && _dateTo != null
                          ? '${DateFormat('d MMM').format(_dateFrom!)} – ${DateFormat('d MMM').format(_dateTo!)}'
                          : 'Date',
                      onTap: _pickDateRange,
                      onClear: _dateFrom == null
                          ? null
                          : () {
                              setState(() {
                                _dateFrom = null;
                                _dateTo = null;
                              });
                              _reload();
                            },
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                const PgSectionHeader(title: 'Team Farmer Activities'),
                if (_rows.isEmpty)
                  const PgEmptyState(
                    message: 'No field activities found for the selected filters.',
                    icon: Icon(Icons.agriculture_outlined),
                  )
                else
                  for (final row in _rows)
                    PgCard(
                      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                      onTap: () => context.push(
                        '/manager/field-activities/${row['id']}',
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  row['farmer_name']?.toString() ?? '-',
                                  style: Theme.of(context)
                                      .textTheme
                                      .titleMedium
                                      ?.copyWith(fontWeight: FontWeight.w700),
                                ),
                              ),
                              PgStatusBadge(
                                label: row['status_label']?.toString() ??
                                    'Completed',
                                tone: PgStatusTone.approved,
                              ),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Text(
                            [
                              if ((row['farmer_mobile']?.toString().isNotEmpty ??
                                  false))
                                row['farmer_mobile'],
                              if ((row['district']?.toString().isNotEmpty ??
                                  false))
                                row['district'],
                              row['village'],
                              row['taluka'],
                            ].whereType<Object>().map((part) => '$part').join(' • '),
                          ),
                          if ((row['crop_name']?.toString().isNotEmpty ?? false))
                            Text('Crop: ${row['crop_name']}'),
                          Text(
                            [
                              row['employee_name']?.toString() ?? '',
                              row['activity_date']?.toString() ?? '',
                            ].where((part) => part.isNotEmpty).join(' • '),
                            style: Theme.of(context)
                                .textTheme
                                .bodySmall
                                ?.copyWith(color: AppColors.textMuted),
                          ),
                          if ((row['recommendations'] as List?)?.isNotEmpty ??
                              false)
                            Text(
                              ((row['recommendations'] as List)
                                      .map((item) {
                                        if (item is! Map) return '';
                                        return item['product_name']?.toString() ??
                                            '';
                                      })
                                      .where((name) => name.isNotEmpty)
                                      .join(', ')),
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                        ],
                      ),
                    ),
                if (_page < _lastPage) ...[
                  const SizedBox(height: AppSpacing.sm),
                  OutlinedButton(
                    onPressed: _loadingMore ? null : _loadMore,
                    child: Text(_loadingMore ? 'Loading…' : 'Load more'),
                  ),
                ],
              ],
            );
          },
        ),
      ),
    );
  }
}

class ManagerFieldActivityDetailRoute {
  const ManagerFieldActivityDetailRoute._();

  static Future<FieldActivityDetail> load(
    AuthController auth,
    int activityId,
  ) {
    return ManagerApi(
      ApiClient(SessionStore(), onUnauthorized: auth.sessionExpired).dio,
    ).getFieldActivity(activityId).then(FieldActivityDetail.fromJson);
  }
}
