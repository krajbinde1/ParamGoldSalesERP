import 'package:flutter/material.dart';

import '../../../core/design/app_spacing.dart';
import '../../../core/widgets/design/pg_brand.dart';
import '../providers/auth_controller.dart';

/// Lightweight bootstrap screen shown while session restore runs.
class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: PgAuthBackdrop(
        child: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.xl),
              child: ListenableBuilder(
                listenable: auth,
                builder: (context, _) {
                  final message = auth.message;
                  final hasError = message != null && message.isNotEmpty;

                  return Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const PgBrandHeader(
                        markSize: 84,
                        light: true,
                      ),
                      const SizedBox(height: AppSpacing.xxl),
                      SizedBox(
                        width: 28,
                        height: 28,
                        child: CircularProgressIndicator(
                          strokeWidth: 2.4,
                          color: Colors.white.withValues(alpha: 0.92),
                          backgroundColor:
                              Colors.white.withValues(alpha: 0.18),
                        ),
                      ),
                      const SizedBox(height: AppSpacing.md),
                      Text(
                        hasError ? message : 'Starting…',
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: hasError
                                  ? const Color(0xFFFECACA)
                                  : Colors.white.withValues(alpha: 0.85),
                              fontWeight: FontWeight.w500,
                            ),
                      ),
                      if (!hasError) ...[
                        const SizedBox(height: AppSpacing.xxl),
                        Text(
                          'PARAMGOLD SALES ERP',
                          textAlign: TextAlign.center,
                          style:
                              Theme.of(context).textTheme.labelMedium?.copyWith(
                                    color: Colors.white.withValues(alpha: 0.55),
                                    letterSpacing: 1.6,
                                    fontWeight: FontWeight.w600,
                                  ),
                        ),
                      ],
                    ],
                  );
                },
              ),
            ),
          ),
        ),
      ),
    );
  }
}
