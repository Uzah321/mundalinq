import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../api/models.dart';

enum Sort {
  newest('newest', 'Newest first'),
  freshest('freshest', 'Freshest harvest'),
  nearest('nearest', 'Nearest to me'),
  priceAsc('price_asc', 'Price: low to high'),
  priceDesc('price_desc', 'Price: high to low');

  const Sort(this.value, this.label);

  final String value;
  final String label;
}

enum Fulfilment {
  delivery('delivery', 'Delivers to me'),
  pickup('pickup', 'Farm pickup');

  const Fulfilment(this.value, this.label);

  final String value;
  final String label;
}

/// What the buyer is looking for; the shopping area is added separately.
@immutable
class SearchFilters {
  const SearchFilters({this.query = '', this.category, this.sort = Sort.newest, this.fulfilment});

  final String query;
  final String? category;
  final Sort sort;
  final Fulfilment? fulfilment;

  /// Filters beyond the search box and category, shown as a dot on the filter button.
  bool get refined => sort != Sort.newest || fulfilment != null;

  SearchFilters copyWith({String? query, String? Function()? category, Sort? sort, Fulfilment? Function()? fulfilment}) =>
      SearchFilters(
        query: query ?? this.query,
        category: category != null ? category() : this.category,
        sort: sort ?? this.sort,
        fulfilment: fulfilment != null ? fulfilment() : this.fulfilment,
      );

  @override
  bool operator ==(Object other) =>
      other is SearchFilters &&
      other.query == query &&
      other.category == category &&
      other.sort == sort &&
      other.fulfilment == fulfilment;

  @override
  int get hashCode => Object.hash(query, category, sort, fulfilment);
}

/// One paged list of listings from GET /products. Pages load as the buyer scrolls.
class ProductSearch extends ChangeNotifier {
  ProductSearch(this.api, {this.perPage = 20});

  final ApiClient api;
  final int perPage;

  Map<String, Object?>? _query;
  List<ProductCard> items = const [];
  int total = 0;
  int _page = 0;
  bool hasMore = true;
  bool loading = false;
  Object? error;
  int _generation = 0;

  bool get initialLoading => loading && items.isEmpty;

  /// Starts over with new filters; does nothing if they haven't changed.
  Future<void> search(Map<String, Object?> query) {
    if (_query != null && mapEquals(query, _query)) return Future.value();
    _query = query;
    return refresh();
  }

  Future<void> refresh() {
    if (_query == null) return Future.value();
    _generation++;
    items = const [];
    total = 0;
    _page = 0;
    hasMore = true;
    loading = false;
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
      final page = Paged.fromJson(
        await api.get('/products', query: {...?_query, 'page': _page + 1, 'per_page': perPage}) as Map<String, dynamic>,
        ProductCard.fromJson,
      );
      if (generation != _generation) return;
      items = [...items, ...page.items];
      total = page.total;
      _page = page.currentPage;
      hasMore = page.hasMore;
    } catch (e) {
      if (generation == _generation) error = e;
    } finally {
      if (generation == _generation) {
        loading = false;
        notifyListeners();
      }
    }
  }
}

/// Builds the GET /products query from the search, the shopping area and the sort.
Map<String, Object?> productQuery(SearchFilters filters, Map<String, Object?> areaFilters) => {
      'q': filters.query.trim(),
      'category': filters.category,
      // "Nearest" needs a location; without one the server would reject it.
      'sort': filters.sort == Sort.nearest && !areaFilters.containsKey('lat') ? Sort.newest.value : filters.sort.value,
      'fulfilment': filters.fulfilment?.value,
      ...areaFilters,
    };

/// The Browse tab's filters, shared so the Home tab can open Browse on a category or a search.
class BrowseController extends ChangeNotifier {
  SearchFilters filters = const SearchFilters();
  int _focusRequests = 0;

  /// Increases each time Home asks Browse to focus its search box.
  int get focusRequests => _focusRequests;

  void update(SearchFilters next) {
    if (next == filters) return;
    filters = next;
    notifyListeners();
  }

  void focusSearch() {
    _focusRequests++;
    notifyListeners();
  }
}
