import 'dart:ui';

import '../api/models.dart';
import '../theme.dart';
import 'format.dart';

/// How a status reads to the buyer, and its colour: green for good news, yellow while waiting, grey once over.
class StatusLabel {
  const StatusLabel(this.text, this.tone);

  final String text;
  final StatusTone tone;
}

enum StatusTone {
  waiting(Brand.harvest, Brand.harvestText),
  active(Brand.mint, Brand.green),
  done(Brand.muted, Brand.mutedText),
  problem(Color(0xFFFDE8E8), Brand.danger);

  const StatusTone(this.background, this.foreground);

  final Color background;
  final Color foreground;
}

StatusLabel orderStatus(Order order) => switch (order.status) {
      'awaiting_confirmation' => StatusLabel(
          order.sellerOrders.length > 1 ? 'Waiting for the farms' : 'Waiting for the farm',
          StatusTone.waiting,
        ),
      'awaiting_payment' => const StatusLabel('Ready to pay', StatusTone.waiting),
      'paid' => const StatusLabel('Paid · in progress', StatusTone.active),
      'completed' => const StatusLabel('Completed', StatusTone.done),
      'cancelled' => const StatusLabel('Cancelled', StatusTone.problem),
      _ => StatusLabel(order.status, StatusTone.done),
    };

StatusLabel sellerOrderStatus(SellerOrder order) => switch (order.status) {
      'awaiting_confirmation' => const StatusLabel('Waiting for the farm to confirm', StatusTone.waiting),
      'accepted' => const StatusLabel('Confirmed · waiting for payment', StatusTone.waiting),
      'rejected' => const StatusLabel("The farm couldn't take this order", StatusTone.problem),
      'expired' => const StatusLabel("The farm didn't answer in time", StatusTone.problem),
      'cancelled' => const StatusLabel('Cancelled', StatusTone.problem),
      'preparing' => const StatusLabel('Being prepared', StatusTone.active),
      'ready' => order.isPickup
          ? const StatusLabel('Ready to collect', StatusTone.active)
          : const StatusLabel('Packed for delivery', StatusTone.active),
      'dispatched' => const StatusLabel('On the way', StatusTone.active),
      'delivered' => StatusLabel(order.isPickup ? 'Collected' : 'Delivered', StatusTone.active),
      'disputed' => const StatusLabel('Problem reported', StatusTone.problem),
      'completed' => const StatusLabel('Completed', StatusTone.done),
      _ => StatusLabel(order.status, StatusTone.done),
    };

/// The one line that tells the buyer what happens next on the whole order.
String orderNextStep(Order order) => switch (order.status) {
      'awaiting_confirmation' => order.sellerOrders.length > 1
          ? 'Each farm checks it has everything and confirms. You pay once they have.'
          : 'The farm checks it has everything and confirms. You pay once it has.',
      'awaiting_payment' => order.paymentDueAt == null
          ? 'Pay by mobile money to confirm your order.'
          : 'Pay by mobile money ${formatWhen(order.paymentDueAt!)} at the latest, or the order is cancelled.',
      'paid' => _paidNextStep(order),
      'completed' => 'All done. Thank you for buying from local farms.',
      'cancelled' => order.cancellationReason ?? 'This order was cancelled.',
      _ => '',
    };

/// A paid order stays "paid" until every farm's part is final, so the next step follows the farms' progress.
String _paidNextStep(Order order) {
  final statuses = order.sellerOrders.map((seller) => seller.status).toSet();
  if (statuses.contains('delivered')) {
    return 'Check what you received, then confirm it arrived well or report a problem.';
  }
  if (statuses.contains('disputed')) return 'MundaLinq support is looking into the problem you reported.';
  if (statuses.contains('dispatched')) return 'On its way. Give the driver your handover code once you have your order.';
  if (statuses.contains('ready')) return 'Ready. Show the handover code when you collect or receive your order.';
  return 'Paid. The farm is preparing your order; show the handover code when you collect or receive it.';
}
