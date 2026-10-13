import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../api/api_client.dart';
import '../api/models.dart';

/// Who is signed in. The Sanctum token is kept in the phone's secure storage (Android Keystore).
class Session extends ChangeNotifier {
  Session(this.api, {FlutterSecureStorage? storage}) : _storage = storage ?? const FlutterSecureStorage();

  static const _tokenKey = 'mundalinq.token';

  final ApiClient api;
  final FlutterSecureStorage _storage;

  User? user;
  bool restoring = true;

  bool get signedIn => api.token != null;

  /// Shown in the user's list of signed-in devices.
  String get _deviceName => 'MundaLinq app on ${defaultTargetPlatform == TargetPlatform.iOS ? 'iPhone' : 'Android'}';

  Future<void> restore() async {
    api.onUnauthorized = () => _end();

    try {
      api.token = await _storage.read(key: _tokenKey);
    } catch (_) {
      api.token = null;
    }

    if (api.token != null) {
      try {
        user = User.fromJson((await api.get('/me'))['data'] as Map<String, dynamic>);
      } on ApiException catch (error) {
        // Offline: keep the token and try again on the next request. A 401 has already ended the session.
        if (error.status == 401) user = null;
      }
    }

    restoring = false;
    notifyListeners();
  }

  Future<void> signIn(String login, String password) async {
    final response = await api.post('/auth/login', {'login': login, 'password': password, 'device_name': _deviceName});
    await _start(response as Map<String, dynamic>);
  }

  Future<void> register({
    required String name,
    required String phone,
    required String email,
    required String password,
    required bool business,
  }) async {
    final response = await api.post('/auth/register', {
      'name': name,
      'phone': phone,
      'email': email,
      'password': password,
      'password_confirmation': password,
      'role': business ? 'business_buyer' : 'buyer',
      'device_name': _deviceName,
    });
    await _start(response as Map<String, dynamic>);
  }

  Future<void> verifyPhone(String code) async {
    final response = await api.post('/auth/phone/verify', {'code': code});
    user = User.fromJson((response as Map<String, dynamic>)['data'] as Map<String, dynamic>);
    notifyListeners();
  }

  Future<void> sendCode() => api.post('/auth/phone/send-code');

  Future<void> signOut() async {
    try {
      await api.post('/auth/logout');
    } on ApiException {
      // Sign out on this phone even if the server can't be reached.
    }
    await _end();
  }

  Future<void> _start(Map<String, dynamic> response) async {
    final token = response['token'] as String?;
    if (token == null) {
      // Staff accounts confirm sign-in with a second code; they use the web dashboard instead.
      throw ApiException(403, 'Staff accounts sign in on the MundaLinq dashboard, not in the app.');
    }

    api.token = token;
    user = User.fromJson(response['user'] as Map<String, dynamic>);
    try {
      await _storage.write(key: _tokenKey, value: token);
    } catch (_) {
      // Storage unavailable: the session still works until the app closes.
    }
    notifyListeners();
  }

  Future<void> _end() async {
    api.token = null;
    user = null;
    try {
      await _storage.delete(key: _tokenKey);
    } catch (_) {
      // Nothing stored.
    }
    notifyListeners();
  }
}
