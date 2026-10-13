import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/farm_models.dart';
import '../../state/farm.dart';
import '../../state/session.dart';
import '../../theme.dart';
import '../../util/format.dart';
import '../account_screen.dart' show AccountSettingsTiles;
import 'earnings_screen.dart';
import 'farm_form_screen.dart';
import 'farm_reviews_screen.dart';
import 'farm_shell.dart';
import 'verification_screen.dart';

/// The farm itself: verification, money, how sales are going, and settings.
class FarmHomeScreen extends StatefulWidget {
  const FarmHomeScreen({super.key});

  @override
  State<FarmHomeScreen> createState() => _FarmHomeScreenState();
}

class _FarmHomeScreenState extends State<FarmHomeScreen> {
  Earnings? _earnings;
  FarmAnalytics? _analytics;

  Farm get _farm => context.read<FarmController>().farm!;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<ApiClient>();
    final farm = _farm;
    // Only owners see the money; managers get a 403, which simply hides those cards.
    if (farm.isOwner) {
      try {
        final response = await api.get('/farms/${farm.id}/earnings') as Map<String, dynamic>;
        if (mounted) setState(() => _earnings = Earnings.fromJson(response['data'] as Map<String, dynamic>));
      } catch (_) {}
    }
    try {
      final response = await api.get('/farms/${farm.id}/analytics') as Map<String, dynamic>;
      if (mounted) setState(() => _analytics = FarmAnalytics.fromJson(response['data'] as Map<String, dynamic>));
    } catch (_) {}
    if (mounted) await context.read<FarmController>().load();
  }

  Future<void> _open(Widget screen) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    if (mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    final farms = context.watch<FarmController>();
    final farm = farms.farm!;
    final earnings = _earnings;
    final analytics = _analytics;

    return Scaffold(
      appBar: AppBar(toolbarHeight: 64, titleSpacing: 20, title: const FarmTitle(subtitle: 'Your farm'), actions: const [NotificationBell()]),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
          children: [
            _VerificationCard(farm: farm, onOpen: () => _open(VerificationScreen(farm: farm))),
            if (earnings != null) ...[
              const SizedBox(height: 14),
              _Card(
                onTap: () => _open(EarningsScreen(farm: farm)),
                child: Row(
                  children: [
                    Expanded(child: _Figure(label: 'Available', value: formatMoney(earnings.available, earnings.currency), strong: true)),
                    Expanded(child: _Figure(label: 'On its way', value: formatMoney(earnings.pending, earnings.currency))),
                    const Icon(LucideIcons.chevronRight, size: 18, color: Brand.mutedText),
                  ],
                ),
              ),
            ],
            if (analytics != null) ...[
              const SizedBox(height: 14),
              _Card(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('LAST 30 DAYS', style: Brand.eyebrow()),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(child: _Figure(label: 'Orders paid', value: '${analytics.orders}')),
                        Expanded(child: _Figure(label: 'Sales', value: formatMoney(analytics.gross, analytics.currency))),
                        Expanded(child: _Figure(label: 'You keep', value: formatMoney(analytics.net, analytics.currency), strong: true)),
                      ],
                    ),
                    const SizedBox(height: 12),
                    Text(
                      [
                        if (analytics.acceptanceRate != null) 'You accepted ${analytics.acceptanceRate!.replaceFirst('.0', '')}% of orders',
                        if (analytics.missed > 0) '${analytics.missed} missed',
                        '${analytics.liveListings} live listing${analytics.liveListings == 1 ? '' : 's'}',
                        if (analytics.lowStock > 0) '${analytics.lowStock} low on stock',
                      ].join(' · '),
                      style: const TextStyle(fontSize: 12.5, color: Brand.mutedText, height: 1.5),
                    ),
                    if (analytics.topProducts.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Text(
                        'Best seller: ${analytics.topProducts.first['title']} (${formatMoney('${analytics.topProducts.first['revenue']}', analytics.currency)})',
                        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                      ),
                    ],
                  ],
                ),
              ),
            ],
            const SizedBox(height: 14),
            _Tile(icon: LucideIcons.store, title: 'Farm details', subtitle: 'Name, contact, collection and delivery', onTap: () => _open(FarmFormScreen(farm: farm))),
            if (farm.isOwner)
              _Tile(icon: LucideIcons.wallet, title: 'Earnings and payouts', subtitle: 'Where your money is paid', onTap: () => _open(EarningsScreen(farm: farm))),
            _Tile(
              icon: LucideIcons.star,
              title: 'Reviews',
              subtitle: farm.rating.count == 0 ? 'No reviews yet' : '${farm.rating.average?.toStringAsFixed(1)} from ${farm.rating.count} buyers',
              onTap: () => _open(FarmReviewsScreen(farm: farm)),
            ),
            _Tile(
              icon: LucideIcons.plus,
              title: 'Add another farm',
              subtitle: 'For a second farm or business',
              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const FarmFormScreen())),
            ),
            const Divider(height: 28),
            _Tile(
              icon: LucideIcons.shoppingBasket,
              title: 'Switch to buying',
              subtitle: 'Browse and order from other farms',
              onTap: () => context.read<FarmController>().stopSelling(),
            ),
            const AccountSettingsTiles(),
            const Divider(height: 28),
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(LucideIcons.logOut, color: Brand.danger),
              title: const Text('Sign out', style: TextStyle(fontWeight: FontWeight.w600, color: Brand.danger)),
              onTap: () => context.read<Session>().signOut(),
            ),
          ],
        ),
      ),
    );
  }
}

class _VerificationCard extends StatelessWidget {
  const _VerificationCard({required this.farm, required this.onOpen});

  final Farm farm;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final (icon, title, message, action, warm) = switch (farm.verificationStatus) {
      'approved' => (LucideIcons.badgeCheck, 'Verified farm', 'Buyers see your listings and a verified badge.', null, false),
      'pending' => (LucideIcons.clock, 'Verification in progress', 'MundaLinq is checking your documents, usually within 2 working days.', 'View', true),
      'rejected' => (LucideIcons.circleAlert, 'Verification not approved', 'See why, fix it and send your documents again.', 'See why', true),
      _ => (LucideIcons.shieldCheck, 'Verify your farm', 'Send a photo of your ID so buyers can see your listings.', 'Start', true),
    };

    return _Card(
      color: warm ? Brand.harvest.withValues(alpha: 0.22) : Brand.mint,
      onTap: action == null ? null : onOpen,
      child: Row(
        children: [
          Icon(icon, color: warm ? Brand.harvestText : Brand.green),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: TextStyle(fontWeight: FontWeight.w800, color: warm ? Brand.harvestText : Brand.green)),
                const SizedBox(height: 2),
                Text(message, style: const TextStyle(fontSize: 12.5, height: 1.4)),
              ],
            ),
          ),
          if (action != null) Text(action, style: TextStyle(fontWeight: FontWeight.w800, color: warm ? Brand.harvestText : Brand.green)),
        ],
      ),
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.child, this.onTap, this.color});

  final Widget child;
  final VoidCallback? onTap;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: color ?? Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Brand.radius),
        side: color == null ? const BorderSide(color: Brand.border) : BorderSide.none,
      ),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(Brand.radius),
        child: Padding(padding: const EdgeInsets.all(16), child: child),
      ),
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({required this.label, required this.value, this.strong = false});

  final String label;
  final String value;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
        const SizedBox(height: 2),
        Text(value, style: TextStyle(fontSize: strong ? 20 : 17, fontWeight: FontWeight.w800, color: strong ? Brand.green : Brand.ink)),
      ],
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({required this.icon, required this.title, required this.subtitle, required this.onTap});

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: Icon(icon, color: Brand.green),
      title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
      subtitle: Text(subtitle, style: const TextStyle(color: Brand.mutedText)),
      trailing: const Icon(LucideIcons.chevronRight, size: 18),
      onTap: onTap,
    );
  }
}
