import 'dart:typed_data';

import 'package:image/image.dart' as img;

const int creditNotePhotoMaxDimension = 1440;
const int creditNotePhotoQuality = 85;

/// Resize to 1440px on the long side and encode JPEG quality 85.
/// Returns the compressed bytes. Does not include the original image.
Uint8List compressCreditNotePhotoBytes(Uint8List original) {
  final decoded = img.decodeImage(original);
  if (decoded == null) {
    throw const FormatException('Credit Note photo could not be read.');
  }

  var working = decoded;
  if (working.width > creditNotePhotoMaxDimension ||
      working.height > creditNotePhotoMaxDimension) {
    working = working.width >= working.height
        ? img.copyResize(working, width: creditNotePhotoMaxDimension)
        : img.copyResize(working, height: creditNotePhotoMaxDimension);
  }

  return Uint8List.fromList(
    img.encodeJpg(working, quality: creditNotePhotoQuality),
  );
}
