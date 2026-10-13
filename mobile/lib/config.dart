/// Build-time settings, passed with --dart-define (see README.md).
class AppConfig {
  /// The Laravel API. 10.0.2.2 is the host computer as seen from the Android emulator;
  /// builds for real phones pass the computer's Wi-Fi address or the server's URL.
  static const apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  /// Pilot builds against the sandbox payment provider show its test numbers on the payment screen.
  static const sandboxPayments = bool.fromEnvironment('SANDBOX_PAYMENTS');
}
