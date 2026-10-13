import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../api/models.dart';

/// Saved listings, kept on the server (/me/favourites) so they follow the buyer across devices.
class FavouritesController extends ChangeNotifier {
  FavouritesController(this.api);

  /// Enough for anyone's saved list; the screen shows them all at once.
  static const _maxPages = 10;

  final ApiClient api;

  final Set<String> _ids = {};
  List<ProductCard>? items;
  bool loading = false;
  Object? error;

  bool has(String productId) => _ids.contains(productId);

  Future<void> load() async {
    loading = true;
    error = null;
    notifyListeners();
    try {
      final all = <ProductCard>[];
      for (var page = 1; page <= _maxPages; page++) {
        final result = Paged.fromJson(
          await api.get('/me/favourites', query: {'page': page}) as Map<String, dynamic>,
          ProductCard.fromJson,
        );
        all.addAll(result.items);
        if (!result.hasMore) break;
      }
      items = all;
      _ids
        ..clear()
        ..addAll(all.map((item) => item.id));
    } catch (e) {
      error = e;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  /// Saves or unsaves at once, then confirms with the server; undone if the server refuses.
  Future<void> toggle(String productId) async {
    final saving = !_ids.contains(productId);
    saving ? _ids.add(productId) : _ids.remove(productId);
    if (!saving) items?.removeWhere((item) => item.id == productId);
    notifyListeners();

    try {
      if (saving) {
        await api.put('/me/favourites/$productId');
        // Drop the list so the Favourites tab reloads it with the new card.
        items = null;
        notifyListeners();
      } else {
        await api.delete('/me/favourites/$productId');
      }
    } catch (_) {
      saving ? _ids.remove(productId) : _ids.add(productId);
      items = null;
      notifyListeners();
      rethrow;
    }
  }

  void reset() {
    _ids.clear();
    items = null;
    error = null;
    notifyListeners();
  }
}
