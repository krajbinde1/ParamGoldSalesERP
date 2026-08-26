import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/prompt_dialog.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/credit_note_api.dart';
import '../models/credit_note.dart';
import '../widgets/credit_note_widgets.dart';
import 'credit_note_detail_screen.dart';
import 'credit_note_form_screen.dart';

class ManagerCreditNoteListScreen extends StatefulWidget {
  const ManagerCreditNoteListScreen({
    super.key,
    required this.auth,
    this.initialTab = 'pending',
  });

  final AuthController auth;
  final String initialTab;

  @override
  State<ManagerCreditNoteListScreen> createState() =>
      _ManagerCreditNoteListScreenState();
}

class _ManagerCreditNoteListScreenState extends State<ManagerCreditNoteListScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs;
  late final ManagerCreditNoteApi _api;
  static const _statuses = [
    'pending_approval',
    'approved',
    'completed',
    'rejected',
  ];
  final Map<String, Future<ManagerCreditNoteListResult>> _futures = {};

  @override
  void initState() {
    super.initState();
    final initialIndex = switch (widget.initialTab) {
      'approved' => 1,
      'completed' => 2,
      'rejected' => 3,
      _ => 0,
    };
    _tabs = TabController(
      length: _statuses.length,
      vsync: this,
      initialIndex: initialIndex,
    );
    _api = ManagerCreditNoteApi(
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
      for (final status in _statuses) {
        _futures[status] = _api.list(status: status);
      }
    });
  }

  Future<void> _openDetail(int id) async {
    await context.push('/manager/credit-notes/$id');
    if (!mounted) return;
    _reloadAll();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Team Credit Notes',
        auth: widget.auth,
        showBack: true,
        onBack: () => Navigator.of(context).maybePop(),
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
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          await context.push('/manager/credit-notes/new');
          if (!mounted) return;
          _reloadAll();
        },
        icon: const Icon(Icons.add_rounded),
        label: const Text('Rate Difference'),
      ),
      body: TabBarView(
        controller: _tabs,
        children: _statuses
            .map(
              (status) => RefreshIndicator(
                onRefresh: () async => _reloadAll(),
                child: FutureBuilder<ManagerCreditNoteListResult>(
                  future: _futures[status],
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
                            message: 'Unable to load team Credit Notes.',
                            onRetry: _reloadAll,
                          ),
                        ],
                      );
                    }
                    final notes = snapshot.data?.notes ?? const [];
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
                      itemCount: notes.length,
                      itemBuilder: (context, index) {
                        final note = notes[index];
                        return CreditNoteListTile(
                          note: note,
                          showEmployee: true,
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

class ManagerCreditNoteDetailScreen extends StatefulWidget {
  const ManagerCreditNoteDetailScreen({
    super.key,
    required this.auth,
    required this.creditNoteId,
  });

  final AuthController auth;
  final int creditNoteId;

  @override
  State<ManagerCreditNoteDetailScreen> createState() =>
      _ManagerCreditNoteDetailScreenState();
}

class _ManagerCreditNoteDetailScreenState
    extends State<ManagerCreditNoteDetailScreen> {
  late Future<CreditNoteDetail> _future;
  bool _busy = false;

  ManagerCreditNoteApi get _api => ManagerCreditNoteApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _future = _api.get(widget.creditNoteId);
  }

  Future<void> _reload() async {
    setState(() => _future = _api.get(widget.creditNoteId));
    await _future;
  }

  Future<void> _approve() async {
    setState(() => _busy = true);
    try {
      await _api.approve(widget.creditNoteId);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Credit Note approved.')),
      );
      await _reload();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('$error')));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reject() async {
    final remark = await promptRemarkDialog(
      context,
      title: 'Reject Credit Note',
    );
    if (remark == null || remark.trim().isEmpty) return;
    setState(() => _busy = true);
    try {
      await _api.reject(widget.creditNoteId, remark: remark.trim());
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Credit Note rejected.')),
      );
      await _reload();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('$error')));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _edit(CreditNoteDetail detail) async {
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => CreditNoteFormScreen(
          auth: widget.auth,
          initial: detail,
          managerMode: true,
        ),
      ),
    );
    if (!mounted) return;
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Credit Note Details',
        auth: widget.auth,
        showBack: true,
        onBack: () => Navigator.of(context).maybePop(),
      ),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<CreditNoteDetail>(
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
                    message: 'Unable to load Credit Note.',
                    onRetry: _reload,
                  ),
                ],
              );
            }
            final detail = snapshot.data!;
            final pending = detail.status == 'pending_approval';
            return CreditNoteDetailBody(
              detail: detail,
              onEdit: pending && !_busy ? () => _edit(detail) : null,
              actions: pending
                  ? [
                      const SizedBox(height: AppSpacing.sm),
                      FilledButton(
                        onPressed: _busy ? null : _approve,
                        child: const Text('Approve'),
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      OutlinedButton(
                        onPressed: _busy ? null : _reject,
                        child: const Text('Reject with remarks'),
                      ),
                    ]
                  : null,
            );
          },
        ),
      ),
    );
  }
}
