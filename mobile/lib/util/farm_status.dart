import '../api/farm_models.dart';
import 'format.dart';
import 'order_status.dart';

/// Order statuses as the farm reads them: what has happened, and what they should do next.
StatusLabel farmOrderStatus(FarmOrder order) => switch (order.status) {
      'awaiting_confirmation' => const StatusLabel('New · accept or decline', StatusTone.waiting),
      'accepted' => const StatusLabel('Accepted · waiting for payment', StatusTone.waiting),
      'rejected' => const StatusLabel('Declined', StatusTone.done),
      'expired' => const StatusLabel('Missed · not answered in time', StatusTone.problem),
      'cancelled' => const StatusLabel('Cancelled', StatusTone.done),
      'preparing' => const StatusLabel('Paid · prepare it', StatusTone.active),
      'ready' => order.isPickup
          ? const StatusLabel('Ready for collection', StatusTone.active)
          : const StatusLabel('Packed · ready to send', StatusTone.active),
      'dispatched' => const StatusLabel('Out for delivery', StatusTone.active),
      'delivered' => const StatusLabel('Handed over', StatusTone.done),
      'disputed' => const StatusLabel('Problem reported', StatusTone.problem),
      'completed' => const StatusLabel('Completed · paid out', StatusTone.done),
      _ => StatusLabel(order.status, StatusTone.done),
    };

String farmOrderNextStep(FarmOrder order) => switch (order.status) {
      'awaiting_confirmation' => order.confirmBy == null
          ? 'Check you have everything, then accept. You can supply less of an item.'
          : 'Check you have everything, then accept by ${formatWhen(order.confirmBy!)} or the order lapses.',
      'accepted' => 'The buyer has been asked to pay. Prepare nothing yet.',
      'preparing' => order.isPickup
          ? 'The buyer has paid. Get it ready, then mark it ready so they know to come.'
          : 'The buyer has paid. Pack it, then mark it ready.',
      'ready' => order.isPickup
          ? 'When the buyer arrives, ask for their 6-digit code and enter it to hand over.'
          : 'Send it out, then enter the buyer\'s 6-digit code when it is delivered.',
      'dispatched' => 'On delivery, ask for the buyer\'s 6-digit code and enter it.',
      'delivered' => 'Handed over. The money becomes yours once the buyer confirms, or after 48 hours.',
      'disputed' => 'The buyer reported a problem. MundaLinq support will be in touch.',
      'completed' => 'Done. The money is in your available earnings.',
      'rejected' => order.rejectionReason ?? 'You declined this order.',
      'expired' => 'This order lapsed because it was not answered in time.',
      'cancelled' => 'The buyer cancelled this order.',
      _ => '',
    };

/// Listing statuses for the farm.
StatusLabel listingStatus(ManagedListing listing) {
  if (listing.status == 'active' && listing.isExpired) return const StatusLabel('Expired · renew it', StatusTone.problem);
  return switch (listing.status) {
    'draft' => const StatusLabel('Draft', StatusTone.done),
    'pending_review' => const StatusLabel('Waiting for review', StatusTone.waiting),
    'active' => const StatusLabel('Live', StatusTone.active),
    'paused' => const StatusLabel('Paused', StatusTone.done),
    'rejected' => const StatusLabel('Not approved', StatusTone.problem),
    _ => StatusLabel(listing.status, StatusTone.done),
  };
}
