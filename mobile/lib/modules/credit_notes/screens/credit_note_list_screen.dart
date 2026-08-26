import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/credit_note_api.dart';
import '../models/credit_note.dart';
import '../widgets/credit_note_widgets.dart';

class CreditNoteListScreen extends StatefulWidget {
  const CreditNoteListScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<CreditNoteListScreen> createState() => _CreditNoteListScreenState();
}

class _CreditNoteListScreenState extends State<CreditNoteListScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs;
  late final CreditNoteApi _api;

  static const _filters = [
    'pending_approval',
    'approved',
    'completed',
    'rejected',
  ];

  final Map<String, Future<List<CreditNoteListItem>>> _futures = {};

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: _filters.length, vsync: this);
    _api = CreditNoteApi(
      ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
    );
    _reloadAll();
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  void _reloadAll() {
    setState(() {
      for (final filter in _filters) {
        _futures[filter] = _api.list(filter: filter);
      }
    });
  }

  Future<void> _openNew() async {
    await context.push('/credit-notes/new');
    if (!mounted) return;
    _reloadAll();
  }

  Future<void> _openDetail(int id) async {
    await context.push('/credit-notes/$id');
    if (!mounted) return;
    _reloadAll();
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      auth: widget.auth,
      title: 'Credit Notes',
      showBack: true,
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openNew,
        icon: const Icon(Icons.add_rounded),
        label: const Text('New Credit Note'),
      ),
      bottom: TabBar(
        controller: _tabs,
        isScrollable: true,
        tabs: const [
          Tab(text: 'Pending Approval'),
          Tab(text: 'Approved'),
          Tab(text: 'Completed'),
          Tab(text: 'Rejected'),
        ],
      ),
      body: TabBarView(
        controller: _tabs,
        children: _filters
            .map(
              (filter) => RefreshIndicator(
                onRefresh: () async => _reloadAll(),
                child: FutureBuilder<List<CreditNoteListItem>>(
                  future: _futures[filter],
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
                            message: 'Unable to load Credit Notes.',
                            onRetry: _reloadAll,
                          ),
                        ],
                      );
                    }
                    final notes = snapshot.data ?? const <CreditNoteListItem>[];
                    if (notes.isEmpty) {
                      return ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.all(AppSpacing.screenPadding),
                        children: const [
                          PgEmptyState(
                            message: 'No Credit Notes in this tab.',
                            icon: Icon(Icons.note_alt_outlined),
                          ),
                        ],
                      );
                    }
                    return ListView.builder(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.all(AppSpacing.screenPadding),
                      itemCount: notes.length + 1,
                      itemBuilder: (context, index) {
                        if (index == notes.length) {
                          return const SizedBox(height: 80);
                        }
                        final note = notes[index];
                        return CreditNoteListTile(
                          note: note,
                          onTap: () => _openDetail(note.id),
                        );
                      },
                    );
                  },
                ),
              ),
            )
            .toList(),
      ),
    );
  }
}
