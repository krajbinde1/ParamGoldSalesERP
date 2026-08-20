import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/dealer_application_api.dart';

enum _DealerAppTab {
  draft,
  pending,
  approved,
  correctionRequired,
  rejected,
}

class DealerApplicationsScreen extends StatefulWidget {
  const DealerApplicationsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<DealerApplicationsScreen> createState() =>
      _DealerApplicationsScreenState();
}

class _DealerApplicationsScreenState extends State<DealerApplicationsScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  late DealerApplicationApi _api;
  final Map<_DealerAppTab, Future<DealerApplicationListResult>> _futures = {};
  final Map<_DealerAppTab, int> _counts = {};

  static const _tabs = _DealerAppTab.values;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: _tabs.length, vsync: this);
    _api = DealerApplicationApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    );
    _reloadAll();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  String _tabQuery(_DealerAppTab tab) => switch (tab) {
        _DealerAppTab.draft => 'draft',
        _DealerAppTab.pending => 'pending',
        _DealerAppTab.approved => 'approved',
        _DealerAppTab.correctionRequired => 'correction_required',
        _DealerAppTab.rejected => 'rejected',
      };

  String _tabLabel(_DealerAppTab tab) => switch (tab) {
        _DealerAppTab.draft => 'Draft',
        _DealerAppTab.pending => 'Pending',
        _DealerAppTab.approved => 'Approved',
        _DealerAppTab.correctionRequired => 'Correction',
        _DealerAppTab.rejected => 'Rejected',
      };

  void _reloadAll() {
    setState(() {
      for (final tab in _tabs) {
        _futures[tab] = _api.list(tab: _tabQuery(tab));
      }
    });
  }

  Future<void> _openForm({int? id}) async {
    await context.push(
      id == null ? '/dealer-applications/new' : '/dealer-applications/$id/edit',
    );
    if (!mounted) return;
    _reloadAll();
  }

  Future<void> _openDetail(Map<String, dynamic> row) async {
    if (row['item_type']?.toString() == 'dealer') {
      return;
    }
    await context.push('/dealer-applications/${row['id']}');
    if (!mounted) return;
    _reloadAll();
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      auth: widget.auth,
      title: 'My Dealers',
      showBack: true,
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _openForm(),
        icon: const Icon(Icons.add_rounded),
        label: const Text('Create Dealer'),
      ),
      bottom: TabBar(
        controller: _tabController,
        isScrollable: true,
        tabs: [
          for (final tab in _tabs)
            Tab(text: '${_tabLabel(tab)} (${_counts[tab] ?? 0})'),
        ],
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          for (final tab in _tabs)
            _DealerApplicationTabList(
              future: _futures[tab],
              emptyMessage: 'No ${_tabLabel(tab).toLowerCase()} dealers.',
              onRefresh: () async {
                _reloadAll();
                await _futures[tab];
              },
              onCounts: (result) {
                final key = tab == _DealerAppTab.correctionRequired
                    ? 'correction_required'
                    : _tabQuery(tab);
                final count = result.counts[key] ?? result.rows.length;
                if (_counts[tab] != count) {
                  WidgetsBinding.instance.addPostFrameCallback((_) {
                    if (!mounted) return;
                    setState(() => _counts[tab] = count);
                  });
                }
              },
              onTap: _openDetail,
            ),
        ],
      ),
    );
  }
}

class _DealerApplicationTabList extends StatelessWidget {
  const _DealerApplicationTabList({
    required this.future,
    required this.emptyMessage,
    required this.onRefresh,
    required this.onCounts,
    required this.onTap,
  });

  final Future<DealerApplicationListResult>? future;
  final String emptyMessage;
  final Future<void> Function() onRefresh;
  final void Function(DealerApplicationListResult result) onCounts;
  final void Function(Map<String, dynamic> row) onTap;

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<DealerApplicationListResult>(
      future: future,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting &&
            !snapshot.hasData) {
          return const PgLoadingState();
        }
        if (snapshot.hasError) {
          return PgErrorState(
            message: errorMessage(snapshot.error),
            onRetry: onRefresh,
          );
        }
        final result = snapshot.data!;
        onCounts(result);
        if (result.rows.isEmpty) {
          return RefreshIndicator(
            onRefresh: onRefresh,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                SizedBox(
                  height: MediaQuery.sizeOf(context).height * 0.45,
                  child: PgEmptyState(message: emptyMessage),
                ),
              ],
            ),
          );
        }

        return RefreshIndicator(
          onRefresh: onRefresh,
          child: ListView.builder(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(AppSpacing.screenPadding),
            itemCount: result.rows.length,
            itemBuilder: (context, index) {
              final row = result.rows[index];
              final submitted = row['submitted_at']?.toString();
              return PgCard(
                onTap: () => onTap(row),
                margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            row['firm_name']?.toString() ?? '-',
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                        ),
                        PgStatusBadge(
                          label: row['status_label']?.toString() ??
                              row['status']?.toString() ??
                              '-',
                          tone: PgStatusRules.orderTone(
                            row['status']?.toString() ?? '',
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(row['owner_name']?.toString() ?? '-'),
                    Text(row['mobile']?.toString() ?? '-'),
                    Text(row['location']?.toString() ?? '-'),
                    if (row['dealer_code'] != null)
                      Text('Code: ${row['dealer_code']}'),
                    if (submitted != null && submitted.isNotEmpty)
                      Text(_formatWhen(submitted)),
                  ],
                ),
              );
            },
          ),
        );
      },
    );
  }
}

String _formatWhen(String raw) {
  final parsed = DateTime.tryParse(raw);
  if (parsed == null) return raw;
  return DateFormat('d MMM yyyy, h:mm a').format(parsed.toLocal());
}
