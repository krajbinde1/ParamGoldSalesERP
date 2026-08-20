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
import '../api/manager_api.dart';

enum _ManagerDealerTab { pending, approved, correctionRequired, rejected }

class ManagerDealerApprovalsScreen extends StatefulWidget {
  const ManagerDealerApprovalsScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<ManagerDealerApprovalsScreen> createState() =>
      _ManagerDealerApprovalsScreenState();
}

class _ManagerDealerApprovalsScreenState
    extends State<ManagerDealerApprovalsScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  late ManagerApi _api;
  final Map<_ManagerDealerTab, Future<ManagerDealerApplicationListResult>>
      _futures = {};
  final Map<_ManagerDealerTab, int> _counts = {};

  static const _tabs = _ManagerDealerTab.values;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: _tabs.length, vsync: this);
    _api = ManagerApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    );
    _reloadAll();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  String _query(_ManagerDealerTab tab) => switch (tab) {
        _ManagerDealerTab.pending => 'pending',
        _ManagerDealerTab.approved => 'approved',
        _ManagerDealerTab.correctionRequired => 'correction_required',
        _ManagerDealerTab.rejected => 'rejected',
      };

  String _label(_ManagerDealerTab tab) => switch (tab) {
        _ManagerDealerTab.pending => 'Pending Approval',
        _ManagerDealerTab.approved => 'Approved',
        _ManagerDealerTab.correctionRequired => 'Correction Required',
        _ManagerDealerTab.rejected => 'Rejected',
      };

  void _reloadAll() {
    setState(() {
      for (final tab in _tabs) {
        _futures[tab] = _api.listDealerApplications(tab: _query(tab));
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      auth: widget.auth,
      title: 'Dealer Approvals',
      showBack: true,
      bottom: TabBar(
        controller: _tabController,
        isScrollable: true,
        tabs: [
          for (final tab in _tabs)
            Tab(text: '${_label(tab)} (${_counts[tab] ?? 0})'),
        ],
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          for (final tab in _tabs)
            FutureBuilder<ManagerDealerApplicationListResult>(
              future: _futures[tab],
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting &&
                    !snapshot.hasData) {
                  return const PgLoadingState();
                }
                if (snapshot.hasError) {
                  return PgErrorState(
                    message: errorMessage(snapshot.error),
                    onRetry: _reloadAll,
                  );
                }
                final result = snapshot.data!;
                final key = tab == _ManagerDealerTab.correctionRequired
                    ? 'correction_required'
                    : _query(tab);
                final count = result.counts[key] ?? result.rows.length;
                if (_counts[tab] != count) {
                  WidgetsBinding.instance.addPostFrameCallback((_) {
                    if (!mounted) return;
                    setState(() => _counts[tab] = count);
                  });
                }
                if (result.rows.isEmpty) {
                  return RefreshIndicator(
                    onRefresh: () async {
                      _reloadAll();
                      await _futures[tab];
                    },
                    child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      children: const [
                        SizedBox(height: 160, child: PgEmptyState(message: 'No applications.')),
                      ],
                    ),
                  );
                }
                return RefreshIndicator(
                  onRefresh: () async {
                    _reloadAll();
                    await _futures[tab];
                  },
                  child: ListView.builder(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(AppSpacing.screenPadding),
                    itemCount: result.rows.length,
                    itemBuilder: (context, index) {
                      final row = result.rows[index];
                      return PgCard(
                        onTap: () async {
                          await context.push(
                            '/manager/dealer-approvals/${row['id']}',
                          );
                          if (!mounted) return;
                          _reloadAll();
                        },
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
                                  label: row['status_label']?.toString() ?? '-',
                                  tone: PgStatusRules.orderTone(
                                    row['status']?.toString() ?? '',
                                  ),
                                ),
                              ],
                            ),
                            Text(row['owner_name']?.toString() ?? '-'),
                            Text(row['mobile']?.toString() ?? '-'),
                            Text(row['location']?.toString() ?? '-'),
                            Text(row['employee_name']?.toString() ?? '-'),
                            if (row['submitted_at'] != null)
                              Text(_formatWhen(row['submitted_at']?.toString())),
                          ],
                        ),
                      );
                    },
                  ),
                );
              },
            ),
        ],
      ),
    );
  }
}

String _formatWhen(String? raw) {
  if (raw == null || raw.isEmpty) return '';
  final parsed = DateTime.tryParse(raw);
  if (parsed == null) return raw;
  return DateFormat('d MMM yyyy, h:mm a').format(parsed.toLocal());
}
