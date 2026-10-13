import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/farm_models.dart';
import '../../theme.dart';
import '../../util/farm_status.dart';
import '../../util/format.dart';
import '../../widgets/common.dart';
import '../../widgets/photo_picker.dart';
import '../messages/chat_screen.dart';
import '../order_screen.dart' show StatusChip;

/// One order as the farm handles it: the packing list, the buyer, and the next step.
class FarmOrderScreen extends StatefulWidget {
  const FarmOrderScreen({super.key, required this.orderId});

  final String orderId;

  @override
  State<FarmOrderScreen> createState() => _FarmOrderScreenState();
}

class _FarmOrderScreenState extends State<FarmOrderScreen> {
  FarmOrder? _order;
  Object? _error;
  bool _busy = false;
  Timer? _refresh;

  ApiClient get _api => context.read<ApiClient>();

  @override
  void initState() {
    super.initState();
    _load();
    // The buyer pays or confirms while the farmer watches.
    _refresh = Timer.periodic(const Duration(seconds: 20), (_) {
      if (!_busy && ['accepted', 'delivered'].contains(_order?.status)) _load();
    });
  }

  @override
  void dispose() {
    _refresh?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final response = await _api.get('/seller-orders/${widget.orderId}') as Map<String, dynamic>;
      if (mounted) setState(() => _order = FarmOrder.fromJson(response['data'] as Map<String, dynamic>));
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
  }

  /// Runs an action that returns the updated order; shows [done] on success.
  Future<void> _act(Future<dynamic> Function() action, String done) async {
    setState(() => _busy = true);
    try {
      final response = await action();
      if (!mounted) return;
      if (response is Map<String, dynamic> && response['data'] is Map<String, dynamic>) {
        setState(() => _order = FarmOrder.fromJson(response['data'] as Map<String, dynamic>));
      } else {
        await _load();
      }
      if (mounted) showMessage(context, done);
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _accept(FarmOrder order) async {
    final changes = await showModalBottomSheet<Map<String, String>>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _AcceptSheet(order: order),
    );
    if (changes == null) return;
    await _act(
      () => _api.post('/seller-orders/${order.id}/accept', {
        if (changes.isNotEmpty) 'items': [for (final entry in changes.entries) {'id': entry.key, 'quantity': entry.value}],
      }),
      'Order accepted. The buyer has been asked to pay.',
    );
  }

  Future<void> _reject(FarmOrder order) async {
    final reason = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => const _RejectSheet(),
    );
    if (reason == null) return;
    await _act(() => _api.post('/seller-orders/${order.id}/reject', {'reason': reason}), 'Order declined. The buyer has been told.');
  }

  Future<void> _handover(FarmOrder order) async {
    final code = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _HandoverSheet(order: order, api: _api),
    );
    if (code == null) return;
    await _load();
    if (mounted) showMessage(context, 'Handed over. Thank you!');
  }

  Future<void> _message(FarmOrder order) => Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => ChatScreen.forOrder(sellerOrderId: order.id, title: order.buyerName, asFarm: true)),
      );

  @override
  Widget build(BuildContext context) {
    final order = _order;

    return Scaffold(
      appBar: AppBar(
        title: Text(order == null ? 'Order' : 'Order ${order.number}'),
        actions: [
          if (order != null)
            IconButton(
              tooltip: 'Sale record (PDF)',
              icon: const Icon(LucideIcons.fileDown),
              onPressed: () => openDocument(context, context.read<ApiClient>(), '/seller-orders/${order.id}/sale-record-link'),
            ),
        ],
      ),
      body: order == null
          ? Center(
              child: _error == null
                  ? const CircularProgressIndicator()
                  : EmptyState(
                      icon: LucideIcons.wifiOff,
                      title: "We couldn't load this order",
                      message: errorMessage(_error!),
                      actions: [FilledButton(onPressed: _load, child: const Text('Try again'))],
                    ),
            )
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
                children: [
                  _box(
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            StatusChip(farmOrderStatus(order)),
                            const Spacer(),
                            if (order.createdAt != null)
                              Text(formatWhen(order.createdAt!), style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
                          ],
                        ),
                        const SizedBox(height: 10),
                        Text(farmOrderNextStep(order), style: const TextStyle(fontSize: 14, height: 1.5)),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  _box(_Buyer(order: order, onMessage: () => _message(order))),
                  const SizedBox(height: 12),
                  _box(_PackingList(order: order)),
                  const SizedBox(height: 18),
                  ..._actions(order),
                ],
              ),
            ),
    );
  }

  List<Widget> _actions(FarmOrder order) {
    Widget progress(String label) => FilledButton(
          onPressed: null,
          child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            const SizedBox.square(dimension: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)),
            const SizedBox(width: 10),
            Text(label),
          ]),
        );
    if (_busy) return [progress('Saving…')];

    return switch (order.status) {
      'awaiting_confirmation' => [
          FilledButton.icon(onPressed: () => _accept(order), icon: const Icon(LucideIcons.check, size: 18), label: const Text('Accept order')),
          const SizedBox(height: 8),
          OutlinedButton(onPressed: () => _reject(order), child: const Text('Decline')),
        ],
      'preparing' => [
          FilledButton.icon(
            onPressed: () => _act(() => _api.post('/seller-orders/${order.id}/ready'), order.isPickup ? 'Marked ready. The buyer has been told to come.' : 'Marked as packed.'),
            icon: const Icon(LucideIcons.packageCheck, size: 18),
            label: Text(order.isPickup ? 'Ready for collection' : 'Packed and ready'),
          ),
        ],
      'ready' when !order.isPickup => [
          FilledButton.icon(
            onPressed: () => _act(() => _api.post('/seller-orders/${order.id}/dispatch'), 'Out for delivery. The buyer has been told.'),
            icon: const Icon(LucideIcons.truck, size: 18),
            label: const Text('Send it out'),
          ),
        ],
      'ready' || 'dispatched' => [
          FilledButton.icon(
            onPressed: () => _handover(order),
            icon: const Icon(LucideIcons.keyRound, size: 18),
            label: const Text("Hand over with the buyer's code"),
          ),
        ],
      _ => const [],
    };
  }

  Widget _box(Widget child) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(border: Border.all(color: Brand.border), borderRadius: BorderRadius.circular(Brand.radius)),
        child: child,
      );
}

class _Buyer extends StatelessWidget {
  const _Buyer({required this.order, required this.onMessage});

  final FarmOrder order;
  final VoidCallback onMessage;

  @override
  Widget build(BuildContext context) {
    final address = order.deliveryAddress;
    final latitude = address == null ? null : double.tryParse('${address['latitude']}');
    final longitude = address == null ? null : double.tryParse('${address['longitude']}');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const Icon(LucideIcons.user, size: 16, color: Brand.green),
            const SizedBox(width: 8),
            Expanded(child: Text(order.buyerName, style: const TextStyle(fontWeight: FontWeight.w700))),
            Icon(order.isPickup ? LucideIcons.store : LucideIcons.truck, size: 14, color: Brand.mutedText),
            const SizedBox(width: 4),
            Text(order.isPickup ? 'Collects' : 'Delivery', style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
          ],
        ),
        if (order.note != null) ...[
          const SizedBox(height: 8),
          Text('"${order.note}"', style: const TextStyle(fontStyle: FontStyle.italic, color: Brand.mutedText)),
        ],
        if (address != null) ...[
          const SizedBox(height: 8),
          Text(
            [address['recipient_name'], address['address_line'], address['district'], address['directions']].whereType<String>().join(' · '),
            style: const TextStyle(fontSize: 13, height: 1.5),
          ),
        ],
        const SizedBox(height: 6),
        Wrap(
          spacing: 4,
          children: [
            TextButton.icon(onPressed: onMessage, icon: const Icon(LucideIcons.messageCircle, size: 16), label: const Text('Message')),
            if (order.buyerPhone != null)
              TextButton.icon(
                onPressed: () => callPhone(context, order.buyerPhone!),
                icon: const Icon(LucideIcons.phone, size: 16),
                label: Text(formatPhone(order.buyerPhone!)),
              ),
            if (latitude != null && longitude != null)
              TextButton.icon(
                onPressed: () => openMap(context, latitude, longitude, 'the delivery address'),
                icon: const Icon(LucideIcons.mapPin, size: 16),
                label: const Text('Map'),
              ),
          ],
        ),
        if (order.buyerPhone == null && order.status == 'awaiting_confirmation')
          const Text("The buyer's number and address show once you accept.", style: TextStyle(fontSize: 12, color: Brand.mutedText)),
      ],
    );
  }
}

class _PackingList extends StatelessWidget {
  const _PackingList({required this.order});

  final FarmOrder order;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('Packing list', style: TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        for (final item in order.items)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${formatQuantity(item.confirmedQuantity ?? item.quantity, item.unit)} · ${item.title}',
                        style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
                      ),
                      if (item.label != null) Text(item.label!, style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
                      if (item.confirmedQuantity != null && item.confirmedQuantity != item.quantity)
                        Text('Ordered ${formatQuantity(item.quantity, item.unit)}', style: const TextStyle(fontSize: 12, color: Brand.harvestText)),
                    ],
                  ),
                ),
                Text(formatMoney(item.lineTotal, order.currency), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
              ],
            ),
          ),
        const Divider(height: 20),
        if (order.deliveryFee != '0.00')
          Row(children: [
            const Expanded(child: Text('Delivery', style: TextStyle(color: Brand.mutedText))),
            Text(formatMoney(order.deliveryFee, order.currency)),
          ]),
        Row(children: [
          const Expanded(child: Text('Total', style: TextStyle(fontWeight: FontWeight.w800))),
          Text(formatMoney(order.total, order.currency), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
        ]),
        const SizedBox(height: 4),
        const Text('MundaLinq keeps a 5% fee on produce, not on delivery.', style: TextStyle(fontSize: 11.5, color: Brand.mutedText)),
      ],
    );
  }
}

/// Confirm the order, optionally supplying less of some items. Returns the changed quantities by item id.
class _AcceptSheet extends StatefulWidget {
  const _AcceptSheet({required this.order});

  final FarmOrder order;

  @override
  State<_AcceptSheet> createState() => _AcceptSheetState();
}

class _AcceptSheetState extends State<_AcceptSheet> {
  late final Map<String, TextEditingController> _quantities = {
    for (final item in widget.order.items) item.id: TextEditingController(text: item.quantity),
  };

  @override
  void dispose() {
    for (final controller in _quantities.values) {
      controller.dispose();
    }
    super.dispose();
  }

  String? get _problem {
    for (final item in widget.order.items) {
      final milli = toMilli(_quantities[item.id]!.text.trim());
      final ordered = toMilli(item.quantity) ?? 0;
      if (milli == null) return 'Enter a quantity for ${item.title} (0 if you have none).';
      if (milli > ordered) return "You can't supply more ${item.unit.pluralName} of ${item.title} than ordered.";
    }
    if (widget.order.items.every((item) => toMilli(_quantities[item.id]!.text.trim()) == 0)) {
      return 'To supply nothing, decline the order instead.';
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final problem = _problem;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        children: [
          Text('Accept order ${widget.order.number}', style: Brand.display(size: 19)),
          const SizedBox(height: 6),
          const Text(
            'Short of something? Lower its quantity (0 if you have none); the buyer only pays for what you supply.',
            style: TextStyle(color: Brand.mutedText, height: 1.5),
          ),
          const SizedBox(height: 14),
          for (final item in widget.order.items)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Row(
                children: [
                  Expanded(
                    child: Text('${item.title}\n${item.label ?? item.unit.pluralName}',
                        style: const TextStyle(fontWeight: FontWeight.w600, height: 1.4)),
                  ),
                  SizedBox(
                    width: 96,
                    child: TextField(
                      controller: _quantities[item.id],
                      textAlign: TextAlign.center,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
                      decoration: InputDecoration(suffixText: 'of ${item.quantity}', contentPadding: const EdgeInsets.symmetric(horizontal: 8, vertical: 12)),
                      onChanged: (_) => setState(() {}),
                    ),
                  ),
                ],
              ),
            ),
          if (problem != null) Text(problem, style: const TextStyle(color: Brand.danger, fontSize: 13)),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: problem != null
                ? null
                : () => Navigator.pop(context, {
                      for (final item in widget.order.items)
                        if (toMilli(_quantities[item.id]!.text.trim()) != toMilli(item.quantity))
                          item.id: fromMilli(toMilli(_quantities[item.id]!.text.trim())!),
                    }),
            child: const Text('Accept'),
          ),
        ],
      ),
    );
  }
}

class _RejectSheet extends StatefulWidget {
  const _RejectSheet();

  @override
  State<_RejectSheet> createState() => _RejectSheetState();
}

class _RejectSheetState extends State<_RejectSheet> {
  static const _reasons = [
    'Sold out',
    "We can't deliver to that address",
    'Not available at the moment',
  ];

  final _other = TextEditingController();
  String? _reason = _reasons.first;

  @override
  void dispose() {
    _other.dispose();
    super.dispose();
  }

  String get _value => _reason ?? _other.text.trim();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        children: [
          Text('Decline this order?', style: Brand.display(size: 19)),
          const SizedBox(height: 6),
          const Text('The buyer sees your reason. Their stock reservation is released.', style: TextStyle(color: Brand.mutedText)),
          RadioGroup<String?>(
            groupValue: _reason,
            onChanged: (reason) => setState(() => _reason = reason),
            child: Column(
              children: [
                for (final reason in _reasons) RadioListTile<String?>(value: reason, title: Text(reason), dense: true, contentPadding: EdgeInsets.zero),
                const RadioListTile<String?>(value: null, title: Text('Another reason'), dense: true, contentPadding: EdgeInsets.zero),
              ],
            ),
          ),
          if (_reason == null)
            TextField(
              controller: _other,
              maxLength: 300,
              decoration: const InputDecoration(hintText: 'Tell the buyer why'),
              onChanged: (_) => setState(() {}),
            ),
          const SizedBox(height: 8),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Brand.danger),
            onPressed: _value.length < 3 ? null : () => Navigator.pop(context, _value),
            child: const Text('Decline order'),
          ),
        ],
      ),
    );
  }
}

/// The buyer's 6-digit code proves they received the order. Returns the code once the server accepts it.
class _HandoverSheet extends StatefulWidget {
  const _HandoverSheet({required this.order, required this.api});

  final FarmOrder order;
  final ApiClient api;

  @override
  State<_HandoverSheet> createState() => _HandoverSheetState();
}

class _HandoverSheetState extends State<_HandoverSheet> {
  final _code = TextEditingController();
  String? _error;
  bool _sending = false;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      await widget.api.post('/seller-orders/${widget.order.id}/handover', {'code': _code.text});
      if (mounted) Navigator.pop(context, _code.text);
    } catch (error) {
      setState(() => _error = errorMessage(error));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        children: [
          Text('Hand over ${widget.order.number}', style: Brand.display(size: 19)),
          const SizedBox(height: 6),
          Text(
            'Ask ${widget.order.buyerName} for the 6-digit code in their MundaLinq app. It proves they received the order.',
            style: const TextStyle(color: Brand.mutedText, height: 1.5),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _code,
            autofocus: true,
            keyboardType: TextInputType.number,
            textAlign: TextAlign.center,
            maxLength: 6,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            style: Brand.display(size: 26, weight: FontWeight.w700).copyWith(letterSpacing: 12),
            decoration: InputDecoration(counterText: '', hintText: '••••••', errorText: _error, errorMaxLines: 3),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 14),
          FilledButton(
            onPressed: _sending || _code.text.length != 6 ? null : _send,
            child: _sending
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Confirm handover'),
          ),
        ],
      ),
    );
  }
}
