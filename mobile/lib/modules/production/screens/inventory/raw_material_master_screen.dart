import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/api_errors.dart';
import '../../../../core/design/app_colors.dart';
import '../../../../core/design/app_spacing.dart';
import '../../../../core/storage/session_store.dart';
import '../../../../core/widgets/design/pg_empty_state.dart';
import '../../../../core/widgets/design/pg_status_badge.dart';
import '../../../../core/widgets/role_shell_widgets.dart';
import '../../../auth/providers/auth_controller.dart';
import '../../api/inventory_production_api.dart';

/// Raw Material Master — master records only (no stock qty/value/ledger).
class RawMaterialMasterScreen extends StatefulWidget {
  const RawMaterialMasterScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<RawMaterialMasterScreen> createState() =>
      _RawMaterialMasterScreenState();
}

class _RawMaterialMasterScreenState extends State<RawMaterialMasterScreen> {
  final _search = TextEditingController();
  String? _status; // active | inactive
  late Future<Map<String, dynamic>> _future;
  bool _canCreate = false;
  bool _canUpdate = false;

  InventoryProductionApi get _api => InventoryProductionApi(
        ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired)
            .dio,
      );

  bool get _manageFromPermissions =>
      widget.auth.permissions.canManageInventoryMasters;

  @override
  void initState() {
    super.initState();
    _canCreate = _manageFromPermissions;
    _canUpdate = _manageFromPermissions;
    _future = _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<Map<String, dynamic>> _load() async {
    final payload = await _api.listRawMaterialMasters(
      search: _search.text.trim(),
      status: _status,
    );
    final meta = payload['meta'] is Map
        ? Map<String, dynamic>.from(payload['meta'] as Map)
        : const <String, dynamic>{};
    _canCreate = meta['can_create'] == true || _manageFromPermissions;
    _canUpdate = meta['can_update'] == true || _manageFromPermissions;
    return payload;
  }

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _openForm({int? id}) async {
    final path = id == null
        ? '/production/raw-materials/create'
        : '/production/raw-materials/$id/edit';
    final changed = await context.push<bool>(path);
    if (changed == true && mounted) {
      await _reload();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(title: 'Raw Material Master', auth: widget.auth),
      floatingActionButton: _canCreate
          ? FloatingActionButton.extended(
              onPressed: () => _openForm(),
              icon: const Icon(Icons.add),
              label: const Text('Add Raw Material'),
            )
          : null,
      body: Column(
        children: [
          _filters(),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _reload,
              child: FutureBuilder<Map<String, dynamic>>(
                future: _future,
                builder: (context, snapshot) {
                  if (snapshot.connectionState == ConnectionState.waiting &&
                      !snapshot.hasData) {
                    return const PgLoadingState();
                  }
                  if (snapshot.hasError) {
                    return PgErrorState(
                      message: errorMessage(snapshot.error),
                      onRetry: _reload,
                    );
                  }

                  final items = (snapshot.data?['data'] as List?)
                          ?.map((e) => Map<String, dynamic>.from(e as Map))
                          .toList() ??
                      const [];

                  if (items.isEmpty) {
                    return const PgEmptyState(
                      message: 'No raw material masters found.',
                    );
                  }

                  return Column(
                    children: [
                      _tableHeader(context),
                      Expanded(
                        child: ListView.builder(
                          padding: EdgeInsets.only(
                            bottom: _canCreate ? 88 : AppSpacing.screenPadding,
                          ),
                          itemCount: items.length,
                          itemBuilder: (context, index) {
                            return _tableRow(
                              context,
                              items[index],
                              zebra: index.isOdd,
                            );
                          },
                        ),
                      ),
                    ],
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _filters() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.screenPadding,
        AppSpacing.sm,
        AppSpacing.screenPadding,
        AppSpacing.xs,
      ),
      child: Row(
        children: [
          Expanded(
            flex: 3,
            child: SizedBox(
              height: 40,
              child: TextField(
                controller: _search,
                style: const TextStyle(fontSize: 13),
                decoration: InputDecoration(
                  isDense: true,
                  hintText: 'Search name or code',
                  contentPadding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 8,
                  ),
                  prefixIcon: const Icon(Icons.search, size: 18),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(8),
                  ),
                ),
                onSubmitted: (_) => _reload(),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            flex: 2,
            child: SizedBox(
              height: 40,
              child: DropdownButtonFormField<String?>(
                value: _status,
                isDense: true,
                decoration: InputDecoration(
                  isDense: true,
                  contentPadding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 8,
                  ),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(8),
                  ),
                ),
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: AppColors.textPrimary,
                    ),
                items: const [
                  DropdownMenuItem(value: null, child: Text('All')),
                  DropdownMenuItem(value: 'active', child: Text('Active')),
                  DropdownMenuItem(value: 'inactive', child: Text('Inactive')),
                ],
                onChanged: (value) {
                  setState(() => _status = value);
                  _reload();
                },
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _tableHeader(BuildContext context) {
    final style = Theme.of(context).textTheme.labelSmall?.copyWith(
          fontWeight: FontWeight.w700,
          color: AppColors.textSecondary,
          letterSpacing: 0.2,
        );

    return Container(
      decoration: const BoxDecoration(
        color: AppColors.background,
        border: Border(
          bottom: BorderSide(color: AppColors.border),
          top: BorderSide(color: AppColors.border),
        ),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      child: Row(
        children: [
          Expanded(flex: 4, child: Text('Name', style: style)),
          Expanded(flex: 3, child: Text('Code', style: style)),
          Expanded(flex: 2, child: Text('Unit', style: style)),
          Expanded(
            flex: 2,
            child: Text('Status', style: style, textAlign: TextAlign.center),
          ),
          if (_canUpdate)
            SizedBox(
              width: 44,
              child: Text('Edit', style: style, textAlign: TextAlign.right),
            ),
        ],
      ),
    );
  }

  Widget _tableRow(
    BuildContext context,
    Map<String, dynamic> item, {
    required bool zebra,
  }) {
    final name = '${item['material_name'] ?? item['name'] ?? '-'}';
    final code = '${item['material_code'] ?? item['code'] ?? '-'}';
    final unit = '${item['unit'] ?? ''}';
    final active = item['status'] == true ||
        item['status'] == 1 ||
        '${item['status_label']}'.toLowerCase() == 'active';
    final id = int.tryParse('${item['id']}') ?? 0;

    final nameStyle = Theme.of(context).textTheme.bodySmall?.copyWith(
          color: AppColors.textPrimary,
          fontWeight: FontWeight.w500,
          height: 1.2,
        );
    final cellStyle = Theme.of(context).textTheme.bodySmall?.copyWith(
          color: AppColors.textPrimary,
        );

    return Material(
      color: zebra
          ? AppColors.background.withValues(alpha: 0.65)
          : AppColors.surface,
      child: Container(
        constraints: const BoxConstraints(minHeight: 44),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: const BoxDecoration(
          border: Border(
            bottom: BorderSide(color: AppColors.border, width: 0.5),
          ),
        ),
        child: Row(
          children: [
            Expanded(
              flex: 4,
              child: Text(
                name,
                style: nameStyle,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            Expanded(
              flex: 3,
              child: Text(
                code,
                style: cellStyle,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            Expanded(
              flex: 2,
              child: Text(
                unit,
                style: cellStyle,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            Expanded(
              flex: 2,
              child: Center(
                child: PgStatusBadge(
                  label: active ? 'Active' : 'Inactive',
                  tone: active ? PgStatusTone.approved : PgStatusTone.neutral,
                ),
              ),
            ),
            if (_canUpdate)
              SizedBox(
                width: 44,
                child: Align(
                  alignment: Alignment.centerRight,
                  child: IconButton(
                    visualDensity: VisualDensity.compact,
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(
                      minWidth: 36,
                      minHeight: 36,
                    ),
                    tooltip: 'Edit',
                    icon: const Icon(Icons.edit_outlined, size: 18),
                    color: AppColors.primary,
                    onPressed: id > 0 ? () => _openForm(id: id) : null,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
