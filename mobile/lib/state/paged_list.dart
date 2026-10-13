import 'package:flutter/foundation.dart';

import '../api/models.dart';

/// A server list that loads a page at a time as the user scrolls. [fetch] gets a page number and returns it.
class PagedList<T> extends ChangeNotifier {
  PagedList(this._fetch);

  Future<Paged<T>> Function(int page) _fetch;

  List<T> items = const [];
  int total = 0;
  int _page = 0;
  bool hasMore = true;
  bool loading = false;
  bool loaded = false;
  Object? error;
  int _generation = 0;

  bool get initialLoading => loading && items.isEmpty;

  /// Swaps the source (e.g. a new filter) and starts over.
  Future<void> reset(Future<Paged<T>> Function(int page) fetch) {
    _fetch = fetch;
    return refresh();
  }

  Future<void> refresh() {
    _generation++;
    items = const [];
    total = 0;
    _page = 0;
    hasMore = true;
    loading = false;
    loaded = false;
    error = null;
    return loadMore();
  }

  Future<void> loadMore() async {
    if (loading || !hasMore) return;
    final generation = _generation;
    loading = true;
    error = null;
    notifyListeners();
    try {
      final page = await _fetch(_page + 1);
      if (generation != _generation) return;
      items = [...items, ...page.items];
      total = page.total;
      _page = page.currentPage;
      hasMore = page.hasMore;
      loaded = true;
    } catch (e) {
      if (generation == _generation) error = e;
    } finally {
      if (generation == _generation) {
        loading = false;
        notifyListeners();
      }
    }
  }

  /// Replaces one item in place (after an action on it), matched by [same].
  void update(T item, bool Function(T a, T b) same) {
    items = [for (final existing in items) same(existing, item) ? item : existing];
    notifyListeners();
  }
}
