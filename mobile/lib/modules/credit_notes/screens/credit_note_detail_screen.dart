import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../../../core/api/api_client.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_detail_widgets.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';
import '../../../core/widgets/design/pg_status_badge.dart';
import '../../auth/providers/auth_controller.dart';
import '../api/credit_note_api.dart';
import '../models/credit_note.dart';
import '../widgets/credit_note_widgets.dart';

class CreditNoteDetailScreen extends StatefulWidget {
  const CreditNoteDetailScreen({
    super.key,
    required this.auth,
    required this.creditNoteId,
  });

  final AuthController auth;
  final int creditNoteId;

  @override
  State<CreditNoteDetailScreen> createState() => _CreditNoteDetailScreenState();
}

class _CreditNoteDetailScreenState extends State<CreditNoteDetailScreen> {
  late Future<CreditNoteDetail> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<CreditNoteDetail> _load() => CreditNoteApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  ).get(widget.creditNoteId);

  Future<void> _reload() async {
    setState(() => _future = _load());
    await _future;
  }

  Future<void> _edit(CreditNoteDetail detail) async {
    await context.push('/credit-notes/${detail.id}/edit', extra: detail);
    if (!mounted) return;
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    return PgPageScaffold(
      title: 'Credit Note',
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
                    message: 'Unable to load Credit Note.',
                    onRetry: _reload,
                  ),
                ],
              );
            }
            return CreditNoteDetailBody(
              detail: snapshot.data!,
              onEdit: snapshot.data!.canEdit
                  ? () => _edit(snapshot.data!)
                  : null,
            );
          },
        ),
      ),
    );
  }
}

class CreditNoteDetailBody extends StatelessWidget {
  const CreditNoteDetailBody({
    super.key,
    required this.detail,
    this.onEdit,
    this.actions,
  });

  final CreditNoteDetail detail;
  final VoidCallback? onEdit;
  final List<Widget>? actions;

  @override
  Widget build(BuildContext context) {
    final currency = NumberFormat.currency(
      locale: 'en_IN',
      symbol: '₹',
      decimalDigits: 2,
    );
    final date = detail.creditNoteDate;

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(AppSpacing.screenPadding),
      children: [
        PgCard(
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      detail.creditNoteNo,
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    Text(detail.typeLabel),
                  ],
                ),
              ),
              PgStatusBadge(
                label: detail.statusLabel,
                tone: creditNoteStatusTone(detail.status),
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        PgCard(
          child: Column(
            children: [
              PgInvoiceRow(
                label: 'Dealer',
                value: detail.dealer?.name ?? '—',
              ),
              if ((detail.employeeName ?? '').isNotEmpty)
                PgInvoiceRow(label: 'Created by', value: detail.employeeName!),
              PgInvoiceRow(
                label: 'Bill Reference',
                value: detail.billReference ?? '—',
              ),
              PgInvoiceRow(
                label: 'Date',
                value: date == null
                    ? '—'
                    : DateFormat('d MMM yyyy').format(date),
              ),
              PgInvoiceRow(label: 'Amount', value: currency.format(detail.amount)),
              if ((detail.remarks ?? '').trim().isNotEmpty)
                PgInvoiceRow(label: 'Remarks', value: detail.remarks!.trim()),
            ],
          ),
        ),
        if ((detail.rejectionRemark ?? '').trim().isNotEmpty) ...[
          const SizedBox(height: AppSpacing.md),
          PgCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Rejection Remarks',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: AppSpacing.sm),
                Text(detail.rejectionRemark!.trim()),
              ],
            ),
          ),
        ],
        const SizedBox(height: AppSpacing.md),
        PgCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Products', style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: AppSpacing.sm),
              ...detail.items.map((item) {
                final line = detail.type == 'rate_difference'
                    ? 'Qty ${item.quantity} • ${currency.format(item.originalRate ?? 0)} → ${currency.format(item.revisedRate ?? 0)}'
                    : 'Qty ${item.quantity} × ${currency.format(item.rate ?? 0)}';
                return ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(item.productName),
                  subtitle: Text(
                    [
                      line,
                      if ((item.reason ?? '').trim().isNotEmpty) item.reason!.trim(),
                    ].join('\n'),
                  ),
                  trailing: Text(currency.format(item.amount)),
                );
              }),
            ],
          ),
        ),
        if ((detail.supportingDocumentUrl ?? '').isNotEmpty) ...[
          const SizedBox(height: AppSpacing.md),
          PgCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Supporting Document',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: AppSpacing.sm),
                if (detail.supportingDocumentIsImage)
                  CachedNetworkImage(
                    imageUrl: detail.supportingDocumentUrl!,
                    height: 180,
                    width: double.infinity,
                    fit: BoxFit.cover,
                  )
                else
                  Text(detail.supportingDocumentUrl!),
              ],
            ),
          ),
        ],
        const SizedBox(height: AppSpacing.md),
        CreditNoteTimeline(steps: detail.timeline),
        if (onEdit != null || (actions ?? const []).isNotEmpty) ...[
          const SizedBox(height: AppSpacing.lg),
          if (onEdit != null)
            OutlinedButton(onPressed: onEdit, child: const Text('Edit')),
          ...?actions,
        ],
        const SizedBox(height: 24),
      ],
    );
  }
}
