import 'package:flutter/material.dart';
import '../../../core/design/app_colors.dart';
import '../../../core/utils/open_maps_location.dart';

class ViewCapturedLocationButton extends StatelessWidget {
  const ViewCapturedLocationButton({
    super.key,
    this.mapsUrl,
    this.latitude,
    this.longitude,
    this.locationAvailable,
  });

  final Object? mapsUrl;
  final Object? latitude;
  final Object? longitude;
  final Object? locationAvailable;

  @override
  Widget build(BuildContext context) {
    final available = hasCapturedCoordinates(
      latitude: latitude,
      longitude: longitude,
      mapsUrl: mapsUrl,
      locationAvailable: locationAvailable,
    );

    if (!available) {
      return Row(
        children: [
          const Icon(
            Icons.location_off_outlined,
            size: 18,
            color: AppColors.textMuted,
          ),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              'Location Not Available',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textMuted,
                    fontWeight: FontWeight.w600,
                  ),
            ),
          ),
        ],
      );
    }

    return Align(
      alignment: Alignment.centerLeft,
      child: FittedBox(
        fit: BoxFit.scaleDown,
        alignment: Alignment.centerLeft,
        child: OutlinedButton.icon(
          onPressed: () => openCapturedMapsLocation(
            context,
            mapsUrl: mapsUrl,
            latitude: latitude,
            longitude: longitude,
            locationAvailable: locationAvailable,
          ),
          icon: const Icon(Icons.location_on_rounded, size: 18),
          label: const Text('View Location'),
        ),
      ),
    );
  }
}
