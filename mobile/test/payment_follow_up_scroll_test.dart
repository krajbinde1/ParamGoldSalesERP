import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/design/app_theme.dart';
import 'package:mobile/core/widgets/design/pg_card.dart';
import 'package:mobile/core/widgets/design/pg_empty_state.dart';
import 'package:mobile/core/widgets/design/pg_status_badge.dart';

void main() {
  Widget wrap(Widget child) {
    return MaterialApp(
      theme: AppTheme.light(),
      home: Scaffold(body: child),
    );
  }

  testWidgets(
    'payment follow-up list survives load, expand, and form insert',
    (tester) async {
      final future = Future<bool>.delayed(
        const Duration(milliseconds: 20),
        () => true,
      );
      var expanded = true;
      var showForm = false;

      await tester.pumpWidget(
        wrap(
          StatefulBuilder(
            builder: (context, setState) {
              return RefreshIndicator(
                onRefresh: () async {},
                child: FutureBuilder<bool>(
                  future: future,
                  builder: (context, snapshot) {
                    final children = <Widget>[];
                    if (!snapshot.hasData) {
                      children.add(const PgLoadingState());
                    } else {
                      children.addAll([
                        const Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            PgStatusBadge(
                              label: 'Overdue',
                              tone: PgStatusTone.rejected,
                            ),
                            PgStatusBadge(
                              label: 'Due Today',
                              tone: PgStatusTone.pending,
                            ),
                          ],
                        ),
                        GestureDetector(
                          onTap: () => setState(() => expanded = !expanded),
                          child: const Text('Employee Performance'),
                        ),
                        if (expanded)
                          const PgCard(child: Text('Performance row')),
                        FilledButton(
                          onPressed: () =>
                              setState(() => showForm = !showForm),
                          child: const Text('Follow-up Again'),
                        ),
                        if (showForm)
                          const PgCard(
                            key: ValueKey('follow-up-form'),
                            child: Text('form'),
                          ),
                        const PgCard(
                          key: ValueKey('cycle-1-open'),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('Cycle #1'),
                              Wrap(
                                spacing: 6,
                                runSpacing: 6,
                                children: [
                                  PgStatusBadge(
                                    label: 'CURRENT',
                                    tone: PgStatusTone.info,
                                  ),
                                  PgStatusBadge(
                                    label: 'OPEN',
                                    tone: PgStatusTone.paid,
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      ]);
                    }
                    return ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.all(16),
                      children: children,
                    );
                  },
                ),
              );
            },
          ),
        ),
      );

      await tester.pump();
      expect(tester.takeException(), isNull);
      await tester.pumpAndSettle();
      expect(find.text('Cycle #1'), findsOneWidget);
      expect(tester.takeException(), isNull);

      await tester.tap(find.text('Employee Performance'));
      await tester.pumpAndSettle();
      expect(find.text('Performance row'), findsNothing);
      expect(tester.takeException(), isNull);

      await tester.tap(find.text('Follow-up Again'));
      await tester.pumpAndSettle();
      expect(find.text('form'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'manager dashboard payment follow-up layout does not nest scrollables',
    (tester) async {
      final future = Future<int>.delayed(
        const Duration(milliseconds: 20),
        () => 3,
      );

      await tester.pumpWidget(
        wrap(
          RefreshIndicator(
            onRefresh: () async {},
            child: FutureBuilder<int>(
              future: future,
              builder: (context, snapshot) {
                final value = snapshot.data;
                return CustomScrollView(
                  key: const PageStorageKey('manager-dashboard-scroll'),
                  physics: const AlwaysScrollableScrollPhysics(),
                  slivers: [
                    if (value == null)
                      const SliverFillRemaining(
                        hasScrollBody: false,
                        child: PgLoadingState(),
                      )
                    else
                      SliverPadding(
                        padding: const EdgeInsets.all(16),
                        sliver: SliverList(
                          delegate: SliverChildListDelegate([
                            const SizedBox(
                              height: 120,
                              child: Row(
                                children: [
                                  Expanded(
                                    child: PgCard(child: Text('Pending')),
                                  ),
                                  SizedBox(width: 8),
                                  Expanded(
                                    child: PgCard(child: Text('Targets')),
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(height: 8),
                            SizedBox(
                              height: 132,
                              child: Row(
                                children: [
                                  Expanded(
                                    child: PgCard(
                                      child: Text('Payment Follow-up $value'),
                                    ),
                                  ),
                                  const SizedBox(width: 8),
                                  const Expanded(child: SizedBox.shrink()),
                                ],
                              ),
                            ),
                            const SizedBox(height: 8),
                            const SizedBox(
                              height: 120,
                              child: Row(
                                children: [
                                  Expanded(
                                    child: PgCard(child: Text('Attendance')),
                                  ),
                                  SizedBox(width: 8),
                                  Expanded(
                                    child: PgCard(child: Text('Orders')),
                                  ),
                                ],
                              ),
                            ),
                          ]),
                        ),
                      ),
                  ],
                );
              },
            ),
          ),
        ),
      );

      await tester.pump();
      expect(tester.takeException(), isNull);
      await tester.pumpAndSettle();
      expect(find.text('Payment Follow-up 3'), findsOneWidget);
      expect(find.byType(GridView), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'payment recovery no follow-up set card shows dealer, employee, outstanding, and set action',
    (tester) async {
      var setFollowUpTapped = false;

      await tester.pumpWidget(
        wrap(
          ListView(
            padding: const EdgeInsets.all(16),
            children: [
              PgCard(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 12,
                ),
                child: const Row(
                  children: [
                    Text('⚪'),
                    SizedBox(width: 10),
                    Expanded(child: Text('No Follow-up Set')),
                    Text('2'),
                    Icon(Icons.chevron_right_rounded),
                  ],
                ),
              ),
              const SizedBox(height: 8),
              PgCard(
                padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Unset Recovery Dealer A'),
                    const Text('Follow-up Employee 9811300503'),
                    const Text('Current Outstanding'),
                    const Text('₹80,000'),
                    Align(
                      alignment: Alignment.centerRight,
                      child: FilledButton(
                        onPressed: () => setFollowUpTapped = true,
                        child: const Text('Set Follow-up'),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      );

      expect(find.text('No Follow-up Set'), findsOneWidget);
      expect(find.text('Unset Recovery Dealer A'), findsOneWidget);
      expect(find.text('Follow-up Employee 9811300503'), findsOneWidget);
      expect(find.text('Current Outstanding'), findsOneWidget);
      expect(find.text('Set Follow-up'), findsOneWidget);

      await tester.tap(find.text('Set Follow-up'));
      await tester.pump();
      expect(setFollowUpTapped, isTrue);
      expect(tester.takeException(), isNull);
    },
  );
}
