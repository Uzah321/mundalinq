import 'dart:async';

import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/models.dart';
import '../../api/quote_models.dart';
import '../../state/paged_list.dart';
import '../../state/session.dart';
import '../../theme.dart';
import '../../util/format.dart';
import '../../util/order_status.dart';
import '../../util/quote_text.dart';
import '../../widgets/common.dart';
import '../address_form_screen.dart';
import '../auth_screen.dart';
import '../checkout_screen.dart' show AddressOption;
import '../messages/chat_screen.dart';
import '../order_screen.dart';
import 'quote_form_screen.dart';

const _gettingIt = {'either': 'Delivery or collection', 'delivery': 'Delivered to you', 'pickup': 'You collect from the farm'};

StatusLabel _statusOf(QuoteRequest request) => switch (request.status) {
      'open' => const StatusLabel('Taking offers', StatusTone.waiting),
      'awarded' => const StatusLabel('Offer accepted', StatusTone.active),
      'expired' => const StatusLabel('Closed for offers', StatusTone.done),
      _ => const StatusLabel('Closed', StatusTone.done),
    };

/// The buyer's requests for quotes, newest first.
class QuotesScreen extends StatefulWidget {
  const QuotesScreen({super.key});

  @override
  State<QuotesScreen> createState() => _QuotesScreenState();
}

class _QuotesScreenState extends State<QuotesScreen> {
  late final PagedList<QuoteRequest> _requests = PagedList(
    (page) async => Paged.fromJson(
      await context.read<ApiClient>().get('/quote-requests', query: {'page': page}) as Map<String, dynamic>,
      QuoteRequest.fromJson,
    ),
  );

  @override
  void initState() {
    super.initState();
    _requests.refresh();
  }

  @override
  void dispose() {
    _requests.dispose();
    super.dispose();
  }

  Future<void> _new() async {
    final created = await Navigator.of(context).push<(QuoteRequest, int)>(MaterialPageRoute(builder: (_) => const QuoteFormScreen()));
    if (created == null || !mounted) return;
    final (request, farmsTold) = created;
    _requests.refresh();
    showMessage(
      context,
      farmsTold == 0
          ? 'Request posted. No farm in ${request.province} sells this on MundaLinq yet; farms that start selling it there can still answer.'
          : 'Request sent to $farmsTold farm${farmsTold == 1 ? '' : 's'} in ${request.province}.',
    );
    await _open(request.id);
  }

  Future<void> _open(String id) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => QuoteScreen(requestId: id)));
    if (mounted) _requests.refresh();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Requests for quotes')),
      floatingActionButton: FloatingActionButton.extended(onPressed: _new, icon: const Icon(LucideIcons.plus), label: const Text('New request')),
      body: RefreshIndicator(
        onRefresh: _requests.refresh,
        child: ListenableBuilder(
          listenable: _requests,
          builder: (context, _) {
            if (_requests.initialLoading) return const Center(child: CircularProgressIndicator());
            if (_requests.error != null && _requests.items.isEmpty) {
              return ListView(
                children: [
                  EmptyState(
                    icon: LucideIcons.wifiOff,
                    title: "Your requests didn't load",
                    message: errorMessage(_requests.error!),
                    actions: [FilledButton(onPressed: _requests.refresh, child: const Text('Try again'))],
                  ),
                ],
              );
            }
            if (_requests.items.isEmpty) {
              return ListView(
                children: [
                  EmptyState(
                    icon: LucideIcons.clipboardList,
                    title: 'Ask farms for a price',
                    message: 'Buying for a restaurant, school or shop? For example: 200 kg of tomatoes every week. '
                        'Farms near you send their prices, and you choose.',
                    actions: [FilledButton(onPressed: _new, child: const Text('New request'))],
                  ),
                ],
              );
            }
            return ListView.separated(
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 96),
              itemCount: _requests.items.length,
              separatorBuilder: (_, _) => const SizedBox(height: 10),
              itemBuilder: (context, index) {
                final request = _requests.items[index];
                return Material(
                  color: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Brand.radius), side: const BorderSide(color: Brand.border)),
                  child: InkWell(
                    onTap: () => _open(request.id),
                    borderRadius: BorderRadius.circular(Brand.radius),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Expanded(child: Text(quoteHeadline(request), style: const TextStyle(fontWeight: FontWeight.w800, height: 1.35))),
                              const SizedBox(width: 8),
                              StatusChip(_statusOf(request)),
                            ],
                          ),
                          const SizedBox(height: 4),
                          Text(
                            '${quoteSchedule(request)} · ${request.district}',
                            style: const TextStyle(fontSize: 13, color: Brand.mutedText),
                          ),
                          const SizedBox(height: 6),
                          Text(
                            request.offersCount == 1 ? '1 offer' : '${request.offersCount} offers',
                            style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: Brand.green),
                          ),
                        ],
                      ),
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

/// One request and its offers, cheapest first: accept one (it becomes an order), decline, or order again.
class QuoteScreen extends StatefulWidget {
  const QuoteScreen({super.key, required this.requestId});

  final String requestId;

  @override
  State<QuoteScreen> createState() => _QuoteScreenState();
}

class _QuoteScreenState extends State<QuoteScreen> {
  QuoteRequest? _request;
  Object? _error;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    _load();
    // New offers arrive while the request is open.
    _poll = Timer.periodic(const Duration(seconds: 30), (_) {
      if (_request?.isOpen ?? false) _load();
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final response = await context.read<ApiClient>().get('/quote-requests/${widget.requestId}') as Map<String, dynamic>;
      if (!mounted) return;
      setState(() {
        _request = QuoteRequest.fromJson(response['data'] as Map<String, dynamic>);
        _error = null;
      });
    } catch (error) {
      if (mounted) setState(() => _error = error);
    }
  }

  Future<bool> _confirm(String title, String message, String action) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: Text(message),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Not now')),
          TextButton(onPressed: () => Navigator.pop(context, true), child: Text(action)),
        ],
      ),
    );
    return confirmed == true;
  }

  Future<void> _post(String path, String done) async {
    try {
      await context.read<ApiClient>().post(path);
      if (!mounted) return;
      showMessage(context, done);
      await _load();
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    }
  }

  Future<void> _close() async {
    if (!await _confirm('Stop taking offers?', 'Farms can no longer send offers and the ones waiting are declined.', 'Stop taking offers')) return;
    await _post('/quote-requests/${widget.requestId}/close', 'Request closed.');
  }

  Future<void> _decline(QuoteOffer offer) async {
    if (!await _confirm('Decline ${offer.farmName ?? 'this'} offer?', "We'll let the farm know. Other offers stay open.", 'Decline')) return;
    await _post('/quote-offers/${offer.id}/decline', 'Offer declined.');
  }

  Future<void> _order(QuoteOffer offer, {required bool again}) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => QuoteOrderScreen(request: _request!, offer: offer, again: again)));
    if (mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    final request = _request;

    Widget body;
    if (request == null) {
      body = _error == null
          ? const Center(child: CircularProgressIndicator())
          : Center(
              child: EmptyState(
                icon: LucideIcons.wifiOff,
                title: "This request didn't load",
                message: errorMessage(_error!),
                actions: [FilledButton(onPressed: _load, child: const Text('Try again'))],
              ),
            );
    } else {
      final offers = request.offers.where((offer) => offer.status != 'withdrawn').toList();
      body = RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
          children: [
            _Summary(request: request),
            if (request.isOpen)
              Align(
                alignment: Alignment.centerRight,
                child: TextButton(onPressed: _close, child: const Text('Stop taking offers')),
              ),
            const SizedBox(height: 10),
            Text(offers.length > 1 ? 'Offers · cheapest first' : 'Offers', style: Brand.display(size: 17)),
            const SizedBox(height: 10),
            if (offers.isEmpty)
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(color: Brand.muted, borderRadius: BorderRadius.circular(Brand.radius)),
                child: Text(
                  request.isOpen
                      ? 'No offers yet. Farms in ${request.province} that sell ${request.categoryName.toLowerCase()} see your request and can send a price. '
                          'Offers show up here, often within a day.'
                      : 'No farm sent an offer. Try a new request with more time to answer.',
                  style: const TextStyle(fontSize: 13, height: 1.5, color: Brand.mutedText),
                ),
              )
            else
              for (final (index, offer) in offers.indexed)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: _OfferCard(
                    offer: offer,
                    cheapest: index == 0 && offers.length > 1 && request.isOpen,
                    canDecide: request.isOpen && offer.isPending,
                    onAccept: () => _order(offer, again: false),
                    onDecline: () => _decline(offer),
                    onOrderAgain: () => _order(offer, again: true),
                  ),
                ),
          ],
        ),
      );
    }

    return Scaffold(appBar: AppBar(title: const Text('Your request')), body: body);
  }
}

class _Summary extends StatelessWidget {
  const _Summary({required this.request});

  final QuoteRequest request;

  @override
  Widget build(BuildContext context) {
    Widget fact(IconData icon, String text) => Padding(
          padding: const EdgeInsets.only(top: 8),
          child: Row(
            children: [
              Icon(icon, size: 16, color: Brand.green),
              const SizedBox(width: 8),
              Expanded(child: Text(text, style: const TextStyle(fontSize: 13.5))),
            ],
          ),
        );

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: Colors.white, border: Border.all(color: Brand.border), borderRadius: BorderRadius.circular(Brand.radius)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: Text(quoteHeadline(request), style: Brand.display(size: 18, height: 1.3))),
              const SizedBox(width: 8),
              StatusChip(_statusOf(request)),
            ],
          ),
          const SizedBox(height: 2),
          Text(request.categoryName, style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
          fact(request.repeats ? LucideIcons.repeat : LucideIcons.calendar, quoteSchedule(request)),
          fact(LucideIcons.truck, _gettingIt[request.fulfilment] ?? request.fulfilment),
          fact(LucideIcons.mapPin, '${request.district}, ${request.province}'),
          if (request.isOpen && request.closesAt != null) fact(LucideIcons.clock, 'Taking offers until ${formatWhen(request.closesAt!)}'),
          if (request.notes != null) ...[
            const SizedBox(height: 10),
            Text('“${request.notes}”', style: const TextStyle(fontSize: 13.5, height: 1.5, fontStyle: FontStyle.italic)),
          ],
        ],
      ),
    );
  }
}

class _OfferCard extends StatelessWidget {
  const _OfferCard({
    required this.offer,
    required this.cheapest,
    required this.canDecide,
    required this.onAccept,
    required this.onDecline,
    required this.onOrderAgain,
  });

  final QuoteOffer offer;
  final bool cheapest;
  final bool canDecide;
  final VoidCallback onAccept;
  final VoidCallback onDecline;
  final VoidCallback onOrderAgain;

  @override
  Widget build(BuildContext context) {
    String money(String amount) => formatMoney(amount, offer.currency);
    final delivery = offer.fulfilment == 'delivery';
    final rating = offer.rating;
    final status = switch (offer.status) {
      'accepted' => const StatusLabel('Accepted', StatusTone.active),
      'declined' => const StatusLabel('Declined', StatusTone.done),
      _ => null,
    };

    Widget line(String label, String value, {bool strong = false}) => Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Row(
            children: [
              Expanded(child: Text(label, style: TextStyle(fontSize: 13, color: strong ? Brand.ink : Brand.mutedText, fontWeight: strong ? FontWeight.w700 : null))),
              Text(value, style: TextStyle(fontWeight: FontWeight.w800, color: strong ? Brand.green : Brand.ink, fontSize: strong ? 16 : 13)),
            ],
          ),
        );

    return Opacity(
      opacity: offer.status == 'declined' ? 0.65 : 1,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: offer.status == 'accepted' ? Brand.green : Brand.border),
          borderRadius: BorderRadius.circular(Brand.radius),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Flexible(child: Text(offer.farmName ?? 'A farm', style: Brand.display(size: 15))),
                          if (offer.farmVerified) ...[const SizedBox(width: 4), const Icon(LucideIcons.badgeCheck, size: 16, color: Brand.green)],
                        ],
                      ),
                      const SizedBox(height: 2),
                      if (rating == null || rating.count == 0)
                        const Text('No reviews yet', style: TextStyle(fontSize: 12, color: Brand.mutedText))
                      else
                        Row(
                          children: [
                            const Icon(LucideIcons.star, size: 13, color: Brand.harvest),
                            const SizedBox(width: 4),
                            Text('${rating.average?.toStringAsFixed(1)} (${rating.count})', style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
                          ],
                        ),
                    ],
                  ),
                ),
                if (cheapest) const Tag(label: 'Lowest total'),
                if (status != null) StatusChip(status),
              ],
            ),
            const SizedBox(height: 4),
            line('${formatQuantity(offer.quantity, offer.unit)} of ${offer.title}${offer.label == null ? '' : ' (${offer.label})'}', '${money(offer.unitPrice)} / ${offer.unit.name}'),
            line(delivery ? 'Delivery' : 'You collect from the farm', delivery ? money(offer.deliveryFee) : 'Free'),
            line('Each order', money(offer.total), strong: true),
            if (offer.message != null) ...[
              const SizedBox(height: 8),
              Text('“${offer.message}”', style: const TextStyle(fontSize: 13, fontStyle: FontStyle.italic, height: 1.4)),
            ],
            if (offer.validUntil != null) ...[
              const SizedBox(height: 6),
              Text('Price holds until ${formatDate(DateTime.parse(offer.validUntil!))}', style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
            ],
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (canDecide) ...[
                  FilledButton(onPressed: onAccept, child: Text('Accept · ${money(offer.total)}')),
                  TextButton(onPressed: onDecline, child: const Text('Decline')),
                ],
                if (offer.canOrderAgain) FilledButton.icon(onPressed: onOrderAgain, icon: const Icon(LucideIcons.repeat, size: 16), label: Text('Order again · ${money(offer.total)}')),
                if (offer.farmId != null)
                  OutlinedButton.icon(
                    onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute(builder: (_) => ChatScreen.forFarm(farmId: offer.farmId!, productId: offer.productId, title: offer.farmName ?? 'Farm')),
                    ),
                    icon: const Icon(LucideIcons.messageCircle, size: 16),
                    label: const Text('Message'),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Accepting an offer, or ordering again on an accepted one: both place an order for the farm to confirm.
class QuoteOrderScreen extends StatefulWidget {
  const QuoteOrderScreen({super.key, required this.request, required this.offer, required this.again});

  final QuoteRequest request;
  final QuoteOffer offer;
  final bool again;

  @override
  State<QuoteOrderScreen> createState() => _QuoteOrderScreenState();
}

class _QuoteOrderScreenState extends State<QuoteOrderScreen> {
  final _note = TextEditingController();
  List<Address>? _addresses;
  String? _addressId;
  bool _placing = false;
  String? _problem;

  QuoteOffer get _offer => widget.offer;
  bool get _delivery => _offer.fulfilment == 'delivery';

  @override
  void initState() {
    super.initState();
    if (_delivery) _loadAddresses();
  }

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _loadAddresses() async {
    try {
      final response = await context.read<ApiClient>().get('/me/addresses') as Map<String, dynamic>;
      final addresses = (response['data'] as List).cast<Map<String, dynamic>>().map(Address.fromJson).toList();
      if (!mounted) return;
      setState(() {
        _addresses = addresses;
        _addressId ??= (addresses.where((address) => address.isDefault).firstOrNull ?? addresses.firstOrNull)?.id;
      });
    } catch (error) {
      if (mounted) setState(() => _problem = errorMessage(error));
    }
  }

  Future<void> _addAddress() async {
    final address = await Navigator.of(context).push<Address>(MaterialPageRoute(builder: (_) => const AddressFormScreen()));
    if (address == null || !mounted) return;
    setState(() {
      _addresses = [...?_addresses, address];
      _addressId = address.id;
      _problem = null;
    });
  }

  Future<void> _verifyPhone() => Navigator.of(context).push(
        MaterialPageRoute(
          builder: (context) => Scaffold(
            appBar: AppBar(title: const Text('Confirm your number')),
            body: VerifyPhoneView(codeJustSent: false, onDone: () => Navigator.pop(context)),
          ),
        ),
      );

  Future<void> _place() async {
    if (context.read<Session>().user?.phoneVerified != true) {
      await _verifyPhone();
      return;
    }
    if (_delivery && _addressId == null) {
      setState(() => _problem = 'Add the address the farm should deliver to.');
      return;
    }
    setState(() {
      _placing = true;
      _problem = null;
    });
    try {
      final response = await context.read<ApiClient>().post('/quote-offers/${_offer.id}/${widget.again ? 'order' : 'accept'}', {
        if (_delivery) 'address_id': _addressId,
        if (_note.text.trim().isNotEmpty) 'note': _note.text.trim(),
      }) as Map<String, dynamic>;
      final order = Order.fromJson(response['data'] as Map<String, dynamic>);
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => OrderScreen(orderId: order.id, justPlaced: true)));
    } catch (error) {
      if (mounted) setState(() => _problem = errorMessage(error));
    } finally {
      if (mounted) setState(() => _placing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<Session>().user;
    final total = formatMoney(_offer.total, _offer.currency);

    return Scaffold(
      appBar: AppBar(title: Text(widget.again ? 'Order again' : 'Accept offer')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
        children: [
          Text(
            '${formatQuantity(_offer.quantity, _offer.unit)} of ${_offer.title} from ${_offer.farmName ?? 'the farm'} for $total'
            '${_delivery ? ', delivered.' : ', collected from the farm.'}',
            style: Brand.display(size: 16, height: 1.4),
          ),
          const SizedBox(height: 8),
          Text(
            [
              if (!widget.again && widget.request.isOpen) 'Other offers are declined.',
              'The farm confirms first; then you pay by mobile money.',
            ].join(' '),
            style: const TextStyle(fontSize: 13, color: Brand.mutedText, height: 1.5),
          ),
          if (user != null && !user.phoneVerified) ...[
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.fromLTRB(14, 6, 6, 6),
              decoration: BoxDecoration(color: Brand.harvest.withValues(alpha: 0.25), borderRadius: BorderRadius.circular(Brand.radius)),
              child: Row(
                children: [
                  const Expanded(
                    child: Text('Confirm your phone number to place orders.',
                        style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: Brand.harvestText)),
                  ),
                  TextButton(onPressed: _verifyPhone, child: const Text('Confirm')),
                ],
              ),
            ),
          ],
          if (_delivery) ...[
            const SizedBox(height: 20),
            Text('Deliver to', style: Brand.display(size: 15)),
            const SizedBox(height: 10),
            if (_addresses == null)
              const Center(child: CircularProgressIndicator())
            else if (_addresses!.isEmpty)
              const Text('Add the address the farm should deliver to.', style: TextStyle(color: Brand.mutedText))
            else
              RadioGroup<String>(
                groupValue: _addressId,
                onChanged: (id) => setState(() => _addressId = id),
                child: Column(children: [for (final address in _addresses!) AddressOption(address: address)]),
              ),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(onPressed: _addAddress, icon: const Icon(LucideIcons.plus, size: 16), label: const Text('Add a new address')),
            ),
          ],
          const SizedBox(height: 16),
          TextField(
            controller: _note,
            minLines: 2,
            maxLines: 4,
            maxLength: 500,
            textCapitalization: TextCapitalization.sentences,
            decoration: const InputDecoration(labelText: 'Note for the farm (optional)', hintText: 'e.g. Deliver to the back door before 8am'),
          ),
          if (_problem != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Text(_problem!, style: const TextStyle(color: Brand.danger)),
            ),
          FilledButton(
            onPressed: _placing ? null : _place,
            child: _placing
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : Text('Place order · $total'),
          ),
        ],
      ),
    );
  }
}
