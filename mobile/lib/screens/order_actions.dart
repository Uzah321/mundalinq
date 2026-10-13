import 'dart:async';

import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../config.dart';
import '../state/session.dart';
import '../theme.dart';
import '../util/format.dart';

Future<bool> _confirm(BuildContext context, {required String title, required String message, required String action}) async {
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

Future<void> cancelOrder(BuildContext context, Order order) async {
  final api = context.read<ApiClient>();
  if (!await _confirm(context, title: 'Cancel this order?', message: 'The farms are told and the produce goes back on sale.', action: 'Cancel order')) {
    return;
  }
  await api.post('/orders/${order.id}/cancel');
}

Future<void> confirmReceipt(BuildContext context, SellerOrder seller) async {
  final api = context.read<ApiClient>();
  if (!await _confirm(
    context,
    title: 'Everything arrived well?',
    message: 'This completes the sale and pays ${seller.farmName}. You can no longer report a problem afterwards.',
    action: 'Yes, all good',
  )) {
    return;
  }
  await api.post('/seller-orders/${seller.id}/confirm-receipt');
}

const _problemReasons = {
  'missing_items': 'Something is missing',
  'damaged': 'It arrived damaged',
  'poor_quality': 'The quality is poor',
  'wrong_items': 'I got the wrong items',
  'other': 'Something else',
};

Future<void> reportProblem(BuildContext context, SellerOrder seller) async {
  final api = context.read<ApiClient>();
  final sent = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (_) => _ProblemSheet(seller: seller, api: api),
  );
  if (sent == true && context.mounted) {
    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Report sent. MundaLinq support will be in touch.')));
  }
}

class _ProblemSheet extends StatefulWidget {
  const _ProblemSheet({required this.seller, required this.api});

  final SellerOrder seller;
  final ApiClient api;

  @override
  State<_ProblemSheet> createState() => _ProblemSheetState();
}

class _ProblemSheetState extends State<_ProblemSheet> {
  final _description = TextEditingController();
  String _reason = 'missing_items';
  String? _error;
  bool _sending = false;

  @override
  void dispose() {
    _description.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      await widget.api.post('/seller-orders/${widget.seller.id}/dispute', {'reason': _reason, 'description': _description.text.trim()});
      if (mounted) Navigator.pop(context, true);
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
          Text('Report a problem', style: Brand.display(size: 19)),
          const SizedBox(height: 6),
          Text(
            "${widget.seller.farmName} isn't paid while support looks into it. Tell us what went wrong.",
            style: const TextStyle(color: Brand.mutedText, height: 1.5),
          ),
          RadioGroup<String>(
            groupValue: _reason,
            onChanged: (reason) => setState(() => _reason = reason ?? _reason),
            child: Column(
              children: [
                for (final entry in _problemReasons.entries)
                  RadioListTile<String>(value: entry.key, title: Text(entry.value), dense: true, contentPadding: EdgeInsets.zero),
              ],
            ),
          ),
          TextField(
            controller: _description,
            minLines: 3,
            maxLines: 5,
            maxLength: 2000,
            decoration: InputDecoration(hintText: 'What happened? At least a sentence, please.', errorText: _error, errorMaxLines: 3),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 8),
          FilledButton(
            onPressed: _sending || _description.text.trim().length < 10 ? null : _send,
            child: _sending
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Send report'),
          ),
        ],
      ),
    );
  }
}

Future<void> reviewFarm(BuildContext context, SellerOrder seller) async {
  final api = context.read<ApiClient>();
  await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (_) => _ReviewSheet(seller: seller, api: api),
  );
}

class _ReviewSheet extends StatefulWidget {
  const _ReviewSheet({required this.seller, required this.api});

  final SellerOrder seller;
  final ApiClient api;

  @override
  State<_ReviewSheet> createState() => _ReviewSheetState();
}

class _ReviewSheetState extends State<_ReviewSheet> {
  final _comment = TextEditingController();
  int _rating = 0;
  String? _error;
  bool _sending = false;

  @override
  void dispose() {
    _comment.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      await widget.api.post('/seller-orders/${widget.seller.id}/review', {
        'rating': _rating,
        if (_comment.text.trim().isNotEmpty) 'comment': _comment.text.trim(),
      });
      if (mounted) Navigator.pop(context, true);
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
          Text('How was ${widget.seller.farmName}?', style: Brand.display(size: 19)),
          const SizedBox(height: 12),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              for (var star = 1; star <= 5; star++)
                IconButton(
                  onPressed: () => setState(() => _rating = star),
                  tooltip: '$star star${star == 1 ? '' : 's'}',
                  iconSize: 38,
                  icon: Icon(Icons.star_rounded, color: star <= _rating ? Brand.harvest : Brand.border),
                ),
            ],
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _comment,
            minLines: 2,
            maxLines: 4,
            maxLength: 1000,
            decoration: InputDecoration(hintText: 'Anything other buyers should know? (optional)', errorText: _error, errorMaxLines: 3),
          ),
          const SizedBox(height: 8),
          FilledButton(
            onPressed: _sending || _rating == 0 ? null : _send,
            child: _sending
                ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Text('Post review'),
          ),
        ],
      ),
    );
  }
}

/// Mobile money: send the prompt to the payer's phone, then check until they approve or it fails.
/// True once the payment has succeeded.
Future<bool> showPaySheet(BuildContext context, Order order) async {
  final api = context.read<ApiClient>();
  final phone = context.read<Session>().user?.phone ?? '';
  final paid = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    isDismissible: true,
    builder: (_) => _PaySheet(order: order, api: api, defaultPhone: phone),
  );
  return paid == true;
}

class _PaySheet extends StatefulWidget {
  const _PaySheet({required this.order, required this.api, required this.defaultPhone});

  final Order order;
  final ApiClient api;
  final String defaultPhone;

  @override
  State<_PaySheet> createState() => _PaySheetState();
}

class _PaySheetState extends State<_PaySheet> {
  late final _phone = TextEditingController(text: widget.defaultPhone);
  late Payment? _payment = widget.order.latestPayment?.pending == true ? widget.order.latestPayment : null;
  String? _error;
  bool _sending = false;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    if (_payment != null) _startPolling();
  }

  @override
  void dispose() {
    _poll?.cancel();
    _phone.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final response = await widget.api.post('/payments/initiate', {
        'order_id': widget.order.id,
        'method': 'mobile_money',
        'phone': _phone.text.trim(),
      }) as Map<String, dynamic>;
      setState(() => _payment = Payment.fromJson(response['data'] as Map<String, dynamic>));
      _startPolling();
    } catch (error) {
      setState(() => _error = errorMessage(error));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  void _startPolling() {
    _poll?.cancel();
    // Each check asks the provider, so every few seconds is plenty while the buyer finds their phone.
    _poll = Timer.periodic(const Duration(seconds: 4), (_) => _check());
    _check();
  }

  Future<void> _check() async {
    final payment = _payment;
    if (payment == null) return;
    try {
      final response = await widget.api.get('/payments/${payment.id}/status') as Map<String, dynamic>;
      final latest = Payment.fromJson(response['data'] as Map<String, dynamic>);
      if (!mounted) return;
      setState(() => _payment = latest);
      if (latest.status == 'succeeded') {
        _poll?.cancel();
        await Future<void>.delayed(const Duration(milliseconds: 900));
        if (mounted) Navigator.pop(context, true);
      } else if (!latest.pending) {
        _poll?.cancel();
      }
    } catch (_) {
      // A dropped check is retried on the next tick.
    }
  }

  @override
  Widget build(BuildContext context) {
    final order = widget.order;
    final payment = _payment;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        children: [
          Text('Pay ${formatMoney(order.total, order.currency)}', style: Brand.display(size: 21)),
          const SizedBox(height: 4),
          Text('Order ${order.number}', style: const TextStyle(color: Brand.mutedText)),
          const SizedBox(height: 18),
          if (payment == null || payment.status == 'failed' || payment.status == 'expired') ...[
            if (payment != null)
              Container(
                margin: const EdgeInsets.only(bottom: 14),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: const Color(0xFFFDE8E8), borderRadius: BorderRadius.circular(Brand.radius)),
                child: Text(
                  payment.failureReason ?? (payment.status == 'expired' ? 'The payment request expired.' : "The payment didn't go through."),
                  style: const TextStyle(color: Brand.danger, fontWeight: FontWeight.w600),
                ),
              ),
            const Text('Mobile money number', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
            const SizedBox(height: 6),
            TextField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: InputDecoration(
                hintText: '0771 234 567',
                helperText: "We'll send a payment request to this phone. Approve it with your PIN.",
                helperMaxLines: 2,
                errorText: _error,
                errorMaxLines: 3,
              ),
            ),
            if (AppConfig.sandboxPayments)
              Container(
                margin: const EdgeInsets.only(top: 12),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: Brand.harvest.withValues(alpha: 0.25), borderRadius: BorderRadius.circular(Brand.radius)),
                child: const Text(
                  'Test payments, no real money: 0771111111 approves, 0772222222 approves after 30 s, '
                  '0773333333 is declined, 0774444444 has no funds.',
                  style: TextStyle(fontSize: 12, height: 1.5, color: Brand.harvestText),
                ),
              ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: _sending ? null : _send,
              child: _sending
                  ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : Text(payment == null ? 'Send payment request' : 'Try again'),
            ),
          ] else if (payment.status == 'succeeded') ...[
            const Icon(LucideIcons.circleCheck, size: 48, color: Brand.green),
            const SizedBox(height: 10),
            Text('Payment received', textAlign: TextAlign.center, style: Brand.display(size: 18, color: Brand.green)),
          ] else ...[
            const Center(child: SizedBox.square(dimension: 40, child: CircularProgressIndicator(strokeWidth: 3))),
            const SizedBox(height: 16),
            Text(
              payment.instructions ?? 'Approve the payment request on ${formatPhone(payment.payerPhone)}.',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 15, height: 1.5, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 8),
            Text(
              payment.expiresAt == null
                  ? 'Waiting for your approval…'
                  : 'Waiting for your approval. The request expires ${formatWhen(payment.expiresAt!)}.',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 12.5, color: Brand.mutedText),
            ),
            const SizedBox(height: 16),
            OutlinedButton(onPressed: () => Navigator.pop(context, false), child: const Text('Close, I will check later')),
          ],
        ],
      ),
    );
  }
}
