import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../state/cart.dart';
import '../state/shell.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'account_screen.dart';
import 'basket_screen.dart';
import 'browse_screen.dart';
import 'favourites_screen.dart';
import 'home_screen.dart';

/// The five tabs. Each keeps its scroll position and state while another is showing.
class AppShell extends StatelessWidget {
  const AppShell({super.key});

  @override
  Widget build(BuildContext context) {
    final shell = context.watch<ShellController>();

    return PopScope(
      // Back on any other tab returns to Home before leaving the app.
      canPop: shell.tab == AppTab.home,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) shell.go(AppTab.home);
      },
      child: Scaffold(
        body: IndexedStack(
          index: shell.tab.index,
          children: const [HomeScreen(), BrowseScreen(), FavouritesScreen(), BasketScreen(), AccountScreen()],
        ),
        bottomNavigationBar: const _BottomNav(),
      ),
    );
  }
}

class _BottomNav extends StatelessWidget {
  const _BottomNav();

  static const _items = [
    (AppTab.home, LucideIcons.house, 'Home'),
    (AppTab.browse, LucideIcons.layoutGrid, 'Browse'),
    (AppTab.favourites, LucideIcons.heart, 'Favourites'),
    (AppTab.basket, LucideIcons.shoppingBag, 'Basket'),
    (AppTab.account, LucideIcons.user, 'Account'),
  ];

  @override
  Widget build(BuildContext context) {
    final shell = context.watch<ShellController>();
    final basketCount = context.select<CartController, int>((cart) => cart.count);

    return DecoratedBox(
      decoration: const BoxDecoration(color: Colors.white, border: Border(top: BorderSide(color: Brand.border))),
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: 66,
          child: Row(
            children: [
              for (final (tab, icon, label) in _items)
                Expanded(
                  child: _NavItem(
                    icon: icon,
                    label: label,
                    active: shell.tab == tab,
                    count: tab == AppTab.basket ? basketCount : 0,
                    onTap: () => shell.go(tab),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({required this.icon, required this.label, required this.active, required this.count, required this.onTap});

  final IconData icon;
  final String label;
  final bool active;
  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = active ? Brand.green : Brand.mutedText;
    return Semantics(
      selected: active,
      button: true,
      label: label,
      excludeSemantics: true,
      child: InkResponse(
        onTap: onTap,
        highlightShape: BoxShape.rectangle,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Stack(
              clipBehavior: Clip.none,
              children: [
                Icon(icon, size: 21, color: color),
                if (count > 0) Positioned(top: -7, right: -12, child: CountBadge(count: count)),
              ],
            ),
            const SizedBox(height: 4),
            Text(label, style: TextStyle(fontSize: 10.5, color: color, fontWeight: active ? FontWeight.w800 : FontWeight.w500)),
            const SizedBox(height: 3),
            Container(width: 4, height: 4, decoration: BoxDecoration(color: active ? Brand.green : Colors.transparent, shape: BoxShape.circle)),
          ],
        ),
      ),
    );
  }
}
