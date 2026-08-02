import 'package:flutter/material.dart';
import '../../design/app_spacing.dart';
import 'pg_card.dart';

class PgWelcomeCard extends StatelessWidget {
  const PgWelcomeCard({
    super.key,
    required this.name,
    required this.dateLabel,
    this.photoUrl,
    this.role,
  });

  final String name;
  final String dateLabel;
  final String? photoUrl;
  final String? role;

  @override
  Widget build(BuildContext context) => PgCard(
    gradient: const LinearGradient(
      colors: [Color(0xFF0F766E), Color(0xFF14B8A6)],
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
    ),
    padding: const EdgeInsets.all(AppSpacing.lg),
    child: Row(
      children: [
        Container(
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            border: Border.all(color: Colors.white.withValues(alpha: 0.4), width: 2),
          ),
          child: CircleAvatar(
            radius: 32,
            backgroundColor: Colors.white.withValues(alpha: 0.2),
            backgroundImage: photoUrl != null ? NetworkImage(photoUrl!) : null,
            child: photoUrl == null
                ? Text(
                    name.isNotEmpty ? name[0].toUpperCase() : '?',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 24,
                      fontWeight: FontWeight.w700,
                    ),
                  )
                : null,
          ),
        ),
        const SizedBox(width: AppSpacing.md),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Welcome back,',
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: Colors.white.withValues(alpha: 0.85),
                ),
              ),
              Text(
                name,
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                ),
              ),
              if (role != null) ...[
                const SizedBox(height: 2),
                Text(
                  role!,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: Colors.white.withValues(alpha: 0.75),
                  ),
                ),
              ],
              const SizedBox(height: 6),
              Row(
                children: [
                  IconTheme(
                    data: IconThemeData(
                      size: 14,
                      color: Colors.white.withValues(alpha: 0.8),
                    ),
                    child: const Icon(Icons.calendar_today_rounded),
                  ),
                  const SizedBox(width: 6),
                  Text(
                    dateLabel,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: Colors.white.withValues(alpha: 0.9),
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ],
    ),
  );
}
