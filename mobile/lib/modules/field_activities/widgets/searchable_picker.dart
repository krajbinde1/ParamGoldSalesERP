import 'package:flutter/material.dart';
import '../../../core/design/app_spacing.dart';

class SearchablePickerOption {
  const SearchablePickerOption({required this.id, required this.label});

  final int id;
  final String label;
}

Future<SearchablePickerOption?> showSearchablePicker({
  required BuildContext context,
  required String title,
  required List<SearchablePickerOption> options,
  int? selectedId,
}) {
  return showModalBottomSheet<SearchablePickerOption>(
    context: context,
    isScrollControlled: true,
    builder: (context) => _SearchablePickerSheet(
      title: title,
      options: options,
      selectedId: selectedId,
    ),
  );
}

class _SearchablePickerSheet extends StatefulWidget {
  const _SearchablePickerSheet({
    required this.title,
    required this.options,
    this.selectedId,
  });

  final String title;
  final List<SearchablePickerOption> options;
  final int? selectedId;

  @override
  State<_SearchablePickerSheet> createState() => _SearchablePickerSheetState();
}

class _SearchablePickerSheetState extends State<_SearchablePickerSheet> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final filtered = widget.options.where((option) {
      if (_query.trim().isEmpty) return true;
      return option.label.toLowerCase().contains(_query.trim().toLowerCase());
    }).toList();

    return SafeArea(
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.7,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(
            children: [
              Text(widget.title, style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: AppSpacing.sm),
              TextField(
                autofocus: true,
                decoration: const InputDecoration(
                  hintText: 'Search',
                  prefixIcon: Icon(Icons.search),
                ),
                onChanged: (value) => setState(() => _query = value),
              ),
              const SizedBox(height: AppSpacing.sm),
              Expanded(
                child: filtered.isEmpty
                    ? const Center(child: Text('No matches'))
                    : ListView.builder(
                        itemCount: filtered.length,
                        itemBuilder: (context, index) {
                          final option = filtered[index];
                          final selected = option.id == widget.selectedId;
                          return ListTile(
                            title: Text(option.label),
                            trailing: selected ? const Icon(Icons.check) : null,
                            onTap: () => Navigator.pop(context, option),
                          );
                        },
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
