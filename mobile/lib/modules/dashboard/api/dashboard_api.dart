import 'package:dio/dio.dart';
import '../models/dashboard_data.dart';

class DashboardApi {
  const DashboardApi(this._dio);
  final Dio _dio;

  Future<DashboardData> load() async {
    final response = await _dio.get('/employee/dashboard');
    return DashboardData.fromJson(Map<String, dynamic>.from(response.data));
  }
}
