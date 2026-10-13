import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import 'server.dart';

/// A failed API call, with the server's message and any per-field validation errors.
class ApiException implements Exception {
  ApiException(this.status, this.message, [this.fieldErrors = const {}]);

  final int status;
  final String message;
  final Map<String, List<String>> fieldErrors;

  String? field(String name) => fieldErrors[name]?.first;

  /// The most specific message: the first field error, else the summary.
  String get detail => fieldErrors.values.firstOrNull?.firstOrNull ?? message;

  @override
  String toString() => detail;
}

String errorMessage(Object error) =>
    error is ApiException ? error.detail : 'Something went wrong. Please try again.';

/// Thin client for the MundaLinq JSON API (/api/v1). Signed-in requests carry a Sanctum bearer token.
class ApiClient {
  /// [baseUrl] fixes the server (for tests); otherwise each request goes to [Server.baseUrl].
  ApiClient({http.Client? client, String? baseUrl})
      : _client = client ?? http.Client(),
        _fixedBaseUrl = baseUrl?.replaceFirst(RegExp(r'/$'), '');

  final http.Client _client;
  final String? _fixedBaseUrl;

  String? token;

  /// Called when the API rejects the token (expired or revoked), so the app can sign out locally.
  void Function()? onUnauthorized;

  Future<dynamic> get(String path, {Map<String, Object?>? query}) => _send('GET', path, query: query);

  Future<dynamic> post(String path, [Object? body]) => _send('POST', path, body: body);

  Future<dynamic> put(String path, [Object? body]) => _send('PUT', path, body: body);

  Future<dynamic> patch(String path, [Object? body]) => _send('PATCH', path, body: body);

  Future<dynamic> delete(String path) => _send('DELETE', path);

  Future<dynamic> _send(String method, String path, {Map<String, Object?>? query, Object? body}) async {
    final params = <String, String>{
      for (final entry in (query ?? const {}).entries)
        if (entry.value != null && entry.value != '') entry.key: '${entry.value}',
    };
    final uri = Uri.parse('${_fixedBaseUrl ?? Server.baseUrl}$path').replace(queryParameters: params.isEmpty ? null : params);

    final request = http.Request(method, uri)..headers['Accept'] = 'application/json';
    if (token != null) request.headers['Authorization'] = 'Bearer $token';
    if (body != null) {
      request.headers['Content-Type'] = 'application/json';
      request.body = jsonEncode(body);
    }

    final http.Response response;
    try {
      response = await http.Response.fromStream(await _client.send(request).timeout(const Duration(seconds: 20)));
    } on SocketException {
      throw ApiException(0, "We couldn't reach MundaLinq. Check your connection and try again.");
    } on TimeoutException {
      throw ApiException(0, 'MundaLinq is taking too long to answer. Please try again.');
    } on http.ClientException {
      throw ApiException(0, "We couldn't reach MundaLinq. Check your connection and try again.");
    }

    if (response.statusCode == 204) return null;

    dynamic payload;
    try {
      payload = response.body.isEmpty ? null : jsonDecode(utf8.decode(response.bodyBytes));
    } on FormatException {
      payload = null;
    }

    if (response.statusCode >= 400) {
      if (response.statusCode == 401 && token != null) onUnauthorized?.call();

      final map = payload is Map<String, dynamic> ? payload : const <String, dynamic>{};
      final errors = <String, List<String>>{};
      final rawErrors = map['errors'];
      if (rawErrors is Map) {
        rawErrors.forEach((key, value) {
          if (value is List) errors['$key'] = value.map((item) => '$item').toList();
        });
      }

      throw ApiException(response.statusCode, (map['message'] as String?) ?? _fallbackMessage(response.statusCode), errors);
    }

    return payload;
  }

  String _fallbackMessage(int status) {
    if (status == 429) return 'That was a lot of tries. Please wait a minute and try again.';
    if (status >= 500) return 'Something went wrong on our side. Please try again shortly.';
    return "That didn't work. Please try again.";
  }
}
