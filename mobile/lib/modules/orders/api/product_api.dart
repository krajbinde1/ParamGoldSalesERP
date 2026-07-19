import 'package:dio/dio.dart';
import '../models/product.dart';

class ProductApi {
  const ProductApi(this._dio);
  final Dio _dio;

  Future<List<Product>> list() async {
    try {
      final response = await _dio.get('/employee/products');
      final body = response.data;
      if (body is! Map) return const [];

      final root = Map<String, dynamic>.from(body);
      final raw = root['data'] ?? root['products'] ?? root['recent_products'];
      if (raw is! List) return const [];

      return raw
          .map(
            (item) => Product.fromJson(Map<String, dynamic>.from(item as Map)),
          )
          .where((product) => product.id > 0 && product.productName.isNotEmpty)
          .toList();
    } on DioException {
      return const [];
    }
  }
}
