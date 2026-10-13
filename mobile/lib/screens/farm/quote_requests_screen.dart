import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/farm_models.dart';
import '../../api/models.dart';
import '../../api/quote_models.dart';
import '../../state/paged_list.dart';
import '../../theme.dart';
import '../../util/format.dart';
import '../../util/order_status.dart';
import '../../util/quote_text.dart';
import '../../widgets/common.dart';
import '../order_screen.dart' show StatusChip;

final _decimal = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))];

const _gettingIt = {
  'either': 'Delivery or collection',
  'delivery': 'Wants it delivered',
  'pickup': 'Will collect from you',
};

String _day(DateTime date) => formatDate(date);

/// How a request stands for this farm; [offer] is its latest offer when the screen has a newer one.
StatusLabel quoteStatusFor(QuoteRequest request, {QuoteOffer? offer}) {
  return switch ((offer ?? request.offer)?.status) {
    'accepted' => const StatusLabel('Accepted', StatusTone.active),
    'declined' => const StatusLabel('Not chosen', StatusTone.done),
    'pending' when request.isOpen => const StatusLabel('Offer sent', StatusTone.waiting),
    'withdrawn' when request.isOpen => const StatusLabel('Withdrawn', StatusTone.done),
    _ when request.isOpen => const StatusLabel('New', StatusTone.active),
    _ => const StatusLabel('Closed', StatusTone.done),
  };
}

/// Buyers in the farm's province asking for prices on what it sells, and the requests it has answered.
class QuoteRequestsScreen extends StatefulWidget {
  const QuoteRequestsScreen({super.key, required this.farm});

  final Farm farm;

  @override
  State<QuoteRequestsScreen> createState() => _QuoteRequestsScreenState();
}

class _QuoteRequestsScreenState extends State<QuoteRequestsScreen> {
  late final PagedList<QuoteRequest> _requests = PagedList(_fetch);
  final _scroll = ScrollController();

  Future<Paged<QuoteRequest>> _fetch(int page) async {
    return Paged.fromJson(
      await context.read<ApiClient>().get('/farms/${widget.farm.id}/quote-requests', query: {'page': page}) as Map<String, dynamic>,
      QuoteRequest.fromJson,
    );
  }

  @override
  void initState() {
    super.initState();
    _requests.refresh();
    _scroll.addListener(() {
      if (_scroll.position.extentAfter < 400) _requests.loadMore();
    });
  }

  @override
  void dispose() {
    _requests.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _open(QuoteRequest request) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => QuoteOfferScreen(request: request, farm: widget.farm)));
    if (mounted) _requests.refresh();
  }

  @override
  Widget build(BuildContext context) {
    final province = widget.farm.location?.province;

    return Scaffold(
      appBar: AppBar(title: const Text('Buyers asking for prices')),
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
                    title: "We couldn't load the requests",
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
                    title: 'No requests right now',
                    message: 'When a buyer in ${province ?? 'your province'} asks for a price on something you sell, '
                        "it shows up here and you'll get a notification.",
                  ),
                ],
              );
            }
            return ListView.separated(
              controller: _scroll,
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
              itemCount: _requests.items.length,
              separatorBuilder: (_, _) => const SizedBox(height: 10),
              itemBuilder: (context, index) => _RequestTile(request: _requests.items[index], onTap: () => _open(_requests.items[index])),
            );
          },
        ),
      ),
    );
  }
}

class _RequestTile extends StatelessWidget {
  const _RequestTile({required this.request, required this.onTap});

  final QuoteRequest request;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final offer = request.offer;
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
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(child: Text(quoteHeadline(request), style: const TextStyle(fontWeight: FontWeight.w800, height: 1.35))),
                  const SizedBox(width: 8),
                  StatusChip(quoteStatusFor(request)),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                '${quoteSchedule(request)} · ${request.district}',
                style: const TextStyle(fontSize: 13, color: Brand.mutedText, height: 1.4),
              ),
              const SizedBox(height: 2),
              Text(
                [
                  ?request.buyerName,
                  _gettingIt[request.fulfilment] ?? request.fulfilment,
                  if (request.isOpen && request.closesAt != null) 'answer by ${_day(request.closesAt!)}',
                ].join(' · '),
                style: const TextStyle(fontSize: 12, color: Brand.mutedText),
              ),
              if (offer != null && offer.status != 'withdrawn') ...[
                const SizedBox(height: 8),
                Text(
                  'You offered ${formatMoney(offer.unitPrice, offer.currency)} per ${offer.unit.name} · ${formatMoney(offer.total, offer.currency)} in all',
                  style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: Brand.green),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// One request: what the buyer wants, and the farm's offer on it (send, change or withdraw).
class QuoteOfferScreen extends StatefulWidget {
  const QuoteOfferScreen({super.key, required this.request, required this.farm});

  final QuoteRequest request;
  final Farm farm;

  @override
  State<QuoteOfferScreen> createState() => _QuoteOfferScreenState();
}

class _QuoteOfferScreenState extends State<QuoteOfferScreen> {
  late QuoteOffer? _offer = widget.request.offer;
  late final _price = TextEditingController(text: _offer?.unitPrice);
  late final _quantity = TextEditingController(text: _offer?.quantity);
  late final _deliveryFee = TextEditingController(text: _offer?.fulfilment == 'delivery' ? _offer?.deliveryFee : null);
  late final _message = TextEditingController(text: _offer?.message);

  /// The farm's live listings, to choose which one the offer is for.
  List<ManagedListing>? _listings;
  Object? _listingsError;
  late String? _variantId = _offer?.variantId;
  late String _fulfilment;
  late String? _validUntil = _offer?.validUntil;
  late bool _editing = _offer == null || _offer!.status == 'withdrawn';
  bool _sending = false;
  ApiException? _error;
  Map<String, String> _problems = const {};

  QuoteRequest get _request => widget.request;
  Farm get _farm => widget.farm;

  String? _problem(String field) => _problems[field] ?? _error?.field(field);

  void _fixed(String field) {
    if (_problems.containsKey(field) || _error != null) {
      setState(() {
        _problems = {..._problems}..remove(field);
        _error = null;
      });
    }
  }

  @override
  void initState() {
    super.initState();
    _fulfilment = _offer?.fulfilment ?? (_request.fulfilment != 'either' ? _request.fulfilment : (_farm.offersDelivery ? 'delivery' : 'pickup'));
    if (_fulfilment == 'delivery' && _deliveryFee.text.isEmpty) _deliveryFee.text = _farm.deliveryFee;
    _loadListings();
  }

  @override
  void dispose() {
    for (final controller in [_price, _quantity, _deliveryFee, _message]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _loadListings() async {
    try {
      final response = await context.read<ApiClient>().get('/farms/${_farm.id}/products', query: {'status': 'active', 'per_page': 100}) as Map<String, dynamic>;
      final listings = Paged.fromJson(response, ManagedListing.fromJson).items.where((listing) => listing.variants.any((variant) => variant.isActive)).toList();
      if (!mounted) return;
      setState(() {
        _listings = listings;
        _listingsError = null;
      });
      // With no offer yet, start from the listing in the requested produce.
      if (_variantId == null && listings.isNotEmpty) {
        final match = listings.where((listing) => listing.categoryId == _request.categoryId).firstOrNull ?? listings.first;
        _choose(match, match.variants.firstWhere((variant) => variant.isActive));
      }
    } catch (error) {
      if (mounted) setState(() => _listingsError = error);
    }
  }

  (ManagedListing, ManagedVariant)? get _choice {
    for (final listing in _listings ?? const <ManagedListing>[]) {
      for (final variant in listing.variants) {
        if (variant.id == _variantId) return (listing, variant);
      }
    }
    return null;
  }

  /// Picks a selling option and fills in its price, and the buyer's quantity when it is in the same unit.
  void _choose(ManagedListing listing, ManagedVariant variant) {
    setState(() {
      _variantId = variant.id;
      _price.text = variant.price;
      _quantity.text = variant.unit.code == _request.unit.code ? _request.quantity : '';
    });
    _fixed('product_variant_id');
  }

  int? get _totalMinor {
    final price = toMinor(_price.text.trim());
    final quantity = toMilli(_quantity.text.trim());
    if (price == null || quantity == null) return null;
    final delivery = _fulfilment == 'delivery' ? (toMinor(_deliveryFee.text.trim().isEmpty ? '0' : _deliveryFee.text.trim()) ?? 0) : 0;
    return (price * quantity + 500) ~/ 1000 + delivery;
  }

  Future<void> _pickValidUntil() async {
    final now = DateTime.now();
    final first = _request.neededBy.isAfter(now) ? _request.neededBy : now;
    final picked = await showDatePicker(
      context: context,
      initialDate: _validUntil == null ? first.add(const Duration(days: 28)) : DateTime.parse(_validUntil!),
      firstDate: DateTime(now.year, now.month, now.day),
      lastDate: now.add(const Duration(days: 365)),
    );
    if (picked != null) setState(() => _validUntil = picked.toIso8601String().substring(0, 10));
  }

  Future<void> _send() async {
    final problems = {
      if (_choice == null) 'product_variant_id': 'Choose which of your listings this offer is for.',
      if ((toMinor(_price.text.trim()) ?? 0) <= 0) 'unit_price': 'Enter your price, e.g. 1.10',
      if ((toMilli(_quantity.text.trim()) ?? 0) <= 0) 'quantity': 'Enter how much you can supply.',
      if (_fulfilment == 'delivery' && _deliveryFee.text.trim().isNotEmpty && toMinor(_deliveryFee.text.trim()) == null)
        'delivery_fee': 'Enter a fee, or 0 for free delivery.',
    };
    setState(() => _problems = problems);
    if (problems.isNotEmpty) {
      showMessage(context, problems.values.first);
      return;
    }

    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final response = await context.read<ApiClient>().post('/quote-requests/${_request.id}/offers', {
        'farm_id': _farm.id,
        'product_variant_id': _variantId,
        'unit_price': _price.text.trim(),
        'quantity': _quantity.text.trim(),
        'fulfilment': _fulfilment,
        if (_fulfilment == 'delivery') 'delivery_fee': _deliveryFee.text.trim().isEmpty ? '0' : _deliveryFee.text.trim(),
        if (_message.text.trim().isNotEmpty) 'message': _message.text.trim(),
        if (_request.repeats && _validUntil != null) 'valid_until': _validUntil,
      }) as Map<String, dynamic>;
      if (!mounted) return;
      final wasSent = _offer != null && _offer!.isPending;
      setState(() {
        _offer = QuoteOffer.fromJson(response['data'] as Map<String, dynamic>);
        _editing = false;
      });
      showMessage(context, wasSent ? 'Offer changed. The buyer sees your new price.' : "Offer sent. We've told the buyer.");
    } on ApiException catch (error) {
      setState(() => _error = error);
      if (error.fieldErrors.isEmpty && mounted) showMessage(context, error.message);
    } catch (error) {
      setState(() => _error = ApiException(0, errorMessage(error)));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _withdraw() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Withdraw your offer?'),
        content: const Text("The buyer can no longer accept it. You can send a new one while they're still taking offers."),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Not now')),
          TextButton(onPressed: () => Navigator.pop(context, true), child: const Text('Withdraw')),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    try {
      final response = await context.read<ApiClient>().delete('/quote-offers/${_offer!.id}') as Map<String, dynamic>;
      if (!mounted) return;
      setState(() => _offer = QuoteOffer.fromJson(response['data'] as Map<String, dynamic>));
      showMessage(context, 'Offer withdrawn.');
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    }
  }

  @override
  Widget build(BuildContext context) {
    final offer = _offer;
    final canOffer = _request.isOpen && (offer == null || offer.isPending || offer.status == 'withdrawn');

    return Scaffold(
      appBar: AppBar(title: Text(_request.buyerName == null ? 'Request for a price' : 'Request from ${_request.buyerName}')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
        children: [
          _RequestCard(request: _request, offer: _offer),
          const SizedBox(height: 18),
          if (offer != null && !_editing && offer.status != 'withdrawn') ...[
            _OfferCard(offer: offer, request: _request),
            const SizedBox(height: 12),
            if (offer.isPending && _request.isOpen)
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () => setState(() => _editing = true),
                      icon: const Icon(LucideIcons.pencil, size: 16),
                      label: const Text('Change offer'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: TextButton(
                      onPressed: _withdraw,
                      style: TextButton.styleFrom(foregroundColor: Brand.danger),
                      child: const Text('Withdraw'),
                    ),
                  ),
                ],
              ),
          ] else if (!canOffer)
            const _Note(icon: LucideIcons.circleSlash, text: 'This request is no longer taking offers.')
          else if (!_farm.verified)
            const _Note(icon: LucideIcons.shieldCheck, text: 'Verify your farm to send offers. Buyers only take offers from verified farms.', warm: true)
          else
            ..._form(),
        ],
      ),
    );
  }

  List<Widget> _form() {
    final choice = _choice;
    final unit = choice?.$2.unit;
    final listings = _listings;
    final total = _totalMinor;
    final currency = choice?.$1.currency ?? 'USD';

    return [
      Text(_offer?.isPending == true ? 'Change your offer' : 'Your offer', style: Brand.display(size: 17)),
      const SizedBox(height: 14),
      if (listings == null && _listingsError == null)
        const Padding(padding: EdgeInsets.all(20), child: Center(child: CircularProgressIndicator()))
      else if (listings == null)
        _Note(icon: LucideIcons.wifiOff, text: "We couldn't load your listings. ${errorMessage(_listingsError!)}")
      else if (listings.isEmpty)
        const _Note(icon: LucideIcons.package, text: 'You have no listings on sale. Publish the produce first, then send your offer.', warm: true)
      else ...[
        DropdownButtonFormField<String>(
          initialValue: _variantId,
          isExpanded: true,
          decoration: InputDecoration(labelText: 'Which of your listings?', errorText: _problem('product_variant_id')),
          items: [
            for (final listing in listings)
              for (final variant in listing.variants.where((variant) => variant.isActive))
                DropdownMenuItem(
                  value: variant.id,
                  child: Text(
                    '${listing.title} · per ${variant.unit.name} · ${formatMoney(variant.price, listing.currency)}${variant.label == null ? '' : ' (${variant.label})'}',
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
          ],
          onChanged: (id) {
            for (final listing in listings) {
              for (final variant in listing.variants) {
                if (variant.id == id) _choose(listing, variant);
              }
            }
          },
        ),
        const SizedBox(height: 14),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: TextField(
                controller: _price,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: _decimal,
                decoration: InputDecoration(
                  labelText: unit == null ? 'Your price' : 'Price per ${unit.name}',
                  prefixText: r'$ ',
                  errorText: _problem('unit_price'),
                  errorMaxLines: 2,
                ),
                onChanged: (_) {
                  _fixed('unit_price');
                  setState(() {});
                },
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: TextField(
                controller: _quantity,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: _decimal,
                decoration: InputDecoration(labelText: 'Quantity', suffixText: unit?.pluralName, errorText: _problem('quantity'), errorMaxLines: 2),
                onChanged: (_) {
                  _fixed('quantity');
                  setState(() {});
                },
              ),
            ),
          ],
        ),
        const SizedBox(height: 6),
        Text(
          unit == null || unit.code == _request.unit.code
              ? 'The buyer asked for ${formatQuantity(_request.quantity, _request.unit)}.'
              : 'The buyer asked for ${formatQuantity(_request.quantity, _request.unit)}. Enter how many ${unit.pluralName} you will supply.',
          style: const TextStyle(fontSize: 12, color: Brand.mutedText),
        ),
        const SizedBox(height: 18),
        const Text('Getting it to the buyer', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        if (_request.fulfilment == 'either')
          Wrap(
            spacing: 8,
            children: [
              for (final (value, label) in const [('pickup', 'Buyer collects'), ('delivery', 'You deliver')])
                ChoiceChip(
                  label: Text(label),
                  selected: _fulfilment == value,
                  onSelected: (_) => setState(() {
                    _fulfilment = value;
                    if (value == 'delivery' && _deliveryFee.text.isEmpty) _deliveryFee.text = _farm.deliveryFee;
                  }),
                  selectedColor: Brand.mint,
                  backgroundColor: Colors.white,
                  showCheckmark: false,
                  labelStyle: TextStyle(fontFamily: 'Manrope', fontWeight: FontWeight.w700, color: _fulfilment == value ? Brand.green : Brand.ink),
                  side: BorderSide(color: _fulfilment == value ? Brand.green : Brand.border),
                ),
            ],
          )
        else
          Text(
            _request.fulfilment == 'delivery' ? 'The buyer wants it delivered to them.' : 'The buyer will collect it from your farm.',
            style: const TextStyle(fontSize: 13, color: Brand.mutedText),
          ),
        if (_fulfilment == 'delivery') ...[
          const SizedBox(height: 12),
          TextField(
            controller: _deliveryFee,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            inputFormatters: _decimal,
            decoration: InputDecoration(labelText: 'Delivery fee per order', prefixText: r'$ ', hintText: '0 for free delivery', errorText: _problem('delivery_fee')),
            onChanged: (_) {
              _fixed('delivery_fee');
              setState(() {});
            },
          ),
        ],
        if (_request.repeats) ...[
          const SizedBox(height: 18),
          const Text('How long your price holds', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          Text(
            'The buyer can order ${_request.frequencyLabel} at this price until then.',
            style: const TextStyle(fontSize: 12, color: Brand.mutedText),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _pickValidUntil,
                  icon: const Icon(LucideIcons.calendar, size: 16),
                  label: Text(_validUntil == null ? 'No end date' : 'Until ${_day(DateTime.parse(_validUntil!))}'),
                ),
              ),
              if (_validUntil != null) ...[
                const SizedBox(width: 8),
                TextButton(onPressed: () => setState(() => _validUntil = null), child: const Text('No end date')),
              ],
            ],
          ),
          if (_problem('valid_until') != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(_problem('valid_until')!, style: const TextStyle(color: Brand.danger, fontSize: 12.5)),
            ),
        ],
        const SizedBox(height: 18),
        TextField(
          controller: _message,
          minLines: 2,
          maxLines: 4,
          maxLength: 1000,
          textCapitalization: TextCapitalization.sentences,
          decoration: const InputDecoration(labelText: 'Message to the buyer (optional)', hintText: 'e.g. Grade A, picked the morning we deliver.'),
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            const Text('Each order', style: TextStyle(fontWeight: FontWeight.w700)),
            const Spacer(),
            Text(total == null ? '–' : formatMinor(total, currency), style: Brand.display(size: 20, color: Brand.green)),
          ],
        ),
        const SizedBox(height: 4),
        const Text(
          'MundaLinq keeps its usual fee when the order is paid. The buyer confirms with you before paying.',
          style: TextStyle(fontSize: 12, color: Brand.mutedText),
        ),
        if (_error != null && _error!.fieldErrors.isNotEmpty && _problems.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 10),
            child: Text(_error!.detail, style: const TextStyle(color: Brand.danger)),
          ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _sending ? null : _send,
          child: _sending
              ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : Text(_offer?.isPending == true ? 'Update offer' : 'Send offer'),
        ),
        if (_offer?.isPending == true)
          TextButton(onPressed: () => setState(() => _editing = false), child: const Text('Keep my current offer')),
      ],
    ];
  }
}

class _RequestCard extends StatelessWidget {
  const _RequestCard({required this.request, this.offer});

  final QuoteRequest request;
  final QuoteOffer? offer;

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
              StatusChip(quoteStatusFor(request, offer: offer)),
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
  const _OfferCard({required this.offer, required this.request});

  final QuoteOffer offer;
  final QuoteRequest request;

  @override
  Widget build(BuildContext context) {
    String money(String amount) => formatMoney(amount, offer.currency);
    final (title, note, tone) = switch (offer.status) {
      'accepted' => ('The buyer accepted your offer', 'The order is in your Orders tab. Confirm it there so the buyer can pay.', StatusTone.active),
      'declined' => ('Not chosen this time', 'The buyer went with another offer or stopped looking.', StatusTone.done),
      _ when !request.isOpen => ('Request closed', 'The buyer did not choose an offer in time.', StatusTone.done),
      _ => ('Your offer · waiting for the buyer', 'You can change your offer until the buyer decides.', StatusTone.waiting),
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

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: tone == StatusTone.waiting ? Brand.harvest.withValues(alpha: 0.25) : tone.background,
        borderRadius: BorderRadius.circular(Brand.radius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: TextStyle(fontWeight: FontWeight.w800, color: tone.foreground)),
          const SizedBox(height: 2),
          Text(note, style: const TextStyle(fontSize: 12.5, height: 1.4)),
          const SizedBox(height: 6),
          line('${formatQuantity(offer.quantity, offer.unit)} of ${offer.title}${offer.label == null ? '' : ' (${offer.label})'}', '${money(offer.unitPrice)} / ${offer.unit.name}'),
          line(offer.fulfilment == 'delivery' ? 'Delivery' : 'Buyer collects', offer.fulfilment == 'delivery' ? money(offer.deliveryFee) : 'Free'),
          line('Each order', money(offer.total), strong: true),
          if (offer.validUntil != null) line('Price holds until', _day(DateTime.parse(offer.validUntil!))),
          if (offer.message != null) ...[
            const SizedBox(height: 8),
            Text('“${offer.message}”', style: const TextStyle(fontSize: 13, fontStyle: FontStyle.italic)),
          ],
        ],
      ),
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.icon, required this.text, this.warm = false});

  final IconData icon;
  final String text;
  final bool warm;

  @override
  Widget build(BuildContext context) {
    final color = warm ? Brand.harvestText : Brand.mutedText;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: warm ? Brand.harvest.withValues(alpha: 0.25) : Brand.muted, borderRadius: BorderRadius.circular(Brand.radius)),
      child: Row(
        children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 10),
          Expanded(child: Text(text, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: color, height: 1.4))),
        ],
      ),
    );
  }
}
