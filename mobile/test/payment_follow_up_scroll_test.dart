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
}
