import 'dart:async';

import 'package:flutter/foundation.dart';

import '../api/api_client.dart';

/// The unread badge. The inbox itself loads on its own screen; this only keeps the count fresh.
class NotificationsController extends ChangeNotifier {
  NotificationsController(this.api);

  final ApiClient api;

  int unread = 0;
  Timer? _timer;

  void start() {
    _timer?.cancel();
    refresh();
    _timer = Timer.periodic(const Duration(minutes: 1), (_) => refresh());
  }

  void stop() {
    _timer?.cancel();
    _timer = null;
    unread = 0;
    notifyListeners();
  }

  Future<void> refresh() async {
    try {
      final response = await api.get('/me/notifications', query: {'unread': 1}) as Map<String, dynamic>;
      final count = (response['meta'] as Map<String, dynamic>?)?['unread_count'] as int? ?? 0;
      if (count != unread) {
        unread = count;
        notifyListeners();
      }
    } catch (_) {
      // Keep the last count; the next tick tries again.
    }
  }

  void markedRead([int count = 1]) {
    unread = (unread - count).clamp(0, 1 << 30);
    notifyListeners();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }
}
