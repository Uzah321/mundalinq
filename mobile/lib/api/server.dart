import 'package:shared_preferences/shared_preferences.dart';

import '../config.dart';

/// Where the API is. It starts at the build's address; testers can point the app elsewhere from the Account tab
/// (a laptop's Wi-Fi address changes from network to network), and the choice is remembered on the phone.
class Server {
  static const _key = 'mundalinq.server';
  static final String buildDefault = _trim(AppConfig.apiBaseUrl);
  static String _baseUrl = buildDefault;

  /// e.g. http://192.168.1.20:8000/api/v1
  static String get baseUrl => _baseUrl;

  /// e.g. http://192.168.1.20:8000
  static String get origin {
    final uri = Uri.parse(_baseUrl);
    return '${uri.scheme}://${uri.authority}';
  }

  static Future<void> restore() async {
    try {
      final stored = (await SharedPreferences.getInstance()).getString(_key);
      if (stored != null && normalize(stored) != null) _baseUrl = stored;
    } catch (_) {
      // Keep the build's address.
    }
  }

  static Future<void> use(String baseUrl) async {
    _baseUrl = baseUrl;
    final prefs = await SharedPreferences.getInstance();
    if (baseUrl == buildDefault) {
      await prefs.remove(_key);
    } else {
      await prefs.setString(_key, baseUrl);
    }
  }

  /// "192.168.1.20:8000" or "http://192.168.1.20:8000" become "http://192.168.1.20:8000/api/v1"; null if unusable.
  static String? normalize(String input) {
    var text = input.trim();
    if (text.isEmpty) return null;
    if (!text.contains('://')) text = 'http://$text';
    final uri = Uri.tryParse(text);
    if (uri == null || uri.host.isEmpty || (uri.scheme != 'http' && uri.scheme != 'https')) return null;
    final path = _trim(uri.path);
    return '${uri.scheme}://${uri.authority}${path.isEmpty ? '/api/v1' : path}';
  }

  /// Photo links carry the server's own idea of its address (APP_URL), which may not be the one this phone reaches
  /// it on. Uploaded files (under /storage/) are fetched from the address the app is using instead.
  static String media(String url) {
    final uri = Uri.tryParse(url);
    if (uri == null || !uri.path.startsWith('/storage/')) return url;
    return '$origin${uri.path}${uri.hasQuery ? '?${uri.query}' : ''}';
  }

  static String _trim(String url) => url.trim().replaceFirst(RegExp(r'/+$'), '');
}
