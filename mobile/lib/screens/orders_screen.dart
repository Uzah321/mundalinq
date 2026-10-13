import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../state/shell.dart';
import '../theme.dart';
import '../util/format.dart';
import '../util/order_status.dart';
import '../widgets/common.dart';
import 'order_screen.dart';

/// The buyer's orders, newest first, 20 at a time.
class OrdersScreen extends StatefulWidget {
  const OrdersScreen({super.key});

  @override
  State<OrdersScreen> createState() => _OrdersScreenState();
}

class _OrdersScreenState extends State<OrdersScreen> {
  final List<Order> _orders = [];
  final _scroll = ScrollController();
  int _page = 0;
  bool _hasMore = true;
  bool _loading = false;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_scroll.position.extentAfter < 400) _loadMore();
    });
    _loadMore();
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _refresh() async {
    setState(() {
      _orders.clear();
      _page = 0;
      _hasMore = true;
    });
    await _loadMore();
  }

  Future<void> _loadMore() async {
    if (_loading || !_hasMore) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = Paged.fromJson(
        await context.read<ApiClient>().get('/orders', query: {'page': _page + 1}) as Map<String, dynamic>,
        Order.fromJson,
      );
      setState(() {
        _orders.addAll(page.items);
        _page = page.currentPage;
        _hasMore = page.hasMore;
      });
    } catch (error) {
      setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _open(Order order) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderScreen(orderId: order.id)));
    if (mounted) _refresh();
  }

  @override
  Widget build(BuildContext context) {
    Widget body;
    if (_orders.isEmpty && _loading) {
      body = const Center(child: CircularProgressIndicator());
    } else if (_orders.isEmpty && _error != null) {
      body = Center(
        child: EmptyState(
          icon: LucideIcons.wifiOff,
          title: "We couldn't load your orders",
          message: errorMessage(_error!),
          actions: [FilledButton(onPressed: _refresh, child: const Text('Try again'))],
        ),
      );
    } else if (_orders.isEmpty) {
      body = Center(
        child: EmptyState(
          icon: LucideIcons.receipt,
          title: 'No orders yet',
          message: 'When you order from a farm, you can follow it here.',
          actions: [
            FilledButton(
              onPressed: () {
                context.read<ShellController>().go(AppTab.browse);
                Navigator.of(context).popUntil((route) => route.isFirst);
              },
              child: const Text('Browse produce'),
            ),
          ],
        ),
      );
    } else {
      body = ListView.separated(
        controller: _scroll,
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
        itemCount: _orders.length + (_hasMore ? 1 : 0),
        separatorBuilder: (_, _) => const SizedBox(height: 10),
        itemBuilder: (context, index) {
          if (index == _orders.length) {
            return const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator()));
          }
          final order = _orders[index];
          return _OrderTile(order: order, onTap: () => _open(order));
        },
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('My orders')),
      body: RefreshIndicator(onRefresh: _refresh, child: body),
    );
  }
}

class _OrderTile extends StatelessWidget {
  const _OrderTile({required this.order, required this.onTap});

  final Order order;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final farms = order.sellerOrders.map((seller) => seller.farmName).toSet().join(', ');
    final items = order.sellerOrders.fold<int>(0, (count, seller) => count + seller.items.length);

    return Material(
      color: Colors.white,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Brand.radius), side: const BorderSide(color: Brand.border)),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(Brand.radius),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Text(order.number, style: const TextStyle(fontWeight: FontWeight.w800)),
                  const SizedBox(width: 8),
                  if (order.createdAt != null)
                    Text(formatDate(order.createdAt!), style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
                  const Spacer(),
                  Text(formatMoney(order.total, order.currency), style: const TextStyle(fontWeight: FontWeight.w800, color: Brand.green)),
                ],
              ),
              const SizedBox(height: 6),
              Text(
                '$farms · $items item${items == 1 ? '' : 's'}',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 13, color: Brand.mutedText),
              ),
              const SizedBox(height: 10),
              StatusChip(orderStatus(order)),
            ],
          ),
        ),
      ),
    );
  }
}
