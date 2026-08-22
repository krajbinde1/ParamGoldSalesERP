import 'dart:developer' as developer;

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../../design/app_colors.dart';
import '../../design/app_spacing.dart';
import '../../utils/public_media_url.dart';

/// Thumbnail + full-screen pinch-zoom viewer for public collection proofs.
class PgProofImage extends StatelessWidget {
  const PgProofImage({
    super.key,
    required this.url,
    this.label = 'Supporting Proof',
    this.height = 180,
  });

  final String? url;
  final String label;
  final double height;

  @override
  Widget build(BuildContext context) {
    final resolved = resolvePublicMediaUrl(url);
    if (resolved == null) {
      return const SizedBox.shrink();
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: Theme.of(context).textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w800,
              ),
        ),
        const SizedBox(height: AppSpacing.sm),
        Material(
          color: AppColors.border.withValues(alpha: 0.35),
          borderRadius: BorderRadius.circular(12),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: () => _openViewer(context, resolved),
            child: SizedBox(
              width: double.infinity,
              height: height,
              child: CachedNetworkImage(
                imageUrl: resolved,
                fit: BoxFit.cover,
                placeholder: (_, _) => const Center(
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
                errorWidget: (context, imageUrl, error) {
                  if (kDebugMode) {
                    developer.log(
                      'PARAMGOLD_PROOF_IMAGE_FAIL url=$imageUrl error=$error',
                      name: 'PgProofImage',
                    );
                  }
                  return const _ProofUnavailable();
                },
              ),
            ),
          ),
        ),
      ],
    );
  }

  Future<void> _openViewer(BuildContext context, String imageUrl) {
    return Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => PgNetworkImageViewerScreen(
          imageUrl: imageUrl,
          title: label,
        ),
      ),
    );
  }
}

class _ProofUnavailable extends StatelessWidget {
  const _ProofUnavailable();

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: AppColors.border,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const Icon(Icons.broken_image_outlined, size: 36, color: AppColors.textMuted),
          const SizedBox(height: 8),
          Text(
            'Supporting proof unavailable',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                  fontWeight: FontWeight.w600,
                ),
          ),
        ],
      ),
    );
  }
}

class PgNetworkImageViewerScreen extends StatelessWidget {
  const PgNetworkImageViewerScreen({
    super.key,
    required this.imageUrl,
    this.title = 'Supporting Proof',
  });

  final String imageUrl;
  final String title;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(title, maxLines: 1, overflow: TextOverflow.ellipsis),
      ),
      body: Center(
        child: InteractiveViewer(
          minScale: 0.8,
          maxScale: 5,
          panEnabled: true,
          child: CachedNetworkImage(
            imageUrl: imageUrl,
            fit: BoxFit.contain,
            placeholder: (_, _) => const CircularProgressIndicator(color: Colors.white),
            errorWidget: (context, url, error) {
              if (kDebugMode) {
                developer.log(
                  'PARAMGOLD_PROOF_VIEWER_FAIL url=$url error=$error',
                  name: 'PgProofImage',
                );
              }
              return const Padding(
                padding: EdgeInsets.all(AppSpacing.xl),
                child: _ProofUnavailable(),
              );
            },
          ),
        ),
      ),
    );
  }
}
