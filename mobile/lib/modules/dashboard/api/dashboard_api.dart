import 'package:dio/dio.dart';
import '../models/dashboard_data.dart';

class DashboardApi {
  const DashboardApi(this._dio);
  final Dio _dio;

  Future<DashboardData> load({
    String period = 'week',
    String? startDate,
    String? endDate,
  }) {
    return _get(
      '/employee/dashboard',
      period: period,
      startDate: startDate,
      endDate: endDate,
    );
  }

  Future<DashboardData> loadTargets({
    String period = 'week',
    String? startDate,
    String? endDate,
  }) {
    return _get(
      '/employee/targets',
      period: period,
      startDate: startDate,
      endDate: endDate,
    );
  }

  Future<DashboardData> _get(
    String path, {
    required String period,
    String? startDate,
    String? endDate,
  }) async {
    final response = await _dio.get(
      path,
      queryParameters: {
        'period': period,
        if (startDate != null) 'start_date': startDate,
        if (endDate != null) 'end_date': endDate,
      },
    );
    return DashboardData.fromJson(Map<String, dynamic>.from(response.data));
  }
}
