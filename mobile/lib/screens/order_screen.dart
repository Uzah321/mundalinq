import 'dart:async';

import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../theme.dart';
import '../util/format.dart';
import '../util/order_status.dart';
import '../widgets/common.dart';
import 'dispute_screen.dart';
import 'messages/chat_screen.dart';
import 'order_actions.dart';

/// One order: what happens next, paying, each farm's progress, handover codes and after-sale actions.
class OrderScreen extends StatefulWidget {
  const OrderScreen({super.key, required this.orderId, this.justPlaced = false});

  final String orderId;
  final bool justPlaced;

  @override
  State<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends State<OrderScreen> {
  Order? _order;
  Object? _error;
  Timer? _refresh;

  @override
  void initState() {
    super.initState();
    _load();
    // Farms confirm and hand over while the buyer watches: check back now and then.
    _refresh = Timer.periodic(const Duration(seconds: 20), (_) {
      final status = _order?.status;
      if (status != 'completed' && status != 'cancelled') _load(quiet: true);
    });
  }

  @override
  void dispose() {
    _refresh?.cancel();
    super.dispose();
  }

  Future<void> _load({bool quiet = false}) async {
    try {
      final response = await context.read<ApiClient>().get('/orders/${widget.orderId}') as Map<String, dynamic>;
      if (mounted) setState(() => _order = Order.fromJson(response['data'] as Map<String, dynamic>));
    } catch (error) {
      if (mounted && !quiet) setState(() => _error = error);
    }
  }

  Future<void> _run(Future<void> Function() action) async {
    try {
      await action();
    } catch (error) {
      if (mounted) showMessage(context, errorMessage(error));
    }
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final order = _order;

    return Scaffold(
      appBar: AppBar(title: Text(order == null ? 'Order' : 'Order ${order.number}')),
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
                  if (widget.justPlaced && order.status == 'awaiting_confirmation')
                    Container(
                      margin: const EdgeInsets.only(bottom: 14),
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(color: Brand.mint, borderRadius: BorderRadius.circular(Brand.radius)),
                      child: Row(
                        children: [
                          const Icon(LucideIcons.circleCheck, color: Brand.green),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              "Order placed. We'll let you know as soon as it's confirmed.",
                              style: Brand.display(size: 14, color: Brand.green),
                            ),
                          ),
                        ],
                      ),
                    ),
                  _Summary(order: order),
                  if (order.status == 'awaiting_payment') ...[
                    const SizedBox(height: 14),
                    FilledButton.icon(
                      onPressed: () async {
                        final paid = await showPaySheet(context, order);
                        if (paid && context.mounted) showMessage(context, 'Payment received. Thank you!');
                        await _load();
                      },
                      icon: const Icon(LucideIcons.smartphone, size: 18),
                      label: Text(order.latestPayment?.pending == true
                          ? 'Check payment'
                          : 'Pay ${formatMoney(order.total, order.currency)} by mobile money'),
                    ),
                  ],
                  const SizedBox(height: 18),
                  for (final seller in order.sellerOrders)
                    _SellerOrderCard(
                      order: order,
                      seller: seller,
                      onConfirmReceipt: () => _run(() => confirmReceipt(context, seller)),
                      onReportProblem: () => _run(() => reportProblem(context, seller)),
                      onReview: () => _run(() => reviewFarm(context, seller)),
                    ),
                  if (order.cancellable) ...[
                    const SizedBox(height: 4),
                    Center(
                      child: TextButton(
                        onPressed: () => _run(() => cancelOrder(context, order)),
                        style: TextButton.styleFrom(foregroundColor: Brand.danger),
                        child: const Text('Cancel order'),
                      ),
                    ),
                  ],
                ],
              ),
            ),
    );
  }
}

class StatusChip extends StatelessWidget {
  const StatusChip(this.label, {super.key});

  final StatusLabel label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(color: label.tone.background, borderRadius: BorderRadius.circular(20)),
      child: Text(label.text, style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: label.tone.foreground)),
    );
  }
}

class _Summary extends StatelessWidget {
  const _Summary({required this.order});

  final Order order;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(border: Border.all(color: Brand.border), borderRadius: BorderRadius.circular(Brand.radius)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              StatusChip(orderStatus(order)),
              const Spacer(),
              if (order.createdAt != null) Text(formatDate(order.createdAt!), style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
            ],
          ),
          const SizedBox(height: 12),
          Text(orderNextStep(order), style: const TextStyle(fontSize: 14, height: 1.5)),
          const Divider(height: 26),
          _amount('Produce', order.subtotal, order.currency),
          _amount('Delivery', order.deliveryFee, order.currency),
          _amount('Total', order.total, order.currency, strong: true),
        ],
      ),
    );
  }

  Widget _amount(String label, String amount, String currency, {bool strong = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(
          children: [
            Expanded(child: Text(label, style: TextStyle(fontWeight: strong ? FontWeight.w800 : FontWeight.w500))),
            Text(formatMoney(amount, currency), style: TextStyle(fontSize: strong ? 16 : 14, fontWeight: strong ? FontWeight.w800 : FontWeight.w600)),
          ],
        ),
      );
}

class _SellerOrderCard extends StatelessWidget {
  const _SellerOrderCard({
    required this.order,
    required this.seller,
    required this.onConfirmReceipt,
    required this.onReportProblem,
    required this.onReview,
  });

  final Order order;
  final SellerOrder seller;
  final VoidCallback onConfirmReceipt;
  final VoidCallback onReportProblem;
  final VoidCallback onReview;

  @override
  Widget build(BuildContext context) {
    final currency = order.currency;
    final address = seller.deliveryAddress;

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
              Icon(seller.isPickup ? LucideIcons.store : LucideIcons.truck, size: 14, color: Brand.mutedText),
              const SizedBox(width: 4),
              Text(seller.isPickup ? 'Collect' : 'Delivery', style: const TextStyle(fontSize: 12, color: Brand.mutedText)),
            ],
          ),
          const SizedBox(height: 10),
          StatusChip(sellerOrderStatus(seller)),
          if (seller.status == 'awaiting_confirmation' && seller.confirmBy != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text('The farm answers by ${formatWhen(seller.confirmBy!)}.', style: const TextStyle(fontSize: 12.5, color: Brand.mutedText)),
            ),
          if (seller.rejectionReason != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(seller.rejectionReason!, style: const TextStyle(fontSize: 12.5, color: Brand.danger)),
            ),
          const SizedBox(height: 10),
          for (final item in seller.items) _ItemLine(item: item, currency: currency),
          if (seller.deliveryFee != '0.00')
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Row(
                children: [
                  const Expanded(child: Text('Delivery', style: TextStyle(fontSize: 13, color: Brand.mutedText))),
                  Text(formatMoney(seller.deliveryFee, currency), style: const TextStyle(fontSize: 13)),
                ],
              ),
            ),
          if (seller.handoverCode != null) _HandoverCode(seller: seller),
          if (seller.isPickup && seller.pickupLocations.isNotEmpty) ...[
            const SizedBox(height: 10),
            const Text('Collect from', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700)),
            for (final place in seller.pickupLocations)
              Text(
                [place['name'], place['address_line'], place['ward'] == null ? null : 'Ward ${place['ward']}', place['district'], place['province']]
                    .whereType<String>()
                    .join(', '),
                style: const TextStyle(fontSize: 12.5, height: 1.5, color: Brand.mutedText),
              ),
          ],
          if (!seller.isPickup && address != null) ...[
            const SizedBox(height: 10),
            const Text('Delivering to', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700)),
            Text(
              [address['address_line'], address['district']].whereType<String>().join(', '),
              style: const TextStyle(fontSize: 12.5, height: 1.5, color: Brand.mutedText),
            ),
          ],
          if (seller.farmPhone != null && order.status == 'paid')
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Row(
                children: [
                  const Icon(LucideIcons.phone, size: 14, color: Brand.green),
                  const SizedBox(width: 6),
                  SelectableText('Farm: ${formatPhone(seller.farmPhone!)}', style: const TextStyle(fontSize: 12.5)),
                ],
              ),
            ),
          if (seller.status == 'delivered') ...[
            const SizedBox(height: 12),
            FilledButton(onPressed: onConfirmReceipt, child: const Text('Everything arrived well')),
            const SizedBox(height: 6),
            OutlinedButton(onPressed: onReportProblem, child: const Text('Report a problem')),
            if (seller.reportProblemUntil != null)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  'You can report a problem until ${formatWhen(seller.reportProblemUntil!)}. After that the sale is final.',
                  style: const TextStyle(fontSize: 12, color: Brand.mutedText),
                ),
              ),
          ],
          if (seller.status == 'disputed')
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: Text(
                'MundaLinq support is looking into your report and will be in touch. The farm is not paid until it is settled.',
                style: TextStyle(fontSize: 12.5, height: 1.5, color: Brand.mutedText),
              ),
            ),
          Wrap(
            spacing: 4,
            children: [
              TextButton.icon(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => ChatScreen.forOrder(sellerOrderId: seller.id, farmId: seller.farmId, title: seller.farmName, asFarm: false),
                  ),
                ),
                icon: const Icon(LucideIcons.messageCircle, size: 16),
                label: const Text('Message the farm'),
              ),
              if (seller.disputeId != null)
                TextButton.icon(
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => DisputeScreen(disputeId: seller.disputeId!, asBuyer: true)),
                  ),
                  icon: const Icon(LucideIcons.circleAlert, size: 16),
                  label: const Text('Your problem report'),
                ),
            ],
          ),
          if (seller.status == 'completed') ...[
            const SizedBox(height: 10),
            if (seller.reviewRating == null)
              OutlinedButton.icon(
                onPressed: onReview,
                icon: const Icon(LucideIcons.star, size: 17, color: Brand.harvestText),
                label: Text('Rate ${seller.farmName}'),
              )
            else
              Row(
                children: [
                  for (var i = 1; i <= 5; i++)
                    Icon(Icons.star_rounded, size: 18, color: i <= seller.reviewRating! ? Brand.harvest : Brand.border),
                  const SizedBox(width: 6),
                  const Text('Thanks for your review', style: TextStyle(fontSize: 12.5, color: Brand.mutedText)),
                ],
              ),
          ],
        ],
      ),
    );
  }
}

class _ItemLine extends StatelessWidget {
  const _ItemLine({required this.item, required this.currency});

  final OrderItem item;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final confirmed = item.confirmedQuantity;
    final short = confirmed != null && confirmed != item.quantity;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('${formatQuantity(item.quantity, item.unit)} · ${item.title}', style: const TextStyle(fontSize: 13)),
                if (item.label != null) Text(item.label!, style: const TextStyle(fontSize: 11.5, color: Brand.mutedText)),
                if (short)
                  Text(
                    confirmed == '0' ? "The farm couldn't supply this" : 'The farm has ${formatQuantity(confirmed, item.unit)}',
                    style: const TextStyle(fontSize: 11.5, color: Brand.harvestText, fontWeight: FontWeight.w600),
                  ),
              ],
            ),
          ),
          Text(formatMoney(item.lineTotal, currency), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

class _HandoverCode extends StatelessWidget {
  const _HandoverCode({required this.seller});

  final SellerOrder seller;

  @override
  Widget build(BuildContext context) {
    final code = seller.handoverCode!;
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: Brand.mint, borderRadius: BorderRadius.circular(Brand.radius)),
      child: Column(
        children: [
          Text(
            seller.isPickup ? 'Show this code when you collect' : 'Give this code to the driver when it arrives',
            style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: Brand.green),
          ),
          const SizedBox(height: 6),
          Text('${code.substring(0, 3)} ${code.substring(3)}', style: Brand.display(size: 30, weight: FontWeight.w700, color: Brand.green).copyWith(letterSpacing: 4)),
          const SizedBox(height: 4),
          const Text(
            'Only share it once you have your order. It proves you received it.',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 11.5, color: Brand.mutedText),
          ),
        ],
      ),
    );
  }
}
