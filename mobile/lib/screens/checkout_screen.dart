import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../state/cart.dart';
import '../state/session.dart';
import '../theme.dart';
import '../util/format.dart';
import '../widgets/common.dart';
import 'address_form_screen.dart';
import 'auth_screen.dart';
import 'order_screen.dart';

/// Pickup or delivery for each farm, a delivery address if needed, then the order. Every change asks the
/// server for a fresh quote: it alone prices delivery and checks stock.
class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key});

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  final _note = TextEditingController();
  final Map<String, String> _methods = {};
  List<Address>? _addresses;
  String? _addressId;
  Quote? _quote;
  Object? _error;
  bool _quoting = false;
  bool _placing = false;
  int _quoteRequest = 0;

  ApiClient get _api => context.read<ApiClient>();

  bool get _needsAddress => _methods.values.contains('delivery');

  @override
  void initState() {
    super.initState();
    _start();
  }

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _start() async {
    try {
      final response = await _api.get('/me/addresses') as Map<String, dynamic>;
      final addresses = (response['data'] as List).cast<Map<String, dynamic>>().map(Address.fromJson).toList();
      if (!mounted) return;
      setState(() {
        _addresses = addresses;
        _addressId = (addresses.where((address) => address.isDefault).firstOrNull ?? addresses.firstOrNull)?.id;
      });
    } catch (error) {
      if (mounted) setState(() => _error = error);
      return;
    }
    await _requote();
  }

  Map<String, Object?> get _body => {
        if (_methods.isNotEmpty) 'fulfilment': _methods,
        if (_needsAddress && _addressId != null) 'address_id': _addressId,
      };

  Future<void> _requote() async {
    final request = ++_quoteRequest;
    setState(() {
      _quoting = true;
      _error = null;
    });
    try {
      final response = await _api.post('/checkout/quote', _body) as Map<String, dynamic>;
      final quote = Quote.fromJson(response['data'] as Map<String, dynamic>);
      if (!mounted || request != _quoteRequest) return;
      setState(() {
        _quote = quote;
        // Keep the server's choice for any farm the buyer hasn't picked for yet.
        for (final seller in quote.sellers) {
          if (seller.fulfilmentMethod != null) _methods.putIfAbsent(seller.farmId, () => seller.fulfilmentMethod!);
        }
      });
    } catch (error) {
      if (mounted && request == _quoteRequest) setState(() => _error = error);
    } finally {
      if (mounted && request == _quoteRequest) setState(() => _quoting = false);
    }
  }

  void _choose(String farmId, String method) {
    if (_methods[farmId] == method) return;
    setState(() => _methods[farmId] = method);
    _requote();
  }

  Future<void> _addAddress() async {
    final address = await Navigator.of(context).push<Address>(MaterialPageRoute(builder: (_) => const AddressFormScreen()));
    if (address == null || !mounted) return;
    setState(() {
      _addresses = [...?_addresses, address];
      _addressId = address.id;
    });
    _requote();
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
    final quote = _quote;
    if (quote == null) return;
    setState(() => _placing = true);
    try {
      final response = await _api.post('/orders', {
        ..._body,
        'expected_total': quote.total,
        if (_note.text.trim().isNotEmpty) 'note': _note.text.trim(),
      }) as Map<String, dynamic>;
      final order = Order.fromJson(response['data'] as Map<String, dynamic>);
      if (!mounted) return;
      // The server took the ordered items out of the basket.
      context.read<CartController>().load();
      Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => OrderScreen(orderId: order.id, justPlaced: true)));
    } on ApiException catch (error) {
      if (!mounted) return;
      if (error.status == 409) {
        await _requote();
        if (mounted) showMessage(context, 'Prices or delivery charges changed. Check the new total and place your order again.');
      } else {
        showMessage(context, error.detail);
        if (error.status == 422) _requote();
      }
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    } finally {
      if (mounted) setState(() => _placing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<Session>().user;
    final quote = _quote;

    Widget body;
    if (quote == null && _error != null) {
      body = Center(
        child: EmptyState(
          icon: LucideIcons.wifiOff,
          title: "We couldn't price your order",
          message: errorMessage(_error!),
          actions: [FilledButton(onPressed: _start, child: const Text('Try again'))],
        ),
      );
    } else if (quote == null) {
      body = const Center(child: CircularProgressIndicator());
    } else if (quote.sellers.isEmpty) {
      body = const Center(
        child: EmptyState(icon: LucideIcons.shoppingBasket, title: 'Your basket is empty', message: 'Add something from the market first.'),
      );
    } else {
      body = ListView(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
        children: [
          if (user != null && !user.phoneVerified)
            _Notice(
              icon: LucideIcons.phone,
              text: 'Confirm your phone number to place orders. Farms and drivers use it to reach you.',
              action: TextButton(onPressed: _verifyPhone, child: const Text('Confirm number')),
            ),
          for (final seller in quote.sellers)
            _SellerCard(
              seller: seller,
              currency: quote.currency,
              method: _methods[seller.farmId] ?? seller.fulfilmentMethod,
              onChoose: (method) => _choose(seller.farmId, method),
            ),
          if (_needsAddress) ...[
            const SizedBox(height: 8),
            Text('Deliver to', style: Brand.display(size: 15)),
            const SizedBox(height: 10),
            if (_addresses?.isEmpty ?? true)
              const Text('Add the address the farm should deliver to.', style: TextStyle(color: Brand.mutedText))
            else
              RadioGroup<String>(
                groupValue: _addressId,
                onChanged: (id) {
                  setState(() => _addressId = id);
                  _requote();
                },
                child: Column(children: [for (final address in _addresses!) _AddressOption(address: address)]),
              ),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: _addAddress,
                icon: const Icon(LucideIcons.plus, size: 16),
                label: const Text('Add a new address'),
              ),
            ),
          ],
          const SizedBox(height: 12),
          TextField(
            controller: _note,
            maxLength: 500,
            maxLines: 2,
            minLines: 1,
            decoration: const InputDecoration(labelText: 'Note for the farms (optional)', hintText: 'e.g. call when you arrive'),
          ),
          for (final problem in quote.problems.where((problem) => problem.farmId == null))
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(problem.message, style: const TextStyle(color: Brand.danger, fontSize: 13)),
            ),
          const SizedBox(height: 12),
          _Totals(quote: quote),
          const SizedBox(height: 12),
          const _Notice(
            icon: LucideIcons.clock,
            text: 'Nothing is charged yet. Farms confirm they have everything, usually within a few hours, then you pay by mobile money.',
          ),
        ],
      );
    }

    final canPlace = quote != null && quote.canPlaceOrder && !_quoting && (user?.phoneVerified ?? false);

    return Scaffold(
      appBar: AppBar(title: const Text('Checkout')),
      body: body,
      bottomNavigationBar: quote == null || quote.sellers.isEmpty
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
                child: FilledButton(
                  onPressed: canPlace && !_placing ? _place : null,
                  child: _placing || _quoting
                      ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                      : Text('Place order · ${formatMoney(quote.total, quote.currency)}'),
                ),
              ),
            ),
    );
  }
}

class _SellerCard extends StatelessWidget {
  const _SellerCard({required this.seller, required this.currency, required this.method, required this.onChoose});

  final CartSeller seller;
  final String currency;
  final String? method;
  final ValueChanged<String> onChoose;

  @override
  Widget build(BuildContext context) {
    final delivery = method == 'delivery';

    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(border: Border.all(color: Brand.border), borderRadius: BorderRadius.circular(Brand.radius)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(LucideIcons.store, size: 16, color: Brand.green),
              const SizedBox(width: 6),
              Expanded(child: Text(seller.farmName, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: Brand.green))),
            ],
          ),
          const SizedBox(height: 8),
          for (final line in seller.items)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      '${formatQuantity(line.quantity, line.unit)} · ${line.title}',
                      style: const TextStyle(fontSize: 13),
                    ),
                  ),
                  Text(formatMoney(line.lineTotal, currency), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                ],
              ),
            ),
          const SizedBox(height: 12),
          Row(
            children: [
              if (seller.availableMethods.contains('pickup'))
                Expanded(
                  child: _MethodChoice(
                    icon: LucideIcons.store,
                    label: 'Collect at the farm',
                    detail: 'Free',
                    selected: method == 'pickup',
                    onTap: () => onChoose('pickup'),
                  ),
                ),
              if (seller.availableMethods.length > 1) const SizedBox(width: 8),
              if (seller.availableMethods.contains('delivery'))
                Expanded(
                  child: _MethodChoice(
                    icon: LucideIcons.truck,
                    label: 'Delivery',
                    detail: delivery ? formatMoney(seller.deliveryFee, currency) : 'Fee shown when chosen',
                    selected: delivery,
                    onTap: () => onChoose('delivery'),
                  ),
                ),
            ],
          ),
          for (final problem in seller.problems)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(problem.message, style: const TextStyle(color: Brand.danger, fontSize: 12.5)),
            ),
          for (final line in seller.items.where((line) => line.problem != null))
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text('${line.title}: ${line.problem!.message}', style: const TextStyle(color: Brand.danger, fontSize: 12.5)),
            ),
        ],
      ),
    );
  }
}

class _MethodChoice extends StatelessWidget {
  const _MethodChoice({required this.icon, required this.label, required this.detail, required this.selected, required this.onTap});

  final IconData icon;
  final String label;
  final String detail;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected ? Brand.mint : Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Brand.radius),
        side: BorderSide(color: selected ? Brand.green : Brand.border),
      ),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(Brand.radius),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, size: 17, color: selected ? Brand.green : Brand.mutedText),
              const SizedBox(height: 6),
              Text(label, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: selected ? Brand.green : Brand.ink)),
              Text(detail, style: const TextStyle(fontSize: 11.5, color: Brand.mutedText)),
            ],
          ),
        ),
      ),
    );
  }
}

class _AddressOption extends StatelessWidget {
  const _AddressOption({required this.address});

  final Address address;

  @override
  Widget build(BuildContext context) {
    final selected = RadioGroup.maybeOf<String>(context)?.groupValue == address.id;
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: selected ? Brand.mint : Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Brand.radius),
          side: BorderSide(color: selected ? Brand.green : Brand.border),
        ),
        child: RadioListTile<String>(
          value: address.id,
          title: Text(address.label ?? address.recipientName, style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: Text(
            '${address.addressLine}, ${address.district}\n${address.recipientName} · ${formatPhone(address.phone)}',
            style: const TextStyle(fontSize: 12.5, color: Brand.mutedText, height: 1.4),
          ),
          isThreeLine: true,
        ),
      ),
    );
  }
}

class _Totals extends StatelessWidget {
  const _Totals({required this.quote});

  final Quote quote;

  @override
  Widget build(BuildContext context) {
    Widget row(String label, String amount, {bool strong = false}) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 3),
          child: Row(
            children: [
              Expanded(child: Text(label, style: TextStyle(fontSize: strong ? 16 : 14, fontWeight: strong ? FontWeight.w800 : FontWeight.w500))),
              Text(formatMoney(amount, quote.currency), style: TextStyle(fontSize: strong ? 18 : 14, fontWeight: strong ? FontWeight.w800 : FontWeight.w600)),
            ],
          ),
        );

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: Brand.muted, borderRadius: BorderRadius.circular(Brand.radius)),
      child: Column(
        children: [
          row('Produce', quote.subtotal),
          row('Delivery', quote.deliveryFee),
          const Divider(height: 18),
          row('Total', quote.total, strong: true),
        ],
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.icon, required this.text, this.action});

  final IconData icon;
  final String text;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
      decoration: BoxDecoration(color: Brand.mint, borderRadius: BorderRadius.circular(Brand.radius)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, size: 17, color: Brand.green),
              const SizedBox(width: 10),
              Expanded(child: Text(text, style: const TextStyle(fontSize: 12.5, height: 1.5, color: Brand.green, fontWeight: FontWeight.w600))),
            ],
          ),
          ?action,
        ],
      ),
    );
  }
}
