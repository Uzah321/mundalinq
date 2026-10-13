import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../api/farm_models.dart';

/// Whether the app is showing the market (buying) or one of the user's farms (selling), and which farm.
/// Both are remembered on the phone, so a farmer reopens the app where they left off.
class FarmController extends ChangeNotifier {
  FarmController(this.api);

  static const _sellingKey = 'mundalinq.selling';
  static const _farmKey = 'mundalinq.farm';

  final ApiClient api;

  List<Farm> farms = const [];
  bool loading = false;
  bool loaded = false;
  Object? error;
  bool _selling = false;
  String? _farmId;

  /// True when the farm screens are showing and the user has a farm to show.
  bool get selling => _selling && farm != null;

  /// Whether the farm screens were open when the app last closed (known before the farms load).
  static bool wasSelling = false;

  Farm? get farm {
    for (final farm in farms) {
      if (farm.id == _farmId) return farm;
    }
    return farms.firstOrNull;
  }

  Future<void> restore() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      _selling = prefs.getBool(_sellingKey) ?? false;
      wasSelling = _selling;
      _farmId = prefs.getString(_farmKey);
    } catch (_) {
      // Start in the market.
    }
  }

  Future<void> load() async {
    loading = true;
    error = null;
    notifyListeners();
    try {
      final response = await api.get('/me/farms') as Map<String, dynamic>;
      farms = (response['data'] as List).cast<Map<String, dynamic>>().map(Farm.fromJson).toList();
      loaded = true;
    } catch (e) {
      error = e;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  /// Opens the farm screens, on [farmId] if given.
  Future<void> startSelling([String? farmId]) async {
    _selling = true;
    if (farmId != null) _farmId = farmId;
    notifyListeners();
    await _save();
  }

  Future<void> stopSelling() async {
    _selling = false;
    wasSelling = false;
    notifyListeners();
    await _save();
  }

  Future<void> choose(Farm farm) async {
    _farmId = farm.id;
    notifyListeners();
    await _save();
  }

  /// After creating or editing a farm: reload the list (it carries the user's role) and show that farm.
  Future<void> refreshWith(String farmId) async {
    _farmId = farmId;
    await load();
    await _save();
  }

  Future<void> reset() async {
    farms = const [];
    loaded = false;
    _selling = false;
    wasSelling = false;
    notifyListeners();
    await _save();
  }

  Future<void> _save() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setBool(_sellingKey, _selling);
      if (_farmId != null) await prefs.setString(_farmKey, _farmId!);
    } catch (_) {
      // Not remembered; the app opens in the market next time.
    }
  }
}
