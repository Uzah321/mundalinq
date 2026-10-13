import 'package:flutter/foundation.dart';

enum AppTab { home, browse, favourites, basket, account }

/// Which bottom tab is showing, so any screen (or a snackbar's "View basket") can switch tabs.
class ShellController extends ChangeNotifier {
  AppTab tab = AppTab.home;

  void go(AppTab next) {
    if (next == tab) return;
    tab = next;
    notifyListeners();
  }
}

/// Listings being quick-added from a card, so only that card shows a spinner.
class QuickAddController extends ChangeNotifier {
  final Set<String> _adding = {};

  bool isAdding(String productId) => _adding.contains(productId);

  void start(String productId) {
    _adding.add(productId);
    notifyListeners();
  }

  void finish(String productId) {
    _adding.remove(productId);
    notifyListeners();
  }
}
