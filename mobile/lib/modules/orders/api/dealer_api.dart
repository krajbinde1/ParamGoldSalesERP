import 'package:dio/dio.dart';
import '../models/order_dealer.dart';

class DealerApi {
  const DealerApi(this._dio);
  final Dio _dio;

  Future<List<OrderDealer>> list() async {
    try {
      final response = await _dio.get('/employee/dealers');
      final body = response.data;
      if (body is! Map) return const [];

      final root = Map<String, dynamic>.from(body);
      final raw = root['data'] ?? root['dealers'];
      if (raw is! List) return const [];

      return raw
          .map(
            (item) =>
                OrderDealer.fromJson(Map<String, dynamic>.from(item as Map)),
          )
          .where((dealer) => dealer.id > 0 && dealer.name.isNotEmpty)
          .toList();
    } on DioException {
      return const [];
    }
  }
}
