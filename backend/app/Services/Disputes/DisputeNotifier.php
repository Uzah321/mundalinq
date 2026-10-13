<?php

namespace App\Services\Disputes;

use App\Enums\DisputeParty;
use App\Enums\NotificationCategory;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\User;
use App\Notifications\MarketplaceAlert;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Keeps the buyer and the farm up to date on a reported problem. Decisions also go by SMS.
 */
class DisputeNotifier
{
    public function opened(Dispute $dispute): void
    {
        $dispute->load(['sellerOrder', 'farm.members', 'buyer']);

        $this->send($this->farmMembers($dispute), new MarketplaceAlert(
            NotificationCategory::Disputes,
            __('Problem reported on order :number', ['number' => $dispute->sellerOrder->number]),
            __('The buyer reported that :reason on order :number. Reply in the :app app; support will review it.', [
                'reason' => $dispute->reason->label(),
                'number' => $dispute->sellerOrder->number,
                'app' => config('app.name'),
            ]),
            $this->context($dispute),
            sms: true,
        ));
    }

    public function messageAdded(Dispute $dispute, DisputeMessage $message): void
    {
        $dispute->load(['sellerOrder', 'farm.members', 'buyer']);

        $recipients = match ($message->author_party) {
            DisputeParty::Buyer => $this->farmMembers($dispute),
            DisputeParty::Farm => collect([$dispute->buyer]),
            DisputeParty::Staff => $this->farmMembers($dispute)->push($dispute->buyer),
        };

        $this->send($recipients, new MarketplaceAlert(
            NotificationCategory::Disputes,
            __('New message about order :number', ['number' => $dispute->sellerOrder->number]),
            $message->body,
            $this->context($dispute),
        ));
    }

    public function withdrawn(Dispute $dispute): void
    {
        $dispute->load(['sellerOrder', 'farm.members']);

        $this->send($this->farmMembers($dispute), new MarketplaceAlert(
            NotificationCategory::Disputes,
            __('Problem report withdrawn'),
            __('The buyer withdrew their report on order :number. The sale is complete.', ['number' => $dispute->sellerOrder->number]),
            $this->context($dispute),
        ));
    }

    public function resolved(Dispute $dispute): void
    {
        $dispute->load(['sellerOrder', 'order', 'farm.members', 'buyer']);
        $number = $dispute->sellerOrder->number;
        $refund = $dispute->refund_minor > 0 ? Money::display($dispute->refund_minor, $dispute->order->currency) : null;

        $this->send(collect([$dispute->buyer]), new MarketplaceAlert(
            NotificationCategory::Disputes,
            __('Your report on order :number is resolved', ['number' => $number]),
            $refund
                ? __('We are refunding :amount for order :number to the number you paid from. :note', ['amount' => $refund, 'number' => $number, 'note' => $dispute->resolution_note])
                : __('Your report on order :number was reviewed and no refund is due. :note', ['number' => $number, 'note' => $dispute->resolution_note]),
            $this->context($dispute),
            sms: true,
        ));

        $this->send($this->farmMembers($dispute), new MarketplaceAlert(
            NotificationCategory::Disputes,
            __('Report on order :number resolved', ['number' => $number]),
            $refund
                ? __(':amount from order :number is refunded to the buyer. :note', ['amount' => $refund, 'number' => $number, 'note' => $dispute->resolution_note])
                : __('The report on order :number was resolved without a refund. Your earnings are released.', ['number' => $number]),
            $this->context($dispute),
            sms: true,
        ));
    }

    /**
     * @return Collection<int, User>
     */
    private function farmMembers(Dispute $dispute): Collection
    {
        return collect($dispute->farm->members->all());
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function send(Collection $recipients, MarketplaceAlert $alert): void
    {
        foreach ($recipients as $recipient) {
            $recipient->notify($alert);
        }
    }

    /**
     * @return array<string, string>
     */
    private function context(Dispute $dispute): array
    {
        return ['dispute_id' => $dispute->id, 'seller_order_id' => $dispute->seller_order_id];
    }
}
