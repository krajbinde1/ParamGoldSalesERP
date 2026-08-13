import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_errors.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/design/app_spacing.dart';
import '../../../core/notifications/notification_api.dart';
import '../../../core/storage/session_store.dart';
import '../../../core/utils/bill_document.dart';
import '../../../core/widgets/design/pg_card.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/role_shell_widgets.dart';
import '../../auth/providers/auth_controller.dart';

class NotificationHistoryScreen extends StatefulWidget {
  const NotificationHistoryScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<NotificationHistoryScreen> createState() =>
      _NotificationHistoryScreenState();
}

class _NotificationHistoryScreenState extends State<NotificationHistoryScreen> {
  late Future<NotificationHistoryResult> _future;

  NotificationApi get _api => NotificationApi(
    ApiClient(SessionStore(), onUnauthorized: widget.auth.sessionExpired).dio,
  );

  @override
  void initState() {
    super.initState();
    _reload();
  }

  void _reload() {
    setState(() => _future = _api.listNotifications());
  }

  Future<void> _refresh() async {
    _reload();
    await _future;
  }

  Future<void> _openItem(AppNotificationItem item) async {
    if (!item.read) {
      try {
        await _api.markRead(item.id);
      } catch (_) {}
    }

    final billUrl = item.billUrl;
    final route = item.route;
    if (item.type.contains('billed') &&
        billUrl != null &&
        billUrl.isNotEmpty &&
        mounted) {
      await openBillDocument(context, url: billUrl);
      return;
    }

    if (route != null && route.isNotEmpty && mounted) {
      context.push(route);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: RoleAppBar(
        title: 'Notifications',
        auth: widget.auth,
        actions: [
          IconButton(
            tooltip: 'Mark all read',
            onPressed: () async {
              try {
                await _api.markAllRead();
                if (!mounted) return;
                await _refresh();
              } catch (error) {
                if (!mounted) return;
                final messenger = ScaffoldMessenger.of(this.context);
                messenger.showSnackBar(
                  SnackBar(content: Text(errorMessage(error))),
                );
              }
            },
            icon: const Icon(Icons.done_all_rounded),
          ),
        ],
      ),
      body: FutureBuilder<NotificationHistoryResult>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting &&
              !snapshot.hasData) {
            return const PgLoadingState();
          }
          if (snapshot.hasError) {
            return RefreshIndicator(
              onRefresh: _refresh,
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.screenPadding),
                children: [
                  PgErrorState(
                    message: errorMessage(snapshot.error),
                    onRetry: _refresh,
                  ),
                ],
              ),
            );
          }

          final result = snapshot.data!;
          if (result.items.isEmpty) {
            return RefreshIndicator(
              onRefresh: _refresh,
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: const [
                  SizedBox(height: 120),
                  PgEmptyState(
                    message: 'No notifications yet',
                    icon: Icon(Icons.notifications_none_rounded),
                  ),
                ],
              ),
            );
          }

          return RefreshIndicator(
            onRefresh: _refresh,
            child: ListView.builder(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AppSpacing.screenPadding),
              itemCount: result.items.length,
              itemBuilder: (context, index) {
                final item = result.items[index];
                return PgCard(
                  margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                  onTap: () => _openItem(item),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              item.title,
                              style: Theme.of(context)
                                  .textTheme
                                  .titleSmall
                                  ?.copyWith(fontWeight: FontWeight.w800),
                            ),
                          ),
                          if (!item.read)
                            Container(
                              width: 8,
                              height: 8,
                              decoration: const BoxDecoration(
                                color: AppColors.primary,
                                shape: BoxShape.circle,
                              ),
                            ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Text(
                        item.body,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: AppColors.textSecondary,
                              height: 1.35,
                            ),
                      ),
                      if (item.createdAt != null) ...[
                        const SizedBox(height: 8),
                        Text(
                          item.createdAt!,
                          style:
                              Theme.of(context).textTheme.labelSmall?.copyWith(
                                    color: AppColors.textMuted,
                                  ),
                        ),
                      ],
                    ],
                  ),
                );
              },
            ),
          );
        },
      ),
    );
  }
}
