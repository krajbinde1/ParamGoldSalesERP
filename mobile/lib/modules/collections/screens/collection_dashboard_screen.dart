import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_quick_action.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/collection_api.dart';
import '../models/collection_dashboard_data.dart';
import '../widgets/collection_widgets.dart';

class CollectionDashboardScreen extends StatefulWidget {
  const CollectionDashboardScreen({super.key, required this.auth});
  final AuthController auth;

  @override
  State<CollectionDashboardScreen> createState() =>
      _CollectionDashboardScreenState();
}

class _CollectionDashboardScreenState extends State<CollectionDashboardScreen> {
  late Future<CollectionDashboardData> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<CollectionDashboardData> _load() => CollectionApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  ).loadDashboard();

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _openCollection(int collectionId) async {
    await context.push('/collections/$collectionId');
    if (!mounted) return;
    await _reload();
  }

  Future<void> _openNewCollection() async {
    await context.push('/collections/new');
    if (!mounted) return;
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 0,
    );

    return PgPageScaffold(
      auth: widget.auth,
      title: 'Collections',
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openNewCollection,
        icon: const Icon(Icons.add_rounded),
        label: const Text('New Collection'),
      ),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<CollectionDashboardData>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting &&
                !snapshot.hasData) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: const [PgLoadingState()],
              );
            }

            if (snapshot.hasError) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgErrorState(
                    message: 'Unable to load collections.',
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final data = snapshot.data ?? CollectionDashboardData.empty;
            final summary = data.summary;

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              children: [
                LayoutBuilder(
                  builder: (context, constraints) => GridView.count(
                    crossAxisCount: 2,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    childAspectRatio: constraints.maxWidth >= 700 ? 1.8 : 1.35,
                    mainAxisSpacing: AppSpacing.sm,
                    crossAxisSpacing: AppSpacing.sm,
                    children: [
                      CollectionSummaryCard(
                        label: 'Total Collection',
                        value: currency.format(summary.totalCollection),
                        color: AppColors.primary,
                      ),
                      CollectionSummaryCard(
                        label: 'This Month',
                        value: currency.format(summary.monthCollection),
                        color: AppColors.info,
                      ),
                      CollectionSummaryCard(
                        label: 'This Week',
                        value: currency.format(summary.weekCollection),
                        color: AppColors.secondary,
                      ),
                      CollectionSummaryCard(
                        label: 'Total Entries',
                        value: '${summary.totalEntries}',
                        color: AppColors.accent,
                        icon: Icons.list_alt_rounded,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                const PgSectionHeader(title: 'Recent Collections'),
                if (data.recentCollections.isEmpty)
                  const PgEmptyState(
                    message: 'No collections submitted yet.',
                    icon: Icons.payments_outlined,
                  )
                else
                  ...data.recentCollections.map(
                    (collection) => RecentCollectionTile(
                      collection: collection,
                      onTap: collection.id == 0
                          ? null
                          : () => _openCollection(collection.id),
                    ),
                  ),
                const SizedBox(height: 80),
              ],
            );
          },
        ),
      ),
    );
  }
}
