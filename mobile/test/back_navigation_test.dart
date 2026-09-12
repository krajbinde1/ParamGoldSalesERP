import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile/core/navigation/navigation_guard.dart';
import 'package:mobile/core/widgets/design/pg_scaffold.dart';

void main() {
  testWidgets('duplicate smartBack in the same frame pops only once', (
    tester,
  ) async {
    final router = GoRouter(
      initialLocation: '/list',
      routes: [
        GoRoute(
          path: '/home',
          builder: (_, _) => const Scaffold(body: Text('home')),
        ),
        GoRoute(
          path: '/list',
          builder: (_, _) => const _ListPage(),
          routes: [
            GoRoute(
              path: 'detail',
              builder: (_, _) => const _DetailPage(),
            ),
          ],
        ),
      ],
    );
    addTearDown(router.dispose);

    await tester.pumpWidget(MaterialApp.router(routerConfig: router));
    expect(find.text('list'), findsOneWidget);

    router.push('/list/detail');
    await tester.pumpAndSettle();
    expect(find.text('detail'), findsOneWidget);

    final context = tester.element(find.text('detail'));
    smartBack(context);
    smartBack(context);
    await tester.pumpAndSettle();

    expect(find.text('detail'), findsNothing);
    expect(find.text('list'), findsOneWidget);
    expect(find.text('home'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'AppBar back and system back do not rebuild list viewport during pop',
    (tester) async {
      var reloads = 0;
      final router = GoRouter(
        initialLocation: '/list',
        routes: [
          GoRoute(
            path: '/home',
            builder: (_, _) => const Scaffold(body: Text('home')),
          ),
          GoRoute(
            path: '/list',
            builder: (_, _) => _ReloadingListPage(
              onReturned: () => reloads++,
            ),
            routes: [
              GoRoute(
                path: 'detail',
                builder: (_, _) => const _DetailPage(),
              ),
            ],
          ),
        ],
      );
      addTearDown(router.dispose);

      await tester.pumpWidget(MaterialApp.router(routerConfig: router));
      await tester.pumpAndSettle();

      await tester.tap(find.text('open-detail'));
      await tester.pumpAndSettle();
      expect(find.text('detail'), findsOneWidget);

      await tester.tap(find.byTooltip('Back'));
      await tester.pump();
      expect(tester.takeException(), isNull);
      await tester.pumpAndSettle();

      expect(find.text('list'), findsOneWidget);
      expect(find.text('detail'), findsNothing);
      expect(reloads, 1);
      expect(tester.takeException(), isNull);

      await tester.tap(find.text('open-detail'));
      await tester.pumpAndSettle();
      expect(find.text('detail'), findsOneWidget);

      await tester.binding.handlePopRoute();
      await tester.pump();
      expect(tester.takeException(), isNull);
      await tester.pumpAndSettle();

      expect(find.text('list'), findsOneWidget);
      expect(find.text('detail'), findsNothing);
      expect(reloads, 2);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('PgPageScaffold system back pops without viewport assertion', (
    tester,
  ) async {
    final router = GoRouter(
      initialLocation: '/list',
      routes: [
        GoRoute(
          path: '/home',
          builder: (_, _) => const Scaffold(body: Text('home')),
        ),
        GoRoute(
          path: '/list',
          builder: (_, _) => PgPageScaffold(
            title: 'Payment Follow-up',
            showBack: true,
            body: ListView(
              children: const [Text('list-body')],
            ),
          ),
          routes: [
            GoRoute(
              path: 'detail',
              builder: (_, _) => PgPageScaffold(
                title: 'Detail',
                showBack: true,
                body: ListView(
                  children: const [Text('detail-body')],
                ),
              ),
            ),
          ],
        ),
      ],
    );
    addTearDown(router.dispose);

    await tester.pumpWidget(MaterialApp.router(routerConfig: router));
    await tester.pumpAndSettle();

    router.push('/list/detail');
    await tester.pumpAndSettle();
    expect(find.text('detail-body'), findsOneWidget);

    await tester.tap(find.byIcon(Icons.arrow_back_rounded));
    await tester.pump();
    expect(tester.takeException(), isNull);
    await tester.pumpAndSettle();

    expect(find.text('list-body'), findsOneWidget);
    expect(find.text('detail-body'), findsNothing);
    expect(tester.takeException(), isNull);

    router.push('/list/detail');
    await tester.pumpAndSettle();
    expect(find.text('detail-body'), findsOneWidget);

    await tester.binding.handlePopRoute();
    await tester.pump();
    expect(tester.takeException(), isNull);
    await tester.pumpAndSettle();

    expect(find.text('list-body'), findsOneWidget);
    expect(find.text('detail-body'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}

class _ListPage extends StatelessWidget {
  const _ListPage();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: ListView(
        children: const [ListTile(title: Text('list'))],
      ),
    );
  }
}

class _DetailPage extends StatelessWidget {
  const _DetailPage();

  @override
  Widget build(BuildContext context) {
    return SafeBackScope(
      child: Scaffold(
        appBar: AppBar(
          automaticallyImplyLeading: false,
          leading: IconButton(
            tooltip: 'Back',
            icon: const Icon(Icons.arrow_back_rounded),
            onPressed: () => smartBack(context),
          ),
        ),
        body: ListView(
          children: const [ListTile(title: Text('detail'))],
        ),
      ),
    );
  }
}

class _ReloadingListPage extends StatefulWidget {
  const _ReloadingListPage({required this.onReturned});

  final VoidCallback onReturned;

  @override
  State<_ReloadingListPage> createState() => _ReloadingListPageState();
}

class _ReloadingListPageState extends State<_ReloadingListPage> {
  int _generation = 0;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: ListView(
        key: ValueKey('list-$_generation'),
        children: [
          const ListTile(title: Text('list')),
          ListTile(
            title: const Text('open-detail'),
            onTap: () async {
              await context.push('/list/detail');
              if (!context.mounted) return;
              await afterNavigation(context, () async {
                if (!mounted) return;
                widget.onReturned();
                setState(() => _generation++);
              });
            },
          ),
        ],
      ),
    );
  }
}
