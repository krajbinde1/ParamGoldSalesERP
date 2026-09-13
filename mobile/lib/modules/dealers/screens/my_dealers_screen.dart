import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/dealer_account_api.dart';
import '../models/dealer_account.dart';

class MyDealersScreen extends StatefulWidget {
  const MyDealersScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<MyDealersScreen> createState() => _MyDealersScreenState();
}

class _MyDealersScreenState extends State<MyDealersScreen> {
  late Future<List<AssignedDealerListItem>> _future;
  String _query = '';

  DealerAccountApi get _api => DealerAccountApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.listAssigned();
  }

  Future<void> _reload() async {
    setState(() => _future = _api.listAssigned());
    await _future;
  }

  Future<void> _openEdit(int dealerId) async {
    final updated = await context.push<bool>('/my-dealers/$dealerId/edit');
    if (!mounted) return;
    if (updated == true) {
      await _reload();
    }
  }

  List<AssignedDealerListItem> _filtered(List<AssignedDealerListItem> dealers) {
    final needle = _query.trim().toLowerCase();
    if (needle.isEmpty) return dealers;

    return dealers.where((dealer) {
      final haystack = [
        dealer.firmName,
        dealer.village,
        dealer.mobile,
      ].whereType<String>().join(' ').toLowerCase();
      return haystack.contains(needle);
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      auth: widget.auth,
      title: 'My Dealers',
      showBack: true,
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/dealer-applications'),
        icon: const Icon(Icons.add_rounded),
        label: const Text('Create Dealer'),
      ),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<List<AssignedDealerListItem>>(
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
                    message: errorMessage(snapshot.error),
                    onRetry: _reload,
                  ),
                ],
              );
            }

            final filtered = _filtered(
              snapshot.data ?? const <AssignedDealerListItem>[],
            );

            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.screenPadding,
                AppSpacing.screenPadding,
                AppSpacing.screenPadding,
                96,
              ),
              children: [
                TextField(
                  decoration: const InputDecoration(
                    hintText: 'Search dealer name, place, or mobile',
                    prefixIcon: Icon(Icons.search_rounded),
                  ),
                  onChanged: (value) => setState(() => _query = value),
                ),
                const SizedBox(height: AppSpacing.md),
                if (filtered.isEmpty)
                  PgEmptyState(
                    message: _query.trim().isEmpty
                        ? 'No dealers are assigned to you yet.'
                        : 'No dealers match your search.',
                    icon: const Icon(Icons.store_mall_directory_outlined),
                  )
                else
                  ...filtered.map(
                    (dealer) => Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: _AssignedDealerCard(
                        dealer: dealer,
                        onTap: () => context.push('/dealers/${dealer.id}'),
                        onEdit: () => _openEdit(dealer.id),
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _AssignedDealerCard extends StatelessWidget {
  const _AssignedDealerCard({
    required this.dealer,
    required this.onTap,
    required this.onEdit,
  });

  final AssignedDealerListItem dealer;
  final VoidCallback onTap;
  final VoidCallback onEdit;

  @override
  Widget build(BuildContext context) {
    final titleStyle = Theme.of(context).textTheme.titleMedium?.copyWith(
          fontWeight: FontWeight.w800,
        );

    return PgCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(dealer.firmName, style: titleStyle),
              ),
              TextButton.icon(
                onPressed: onEdit,
                icon: const Icon(Icons.edit_outlined, size: 18),
                label: const Text('Edit'),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          _infoRow(context, 'District', dealer.district),
          _infoRow(context, 'Taluka', dealer.taluka),
          _infoRow(context, 'Place', dealer.village),
          _infoRow(context, 'Owner Name', dealer.ownerName),
          _infoRow(context, 'Mobile Number', dealer.mobile),
          _infoRow(context, 'Email ID', dealer.email),
        ],
      ),
    );
  }

  Widget _infoRow(BuildContext context, String label, String? value) {
    final display = (value ?? '').trim();
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 118,
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                    fontWeight: FontWeight.w600,
                  ),
            ),
          ),
          Expanded(
            child: Text(
              display.isEmpty ? '—' : display,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                    color: AppColors.textPrimary,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}
