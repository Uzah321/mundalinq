import 'dart:async';

import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/farm_models.dart';
import '../../api/models.dart';
import '../../api/quote_models.dart';
import '../../state/farm.dart';
import '../../state/paged_list.dart';
import '../../theme.dart';
import '../../util/farm_status.dart';
import '../../util/format.dart';
import '../../widgets/common.dart';
import '../order_screen.dart' show StatusChip;
import 'farm_order_screen.dart';
import 'farm_shell.dart';
import 'quote_requests_screen.dart';

/// Filters on the farm's orders; each is one server status.
const _filters = [
  ('awaiting_confirmation', 'New'),
  ('accepted', 'Awaiting payment'),
  ('preparing', 'To prepare'),
  ('ready', 'Ready'),
  ('dispatched', 'On the way'),
  ('delivered', 'Handed over'),
  (null, 'All'),
];

/// The farm's incoming orders, newest first. Checks for new ones every half minute.
class FarmOrdersScreen extends StatefulWidget {
  const FarmOrdersScreen({super.key});

  @override
  State<FarmOrdersScreen> createState() => _FarmOrdersScreenState();
}

class _FarmOrdersScreenState extends State<FarmOrdersScreen> {
  String? _status = 'awaiting_confirmation';
  late final PagedList<FarmOrder> _orders = PagedList(_fetch);
  final _scroll = ScrollController();
  Timer? _poll;

  /// Open requests for quotes this farm hasn't answered yet.
  int _newRequests = 0;

  String get _farmId => context.read<FarmController>().farm!.id;

  Future<Paged<FarmOrder>> _fetch(int page) async => Paged.fromJson(
        await context.read<ApiClient>().get('/farms/$_farmId/orders', query: {'status': _status, 'page': page}) as Map<String, dynamic>,
        FarmOrder.fromJson,
      );

  @override
  void initState() {
    super.initState();
    _orders.refresh();
    _countRequests();
    _scroll.addListener(() {
      if (_scroll.position.extentAfter < 400) _orders.loadMore();
    });
    _poll = Timer.periodic(const Duration(seconds: 30), (_) {
      if (!_orders.loading) _orders.refresh();
      _countRequests();
    });
  }

  Future<void> _countRequests() async {
    try {
      final page = Paged.fromJson(
        await context.read<ApiClient>().get('/farms/$_farmId/quote-requests') as Map<String, dynamic>,
        QuoteRequest.fromJson,
      );
      final unanswered = page.items.where((request) => request.isOpen && (request.offer == null || request.offer!.status == 'withdrawn')).length;
      if (mounted && unanswered != _newRequests) setState(() => _newRequests = unanswered);
    } catch (_) {
      // The banner is a nudge; the requests screen shows any error.
    }
  }

  Future<void> _openRequests() async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => QuoteRequestsScreen(farm: context.read<FarmController>().farm!)));
    if (mounted) _countRequests();
  }

  @override
  void dispose() {
    _poll?.cancel();
    _orders.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _filter(String? status) {
    setState(() => _status = status);
    _orders.refresh();
  }

  Future<void> _open(FarmOrder order) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => FarmOrderScreen(orderId: order.id)));
    if (mounted) _orders.refresh();
  }

  @override
  Widget build(BuildContext context) {
    final farm = context.watch<FarmController>().farm;

    return Scaffold(
      appBar: AppBar(
        toolbarHeight: 64,
        titleSpacing: 20,
        title: const FarmTitle(subtitle: 'Orders'),
        actions: const [NotificationBell()],
      ),
      body: Column(
        children: [
          if (farm != null && !farm.verified) const _VerifyBanner(),
          if (_newRequests > 0) _RequestsBanner(count: _newRequests, onTap: _openRequests),
          SizedBox(
            height: 52,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
              children: [
                for (final (status, label) in _filters)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: Text(label),
                      selected: _status == status,
                      onSelected: (_) => _filter(status),
                      selectedColor: Brand.mint,
                      backgroundColor: Colors.white,
                      showCheckmark: false,
                      labelStyle: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700, color: _status == status ? Brand.green : Brand.ink),
                      side: BorderSide(color: _status == status ? Brand.green : Brand.border),
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _orders.refresh,
              child: ListenableBuilder(
                listenable: _orders,
                builder: (context, _) {
                  if (_orders.initialLoading) return const Center(child: CircularProgressIndicator());
                  if (_orders.error != null && _orders.items.isEmpty) {
                    return ListView(
                      children: [
                        EmptyState(
                          icon: LucideIcons.wifiOff,
                          title: "We couldn't load your orders",
                          message: errorMessage(_orders.error!),
                          actions: [FilledButton(onPressed: _orders.refresh, child: const Text('Try again'))],
                        ),
                      ],
                    );
                  }
                  if (_orders.items.isEmpty) {
                    return ListView(
                      children: [
                        EmptyState(
                          icon: LucideIcons.clipboardList,
                          title: _status == 'awaiting_confirmation' ? 'No new orders' : 'Nothing here',
                          message: _status == 'awaiting_confirmation'
                              ? "New orders appear here, and you'll get a notification and an SMS."
                              : 'Orders in this step will show up here.',
                        ),
                      ],
                    );
                  }
                  return ListView.separated(
                    controller: _scroll,
                    padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                    itemCount: _orders.items.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 10),
                    itemBuilder: (context, index) => _FarmOrderTile(order: _orders.items[index], onTap: () => _open(_orders.items[index])),
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _VerifyBanner extends StatelessWidget {
  const _VerifyBanner();

  @override
  Widget build(BuildContext context) {
    final farm = context.watch<FarmController>().farm!;
    final pending = farm.verificationStatus == 'pending';
    return Material(
      color: Brand.harvest.withValues(alpha: 0.25),
      child: InkWell(
        onTap: () => context.read<FarmShellController>().go(FarmTab.farm),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 10, 20, 10),
          child: Row(
            children: [
              const Icon(LucideIcons.shieldCheck, size: 18, color: Brand.harvestText),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  pending
                      ? 'Your farm is being verified. Buyers will see it once approved.'
                      : 'Verify your farm so buyers can see your listings. Tap to start.',
                  style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: Brand.harvestText),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _RequestsBanner extends StatelessWidget {
  const _RequestsBanner({required this.count, required this.onTap});

  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Brand.mint,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 10, 16, 10),
          child: Row(
            children: [
              const Icon(LucideIcons.clipboardList, size: 18, color: Brand.green),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  count == 1 ? 'A buyer is asking for your price. Tap to send an offer.' : '$count buyers are asking for prices. Tap to send offers.',
                  style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: Brand.green),
                ),
              ),
              const Icon(LucideIcons.chevronRight, size: 18, color: Brand.green),
            ],
          ),
        ),
      ),
    );
  }
}

class _FarmOrderTile extends StatelessWidget {
  const _FarmOrderTile({required this.order, required this.onTap});

  final FarmOrder order;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final items = order.items.map((item) => '${formatQuantity(item.quantity, item.unit)} ${item.title}').join(', ');
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
                  Icon(order.isPickup ? LucideIcons.store : LucideIcons.truck, size: 14, color: Brand.mutedText),
                  const Spacer(),
                  Text(formatMoney(order.total, order.currency), style: const TextStyle(fontWeight: FontWeight.w800, color: Brand.green)),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                '${order.buyerName} · $items',
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 13, color: Brand.mutedText, height: 1.4),
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  StatusChip(farmOrderStatus(order)),
                  const Spacer(),
                  if (order.status == 'awaiting_confirmation' && order.confirmBy != null)
                    Text('by ${formatClock(order.confirmBy!)}', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Brand.harvestText)),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
