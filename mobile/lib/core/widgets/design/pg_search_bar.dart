import 'package:flutter/material.dart';
import '../../design/app_colors.dart';
import '../../design/app_spacing.dart';

class PgSearchBar extends StatelessWidget {
  const PgSearchBar({
    super.key,
    required this.controller,
    this.hint = 'Search...',
    this.onChanged,
    this.onClear,
  });

  final TextEditingController controller;
  final String hint;
  final ValueChanged<String>? onChanged;
  final VoidCallback? onClear;

  @override
  Widget build(BuildContext context) => TextField(
    controller: controller,
    onChanged: onChanged,
    decoration: InputDecoration(
      hintText: hint,
      prefixIcon: const Icon(Icons.search_rounded, color: AppColors.textMuted),
      suffixIcon: controller.text.isNotEmpty
          ? IconButton(
              icon: const Icon(Icons.close_rounded, size: 20),
              onPressed: () {
                controller.clear();
                onClear?.call();
                onChanged?.call('');
              },
            )
          : null,
      filled: true,
      fillColor: AppColors.surface,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppSpacing.radiusMd),
        borderSide: const BorderSide(color: AppColors.border),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppSpacing.radiusMd),
        borderSide: const BorderSide(color: AppColors.border),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppSpacing.radiusMd),
        borderSide: const BorderSide(color: AppColors.primary, width: 1.5),
      ),
    ),
  );
}

class PgFilterChips extends StatelessWidget {
  const PgFilterChips({
    super.key,
    required this.options,
    required this.selected,
    required this.onSelected,
  });

  final List<(String label, String value)> options;
  final String selected;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) => SingleChildScrollView(
    scrollDirection: Axis.horizontal,
    child: Row(
      children: options.map((option) {
        final isSelected = option.$2 == selected;
        return Padding(
          padding: const EdgeInsets.only(right: AppSpacing.sm),
          child: FilterChip(
            label: Text(option.$1),
            selected: isSelected,
            showCheckmark: false,
            onSelected: (_) => onSelected(option.$2),
            selectedColor: AppColors.primary.withValues(alpha: 0.12),
            labelStyle: TextStyle(
              color: isSelected ? AppColors.primary : AppColors.textSecondary,
              fontWeight: isSelected ? FontWeight.w700 : FontWeight.w500,
            ),
            side: BorderSide(
              color: isSelected ? AppColors.primary : AppColors.border,
            ),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(AppSpacing.radiusMd),
            ),
          ),
        );
      }).toList(),
    ),
  );
}
