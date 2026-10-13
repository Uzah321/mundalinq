import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/server.dart';
import '../state/session.dart';
import '../state/shopping_area.dart';
import '../theme.dart';
import '../util/format.dart';
import '../widgets/common.dart';
import 'auth_screen.dart';
import 'location_sheet.dart';
import 'orders_screen.dart';
import 'server_sheet.dart';

class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final user = session.user;

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
            Text(
              [formatPhone(user.phone), ?user.email].join(' · '),
              textAlign: TextAlign.center,
              style: const TextStyle(color: Brand.mutedText),
            ),
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
            const SizedBox(height: 28),
          ],
          if (session.signedIn) ...[
            const Divider(),
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(LucideIcons.receipt, color: Brand.green),
              title: const Text('My orders', style: TextStyle(fontWeight: FontWeight.w600)),
              subtitle: const Text('Follow, pay for and confirm your orders', style: TextStyle(color: Brand.mutedText)),
              trailing: const Icon(LucideIcons.chevronRight, size: 18),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const OrdersScreen())),
            ),
          ],
          const Divider(),
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: const Icon(LucideIcons.mapPin, color: Brand.green),
            title: const Text('Shopping area', style: TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text(context.watch<ShoppingAreaController>().label, style: const TextStyle(color: Brand.mutedText)),
            trailing: const Icon(LucideIcons.chevronRight, size: 18),
            onTap: () => showLocationSheet(context),
          ),
          if (session.signedIn) ...[
            const Divider(),
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(LucideIcons.logOut, color: Brand.danger),
              title: const Text('Sign out', style: TextStyle(fontWeight: FontWeight.w600, color: Brand.danger)),
              onTap: () async {
                await context.read<Session>().signOut();
                if (context.mounted) showMessage(context, 'You are signed out');
              },
            ),
          ],
          const Divider(),
          // The address lives outside the widget tree, so this tile redraws itself after a change.
          StatefulBuilder(
            builder: (context, setState) => ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(LucideIcons.server, color: Brand.mutedText),
              title: const Text('Server address', style: TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Text(Server.origin, style: const TextStyle(color: Brand.mutedText)),
              trailing: const Icon(LucideIcons.chevronRight, size: 18),
              onTap: () async {
                await showServerSheet(context);
                setState(() {});
              },
            ),
          ),
          const SizedBox(height: 12),
          const Text('MundaLinq 0.1', textAlign: TextAlign.center, style: TextStyle(fontSize: 11, color: Brand.mutedText)),
        ],
      ),
    );
  }
}
