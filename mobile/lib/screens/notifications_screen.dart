import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../api/social_models.dart';
import '../state/farm.dart';
import '../state/notifications.dart';
import '../state/paged_list.dart';
import '../theme.dart';
import '../util/format.dart';
import '../widgets/common.dart';
import 'dispute_screen.dart';
import 'farm/earnings_screen.dart';
import 'farm/farm_order_screen.dart';
import 'farm/farm_reviews_screen.dart';
import 'farm/listing_screen.dart';
import 'farm/quote_requests_screen.dart';
import 'farm/verification_screen.dart';
import 'messages/conversations_screen.dart';
import 'order_screen.dart';
import 'quotes/quotes_screen.dart';

const _icons = {
  'orders': LucideIcons.receipt,
  'messages': LucideIcons.messageCircle,
  'reviews': LucideIcons.star,
  'disputes': LucideIcons.circleAlert,
  'listings': LucideIcons.package,
  'account': LucideIcons.user,
  'payouts': LucideIcons.wallet,
  'quotes': LucideIcons.clipboardList,
};

/// Everything that also goes out by SMS, plus messages, reviews and low-stock alerts. Tapping opens the thing.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  late final PagedList<AppNotification> _items = PagedList(
    (page) async => Paged.fromJson(
      await context.read<ApiClient>().get('/me/notifications', query: {'page': page}) as Map<String, dynamic>,
      AppNotification.fromJson,
    ),
  );

  @override
  void initState() {
    super.initState();
    _items.refresh();
  }

  @override
  void dispose() {
    _items.dispose();
    super.dispose();
  }

  Future<void> _readAll() async {
    try {
      await context.read<ApiClient>().post('/me/notifications/read-all');
      if (!mounted) return;
      context.read<NotificationsController>().refresh();
      await _items.refresh();
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    }
  }

  Future<void> _open(AppNotification notification) async {
    if (!notification.read) {
      context.read<ApiClient>().post('/me/notifications/${notification.id}/read').catchError((Object _) {});
      context.read<NotificationsController>().markedRead();
      _items.update(
        AppNotification(
          id: notification.id,
          category: notification.category,
          title: notification.title,
          body: notification.body,
          context: notification.context,
          read: true,
          createdAt: notification.createdAt,
        ),
        (a, b) => a.id == b.id,
      );
    }
    final screen = _screenFor(notification);
    if (screen != null) await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
  }

  Widget? _screenFor(AppNotification notification) {
    final ids = notification.context;
    final farm = ids['farm_id'] == null
        ? null
        : context.read<FarmController>().farms.where((farm) => farm.id == ids['farm_id']).firstOrNull;

    if (ids['dispute_id'] != null) return DisputeScreen(disputeId: ids['dispute_id']!);
    if (ids['order_id'] != null) return OrderScreen(orderId: ids['order_id']!);
    if (ids['seller_order_id'] != null && ids['farm_id'] != null) return FarmOrderScreen(orderId: ids['seller_order_id']!);
    if (notification.category == 'messages') return ConversationsScreen(asFarm: ids['farm_id'] != null && farm != null);
    // Offers on the buyer's own request; a farm's alerts about requests also name the farm.
    if (ids['quote_request_id'] != null && ids['farm_id'] == null) return QuoteScreen(requestId: ids['quote_request_id']!);
    if (farm == null) return null;
    if (ids['quote_request_id'] != null) return QuoteRequestsScreen(farm: farm);
    if (ids['product_id'] != null) return ListingScreen(listingId: ids['product_id']!);
    if (ids['review_id'] != null) return FarmReviewsScreen(farm: farm);
    if (notification.category == 'payouts') return EarningsScreen(farm: farm);
    if (notification.category == 'account') return VerificationScreen(farm: farm);
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final unread = context.watch<NotificationsController>().unread;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [if (unread > 0) TextButton(onPressed: _readAll, child: const Text('Mark all read'))],
      ),
      body: RefreshIndicator(
        onRefresh: _items.refresh,
        child: ListenableBuilder(
          listenable: _items,
          builder: (context, _) {
            if (_items.initialLoading) return const Center(child: CircularProgressIndicator());
            if (_items.error != null && _items.items.isEmpty) {
              return ListView(children: [
                EmptyState(
                  icon: LucideIcons.wifiOff,
                  title: "We couldn't load notifications",
                  message: errorMessage(_items.error!),
                  actions: [FilledButton(onPressed: _items.refresh, child: const Text('Try again'))],
                ),
              ]);
            }
            if (_items.items.isEmpty) {
              return ListView(children: const [
                EmptyState(icon: LucideIcons.bell, title: 'Nothing yet', message: 'Updates about your orders, messages and listings show up here.'),
              ]);
            }
            return ListView.separated(
              itemCount: _items.items.length + (_items.hasMore ? 1 : 0),
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (context, index) {
                if (index == _items.items.length) {
                  _items.loadMore();
                  return const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator()));
                }
                final notification = _items.items[index];
                return Material(
                  color: notification.read ? Colors.white : Brand.mint.withValues(alpha: 0.6),
                  child: ListTile(
                    onTap: () => _open(notification),
                    contentPadding: const EdgeInsets.fromLTRB(20, 8, 20, 8),
                    leading: CircleAvatar(
                      backgroundColor: notification.read ? Brand.muted : Colors.white,
                      child: Icon(_icons[notification.category] ?? LucideIcons.bell, size: 18, color: Brand.green),
                    ),
                    title: Text(
                      notification.title ?? 'MundaLinq',
                      style: TextStyle(fontWeight: notification.read ? FontWeight.w600 : FontWeight.w800),
                    ),
                    subtitle: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (notification.body != null) Text(notification.body!, style: const TextStyle(height: 1.4)),
                        if (notification.createdAt != null)
                          Padding(
                            padding: const EdgeInsets.only(top: 4),
                            child: Text(formatWhen(notification.createdAt!), style: const TextStyle(fontSize: 11.5, color: Brand.mutedText)),
                          ),
                      ],
                    ),
                  ),
                );
              },
            );
          },
        ),
      ),
    );
  }
}
