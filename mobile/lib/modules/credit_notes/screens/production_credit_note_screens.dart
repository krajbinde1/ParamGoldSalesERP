import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../../core/widgets/prompt_dialog.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/credit_note_api.dart';
import '../models/credit_note.dart';
import '../widgets/credit_note_widgets.dart';
import 'credit_note_detail_screen.dart';

class ProductionCreditNoteListScreen extends StatefulWidget {
  const ProductionCreditNoteListScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<ProductionCreditNoteListScreen> createState() =>
      _ProductionCreditNoteListScreenState();
}

class _ProductionCreditNoteListScreenState
    extends State<ProductionCreditNoteListScreen> {
  late Future<List<CreditNoteListItem>> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<CreditNoteListItem>> _load() => ProductionCreditNoteApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  ).listPending();

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      title: 'Factory Returns',
      showBack: true,
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<List<CreditNoteListItem>>(
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
                    message: 'Unable to load factory returns.',
                    onRetry: _reload,
                  ),
                ],
              );
            }
            final notes = snapshot.data ?? const [];
            if (notes.isEmpty) {
              return ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: const [
                  PgEmptyState(message: 'No factory returns pending approval.'),
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
                  onTap: () async {
                    await context.push('/production/credit-notes/${note.id}');
                    if (!mounted) return;
                    await _reload();
                  },
                );
              },
            );
          },
        ),
      ),
    );
  }
}

class ProductionCreditNoteDetailScreen extends StatefulWidget {
  const ProductionCreditNoteDetailScreen({
    super.key,
    required this.auth,
    required this.creditNoteId,
  });

  final AuthController auth;
  final int creditNoteId;

  @override
  State<ProductionCreditNoteDetailScreen> createState() =>
      _ProductionCreditNoteDetailScreenState();
}

class _ProductionCreditNoteDetailScreenState
    extends State<ProductionCreditNoteDetailScreen> {
  late Future<CreditNoteDetail> _future;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  ProductionCreditNoteApi get _api => ProductionCreditNoteApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  Future<CreditNoteDetail> _load() => _api.get(widget.creditNoteId);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _approve(CreditNoteDetail detail) async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await _api.approve(detail.id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Factory return approved. Stock updated.'),
        ),
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

  Future<void> _reject(CreditNoteDetail detail) async {
    if (_busy) return;
    final remark = await promptRemarkDialog(
      context,
      title: 'Reject factory return',
    );
    if (remark == null || remark.trim().length < 3) return;
    setState(() => _busy = true);
    try {
      await _api.reject(detail.id, remark: remark.trim());
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Factory return rejected.')),
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

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      title: 'Factory Return',
      showBack: true,
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
                    message: 'Unable to load factory return.',
                    onRetry: _reload,
                  ),
                ],
              );
            }
            final detail = snapshot.data!;
            final canAct = detail.status == 'pending_production_approval';
            return CreditNoteDetailBody(
              detail: detail,
              actions: canAct
                  ? [
                      const SizedBox(height: AppSpacing.sm),
                      FilledButton(
                        onPressed: _busy ? null : () => _approve(detail),
                        child: const Text('Approve and add stock'),
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      OutlinedButton(
                        onPressed: _busy ? null : () => _reject(detail),
                        child: const Text('Reject'),
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
