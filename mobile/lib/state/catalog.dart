import 'package:flutter/foundation.dart' hide Category;

import '../api/api_client.dart';
import '../api/models.dart';

/// Reference data that rarely changes: the category tree and the unit rules.
class CatalogController extends ChangeNotifier {
  CatalogController(this.api);

  final ApiClient api;

  List<Category> categories = const [];
  Map<String, Unit> units = const {};
  final Map<String, String> _rootSlugs = {};
  bool loading = false;
  Object? error;

  Future<void> load() async {
    if (loading) return;
    loading = true;
    error = null;
    notifyListeners();
    try {
      final responses = await Future.wait([api.get('/categories'), api.get('/units')]);
      categories = ((responses[0] as Map<String, dynamic>)['data'] as List)
          .cast<Map<String, dynamic>>()
          .map(Category.fromJson)
          .toList();
      units = {
        for (final unit in ((responses[1] as Map<String, dynamic>)['data'] as List).cast<Map<String, dynamic>>())
          unit['code'] as String: Unit.fromJson(unit),
      };
      _rootSlugs.clear();
      for (final category in categories) {
        _rootSlugs[category.slug] = category.slug;
        for (final child in category.children) {
          _rootSlugs[child.slug] = category.slug;
        }
      }
    } catch (e) {
      error = e;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  /// Maps any category slug to its top-level one, so a "tomatoes" listing borrows the vegetables icon.
  String rootOf(String slug) => _rootSlugs[slug] ?? slug;

  String? nameOf(String slug) {
    for (final category in categories) {
      if (category.slug == slug) return category.name;
      for (final child in category.children) {
        if (child.slug == slug) return child.name;
      }
    }
    return null;
  }

  /// Unknown until the unit rules load; the server checks again either way.
  bool? allowsFraction(String unitCode) => units[unitCode]?.allowsFraction;
}
