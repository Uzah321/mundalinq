import 'package:flutter_test/flutter_test.dart';
import 'package:mundalinq/api/server.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  test('typed addresses become API addresses', () {
    expect(Server.normalize('192.168.1.20:8000'), 'http://192.168.1.20:8000/api/v1');
    expect(Server.normalize(' http://192.168.1.20:8000/ '), 'http://192.168.1.20:8000/api/v1');
    expect(Server.normalize('https://api.mundalinq.co.zw/api/v1'), 'https://api.mundalinq.co.zw/api/v1');
    expect(Server.normalize(''), isNull);
    expect(Server.normalize('ftp://example.com'), isNull);
  });

  test('uploaded photos are fetched from the address the app uses', () async {
    SharedPreferences.setMockInitialValues({});
    await Server.use('http://10.0.0.5:8000/api/v1');
    expect(Server.media('http://192.168.1.216:8000/storage/products/a/b_thumb.webp'), 'http://10.0.0.5:8000/storage/products/a/b_thumb.webp');
    expect(Server.media('https://cdn.example.com/products/a.webp'), 'https://cdn.example.com/products/a.webp');
  });
}
