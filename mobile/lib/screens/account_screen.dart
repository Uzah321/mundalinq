import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/server.dart';
import '../state/farm.dart';
import '../state/session.dart';
import '../state/shopping_area.dart';
import '../theme.dart';
import '../util/format.dart';
import '../widgets/common.dart';
import 'account_settings.dart';
import 'auth_screen.dart';
import 'farm/farm_form_screen.dart';
import 'location_sheet.dart';
import 'messages/conversations_screen.dart';
import 'orders_screen.dart';
import 'quotes/quotes_screen.dart';
import 'server_sheet.dart';

class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final farms = context.watch<FarmController>();
    final user = session.user;

    void push(Widget screen) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));

    return Scaffold(
      appBar: AppBar(title: const Text('Account')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          if (!session.signedIn)
            EmptyState(
              icon: LucideIcons.user,
              title: 'Welcome to MundaLinq',
              message: 'Sign in to keep a basket, save favourites and order from farms near you.',
              actions: [
                FilledButton(
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute(fullscreenDialog: true, builder: (_) => const AuthScreen())),
                  child: const Text('Sign in'),
                ),
                OutlinedButton(
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute(fullscreenDialog: true, builder: (_) => const AuthScreen(startWithRegister: true)),
                  ),
                  child: const Text('Create account'),
                ),
              ],
            )
          else if (user == null)
            const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()))
          else ...[
            const SizedBox(height: 8),
            Center(
              child: Container(
                width: 68,
                height: 68,
                alignment: Alignment.center,
                decoration: const BoxDecoration(color: Brand.mint, shape: BoxShape.circle),
                child: Text(initials(user.name), style: Brand.display(size: 22, weight: FontWeight.w700, color: Brand.green)),
              ),
            ),
            const SizedBox(height: 14),
            Text(user.name, textAlign: TextAlign.center, style: Brand.display(size: 19)),
            const SizedBox(height: 4),
            Text([formatPhone(user.phone), ?user.email].join(' · '), textAlign: TextAlign.center, style: const TextStyle(color: Brand.mutedText)),
            const SizedBox(height: 14),
            Center(
              child: user.phoneVerified
                  ? const Tag(label: 'Phone verified', icon: LucideIcons.circleCheck)
                  : FilledButton.icon(
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (context) => Scaffold(
                            appBar: AppBar(title: const Text('Confirm your number')),
                            body: VerifyPhoneView(codeJustSent: false, onDone: () => Navigator.pop(context)),
                          ),
                        ),
                      ),
                      icon: const Icon(LucideIcons.phone, size: 17),
                      label: const Text('Verify phone'),
                    ),
            ),
            const SizedBox(height: 20),
            _Tile(icon: LucideIcons.receipt, title: 'My orders', subtitle: 'Follow, pay for and confirm your orders', onTap: () => push(const OrdersScreen())),
            _Tile(
              icon: LucideIcons.clipboardList,
              title: 'Requests for quotes',
              subtitle: 'Ask farms for prices on large or regular orders',
              onTap: () => push(const QuotesScreen()),
            ),
            _Tile(icon: LucideIcons.messagesSquare, title: 'Messages', subtitle: 'Your conversations with farms', onTap: () => push(const ConversationsScreen())),
            _Tile(icon: LucideIcons.house, title: 'Delivery addresses', subtitle: 'Where farms deliver to you', onTap: () => push(const AddressesScreen())),
          ],
          _Tile(
            icon: LucideIcons.mapPin,
            title: 'Shopping area',
            subtitle: context.watch<ShoppingAreaController>().label,
            onTap: () => showLocationSheet(context),
          ),
          if (session.signedIn && user != null) ...[
            const Divider(height: 28),
            if (farms.farms.isNotEmpty)
              _Tile(
                icon: LucideIcons.sprout,
                title: 'Switch to selling',
                subtitle: farms.farm?.name ?? 'Your farm',
                highlight: true,
                onTap: () => farms.startSelling(),
              )
            else
              _Tile(
                icon: LucideIcons.sprout,
                title: 'Sell on MundaLinq',
                subtitle: 'Farmers: list your produce and get orders from buyers near you',
                highlight: true,
                onTap: () => push(const FarmFormScreen()),
              ),
            const AccountSettingsTiles(),
            const Divider(height: 28),
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(LucideIcons.logOut, color: Brand.danger),
              title: const Text('Sign out', style: TextStyle(fontWeight: FontWeight.w600, color: Brand.danger)),
              onTap: () async {
                await context.read<Session>().signOut();
                if (context.mounted) showMessage(context, 'You are signed out');
              },
            ),
          ] else
            const _ServerTile(),
          const SizedBox(height: 12),
          const Text('MundaLinq 0.2', textAlign: TextAlign.center, style: TextStyle(fontSize: 11, color: Brand.mutedText)),
        ],
      ),
    );
  }
}

/// Settings shared by the market's Account tab and the farm's Farm tab.
class AccountSettingsTiles extends StatelessWidget {
  const AccountSettingsTiles({super.key});

  @override
  Widget build(BuildContext context) {
    void push(Widget screen) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    return Column(
      children: [
        _Tile(icon: LucideIcons.userPen, title: 'Your details', subtitle: 'Name and email', onTap: () => push(const ProfileScreen())),
        _Tile(icon: LucideIcons.bell, title: 'SMS and WhatsApp', subtitle: 'How updates reach you outside the app', onTap: () => push(const NotificationSettingsScreen())),
        _Tile(icon: LucideIcons.smartphone, title: 'Signed-in devices', subtitle: 'Sign out phones you no longer use', onTap: () => push(const DevicesScreen())),
        const _ServerTile(),
      ],
    );
  }
}

class _ServerTile extends StatefulWidget {
  const _ServerTile();

  @override
  State<_ServerTile> createState() => _ServerTileState();
}

class _ServerTileState extends State<_ServerTile> {
  @override
  Widget build(BuildContext context) {
    return _Tile(
      icon: LucideIcons.server,
      title: 'Server address',
      subtitle: Server.origin,
      muted: true,
      onTap: () async {
        await showServerSheet(context);
        // The address lives outside the widget tree, so redraw after a change.
        if (mounted) setState(() {});
      },
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({required this.icon, required this.title, required this.subtitle, required this.onTap, this.highlight = false, this.muted = false});

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final bool highlight;
  final bool muted;

  @override
  Widget build(BuildContext context) {
    final tile = ListTile(
      contentPadding: highlight ? const EdgeInsets.symmetric(horizontal: 12) : EdgeInsets.zero,
      leading: Icon(icon, color: muted ? Brand.mutedText : Brand.green),
      title: Text(title, style: TextStyle(fontWeight: highlight ? FontWeight.w800 : FontWeight.w600, color: highlight ? Brand.green : null)),
      subtitle: Text(subtitle, style: const TextStyle(color: Brand.mutedText)),
      trailing: const Icon(LucideIcons.chevronRight, size: 18),
      onTap: onTap,
    );
    if (!highlight) return tile;
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      decoration: BoxDecoration(color: Brand.mint, borderRadius: BorderRadius.circular(Brand.radius)),
      child: tile,
    );
  }
}
