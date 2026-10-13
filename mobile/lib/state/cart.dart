import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../util/format.dart';

/// The signed-in buyer's basket. Every cart endpoint answers with the whole priced cart, so each change
/// replaces it rather than patching it locally.
class CartController extends ChangeNotifier {
  CartController(this.api);

  final ApiClient api;

  Cart? cart;
  bool loading = false;
  Object? error;
  int _pending = 0;

  /// True while a change is in flight; buttons wait so quantities never race each other.
  bool get busy => _pending > 0;

  List<CartLine> get lines => cart?.lines ?? const [];

  int get count => lines.length;

  List<CartLine> linesFor(String productId) => lines.where((line) => line.productId == productId).toList();

  CartLine? lineForVariant(String variantId) {
    for (final line in lines) {
      if (line.variantId == variantId) return line;
    }
    return null;
  }

  Future<void> load() async {
    loading = true;
    error = null;
    notifyListeners();
    try {
      cart = Cart.fromJson((await api.get('/cart'))['data'] as Map<String, dynamic>);
    } catch (e) {
      error = e;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  /// Sets the quantity of a selling option, adding it if it isn't in the basket yet.
  Future<void> add(String variantId, String quantity) =>
      _change(() => api.post('/cart/items', {'variant_id': variantId, 'quantity': quantity}));

  Future<void> update(CartLine line, String quantity) =>
      _change(() => api.patch('/cart/items/${line.cartItemId}', {'quantity': quantity}));

  Future<void> remove(CartLine line) => _change(() => api.delete('/cart/items/${line.cartItemId}'));

  /// One unit more or less; stepping below one unit removes the line.
  Future<void> step(CartLine line, int direction) {
    final next = (toMilli(line.quantity) ?? 0) + direction * 1000;
    return next <= 0 ? remove(line) : update(line, fromMilli(next));
  }

  Future<void> clear() async {
    _pending++;
    notifyListeners();
    try {
      await api.delete('/cart');
    } finally {
      _pending--;
    }
    await load();
  }

  void reset() {
    cart = null;
    error = null;
    notifyListeners();
  }

  Future<void> _change(Future<dynamic> Function() request) async {
    _pending++;
    notifyListeners();
    try {
      final response = await request();
      cart = Cart.fromJson((response as Map<String, dynamic>)['data'] as Map<String, dynamic>);
    } finally {
      _pending--;
      notifyListeners();
    }
  }
}
