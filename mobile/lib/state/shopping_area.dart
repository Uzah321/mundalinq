import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Mirrors config('mundalinq.countries.ZW.provinces'); the API rejects any other value.
const provinces = [
  'Bulawayo',
  'Harare',
  'Manicaland',
  'Mashonaland Central',
  'Mashonaland East',
  'Mashonaland West',
  'Masvingo',
  'Matabeleland North',
  'Matabeleland South',
  'Midlands',
];

const radiusOptions = [25, 50, 100];

/// Where the buyer is shopping from: a province, or farms within a radius of their phone's location.
class ShoppingArea {
  const ShoppingArea.province(String this.province)
      : latitude = null,
        longitude = null,
        radiusKm = null;

  const ShoppingArea.nearby({required double this.latitude, required double this.longitude, required int this.radiusKm})
      : province = null;

  final String? province;
  final double? latitude;
  final double? longitude;
  final int? radiusKm;

  bool get isNearby => latitude != null;

  String get label => isNearby ? 'Near me · $radiusKm km' : province!;

  Map<String, Object?> get filters => isNearby
      ? {'lat': latitude!.toStringAsFixed(5), 'lng': longitude!.toStringAsFixed(5), 'radius_km': radiusKm}
      : {'province': province};

  Map<String, Object?> toJson() => isNearby
      ? {'lat': latitude, 'lng': longitude, 'radius_km': radiusKm}
      : {'province': province};

  static ShoppingArea? fromJson(Map<String, dynamic> json) {
    if (json['province'] is String) return ShoppingArea.province(json['province'] as String);
    if (json['lat'] is num && json['lng'] is num && json['radius_km'] is int) {
      return ShoppingArea.nearby(
        latitude: (json['lat'] as num).toDouble(),
        longitude: (json['lng'] as num).toDouble(),
        radiusKm: json['radius_km'] as int,
      );
    }
    return null;
  }

  @override
  bool operator ==(Object other) =>
      other is ShoppingArea &&
      other.province == province &&
      other.latitude == latitude &&
      other.longitude == longitude &&
      other.radiusKm == radiusKm;

  @override
  int get hashCode => Object.hash(province, latitude, longitude, radiusKm);
}

class ShoppingAreaController extends ChangeNotifier {
  static const _key = 'mundalinq.area';

  ShoppingArea? area;

  String get label => area?.label ?? 'All of Zimbabwe';

  Future<void> restore() async {
    try {
      final stored = (await SharedPreferences.getInstance()).getString(_key);
      if (stored != null) area = ShoppingArea.fromJson(jsonDecode(stored) as Map<String, dynamic>);
    } catch (_) {
      area = null;
    }
    notifyListeners();
  }

  Future<void> choose(ShoppingArea? next) async {
    area = next;
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    if (next == null) {
      await prefs.remove(_key);
    } else {
      await prefs.setString(_key, jsonEncode(next.toJson()));
    }
  }
}
