import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../state/farm.dart';
import '../../state/notifications.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../messages/conversations_screen.dart';
import '../notifications_screen.dart';
import 'farm_home_screen.dart';
import 'farm_orders_screen.dart';
import 'listings_screen.dart';

enum FarmTab { orders, listings, messages, farm }

/// Which farm tab is showing, so screens (and notifications) can switch tabs.
class FarmShellController extends ChangeNotifier {
  FarmTab tab = FarmTab.orders;

  void go(FarmTab next) {
    if (next == tab) return;
    tab = next;
    notifyListeners();
  }
}

/// The farm side of the app: orders, listings, messages with buyers, and the farm itself.
class FarmShell extends StatelessWidget {
  const FarmShell({super.key});

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(create: (_) => FarmShellController(), child: const _FarmShellBody());
  }
}

class _FarmShellBody extends StatelessWidget {
  const _FarmShellBody();

  @override
  Widget build(BuildContext context) {
    final shell = context.watch<FarmShellController>();
    final farmId = context.select<FarmController, String?>((farms) => farms.farm?.id);

    return PopScope(
      canPop: shell.tab == FarmTab.orders,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) shell.go(FarmTab.orders);
      },
      child: Scaffold(
        // Rebuilt from scratch when the farmer switches farm, so nothing from the old farm lingers.
        body: IndexedStack(
          key: ValueKey(farmId),
          index: shell.tab.index,
          children: const [FarmOrdersScreen(), ListingsScreen(), ConversationsScreen(asFarm: true), FarmHomeScreen()],
        ),
        bottomNavigationBar: const _FarmNav(),
      ),
    );
  }
}

class _FarmNav extends StatelessWidget {
  const _FarmNav();

  static const _items = [
    (FarmTab.orders, LucideIcons.clipboardList, 'Orders'),
    (FarmTab.listings, LucideIcons.package, 'Listings'),
    (FarmTab.messages, LucideIcons.messagesSquare, 'Messages'),
    (FarmTab.farm, LucideIcons.sprout, 'Farm'),
  ];

  @override
  Widget build(BuildContext context) {
    final shell = context.watch<FarmShellController>();

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
                  child: Semantics(
                    selected: shell.tab == tab,
                    button: true,
                    label: label,
                    excludeSemantics: true,
                    child: InkResponse(
                      onTap: () => shell.go(tab),
                      highlightShape: BoxShape.rectangle,
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(icon, size: 21, color: shell.tab == tab ? Brand.green : Brand.mutedText),
                          const SizedBox(height: 4),
                          Text(
                            label,
                            style: TextStyle(
                              fontSize: 10.5,
                              color: shell.tab == tab ? Brand.green : Brand.mutedText,
                              fontWeight: shell.tab == tab ? FontWeight.w800 : FontWeight.w500,
                            ),
                          ),
                          const SizedBox(height: 3),
                          Container(
                            width: 4,
                            height: 4,
                            decoration: BoxDecoration(color: shell.tab == tab ? Brand.green : Colors.transparent, shape: BoxShape.circle),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The farm's name as the app bar title; tapping it switches farm when the farmer has several.
class FarmTitle extends StatelessWidget {
  const FarmTitle({super.key, required this.subtitle});

  final String subtitle;

  @override
  Widget build(BuildContext context) {
    final farms = context.watch<FarmController>();
    final farm = farms.farm;

    final title = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(subtitle.toUpperCase(), style: Brand.eyebrow()),
        const SizedBox(height: 2),
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Flexible(child: Text(farm?.name ?? 'Your farm', overflow: TextOverflow.ellipsis, style: Brand.display(size: 17))),
            if (farms.farms.length > 1) const Icon(LucideIcons.chevronDown, size: 18),
          ],
        ),
      ],
    );

    if (farms.farms.length < 2) return title;
    return InkWell(
      onTap: () => showModalBottomSheet<void>(
        context: context,
        builder: (sheetContext) => SafeArea(
          child: ListView(
            shrinkWrap: true,
            children: [
              Padding(padding: const EdgeInsets.fromLTRB(20, 0, 20, 8), child: Text('Your farms', style: Brand.display(size: 17))),
              for (final option in farms.farms)
                ListTile(
                  leading: const Icon(LucideIcons.sprout, color: Brand.green),
                  title: Text(option.name),
                  subtitle: Text(option.location?.district ?? '', style: const TextStyle(color: Brand.mutedText)),
                  trailing: option.id == farm?.id ? const Icon(LucideIcons.check, color: Brand.green) : null,
                  onTap: () {
                    farms.choose(option);
                    Navigator.pop(sheetContext);
                  },
                ),
            ],
          ),
        ),
      ),
      child: title,
    );
  }
}

/// The bell in farm and market app bars, with the unread count.
class NotificationBell extends StatelessWidget {
  const NotificationBell({super.key});

  @override
  Widget build(BuildContext context) {
    final unread = context.select<NotificationsController, int>((notifications) => notifications.unread);
    return Padding(
      padding: const EdgeInsets.only(right: 12),
      child: BadgedIconButton(
        icon: LucideIcons.bell,
        tooltip: 'Notifications',
        count: unread,
        onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const NotificationsScreen())),
      ),
    );
  }
}
