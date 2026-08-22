import 'package:dio/dio.dart';

class MaharashtraLocationData {
  const MaharashtraLocationData({
    required this.state,
    required this.districts,
  });

  final String state;
  final List<MaharashtraDistrictNode> districts;

  factory MaharashtraLocationData.fromJson(Map<String, dynamic> json) {
    final rows = json['districts'] as List? ?? const [];
    return MaharashtraLocationData(
      state: json['state']?.toString() ?? 'Maharashtra',
      districts: rows
          .whereType<Map>()
          .map((row) => MaharashtraDistrictNode.fromJson(
                Map<String, dynamic>.from(row),
              ))
          .toList(),
    );
  }
}

class MaharashtraDistrictNode {
  const MaharashtraDistrictNode({
    required this.name,
    required this.label,
    required this.talukas,
    this.formerName,
  });

  final String name;
  final String? formerName;
  final String label;
  final List<String> talukas;

  factory MaharashtraDistrictNode.fromJson(Map<String, dynamic> json) {
    return MaharashtraDistrictNode(
      name: json['name']?.toString() ?? '',
      formerName: json['former_name']?.toString(),
      label: json['label']?.toString() ?? json['name']?.toString() ?? '',
      talukas: (json['talukas'] as List?)
              ?.map((item) => item.toString())
              .where((item) => item.trim().isNotEmpty)
              .toList() ??
          const [],
    );
  }
}

class LocationApi {
  const LocationApi(this._dio);
  final Dio _dio;

  Future<MaharashtraLocationData> maharashtra() async {
    final response = await _dio.get('/locations/maharashtra');
    final body = response.data;
    if (body is! Map) {
      throw DioException(
        requestOptions: response.requestOptions,
        message: 'Invalid Maharashtra location response.',
      );
    }
    return MaharashtraLocationData.fromJson(Map<String, dynamic>.from(body));
  }
}
