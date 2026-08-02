import 'package:flutter/material.dart';
import '../../../core/widgets/design/pg_empty_state.dart';
import '../../../core/widgets/design/pg_scaffold.dart';

class ComingSoonScreen extends StatelessWidget {
  const ComingSoonScreen({super.key, required this.module});
  final String module;

  @override
  Widget build(BuildContext context) => PgPageScaffold(
    title: module,
    showBack: true,
    body: PgEmptyState(
      message: 'Module coming next',
      icon: const Icon(Icons.construction_rounded),
    ),
  );
}
