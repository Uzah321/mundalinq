<?php

namespace App\Services\Messaging;

use App\Enums\ConversationSide;
use App\Enums\NotificationCategory;
use App\Models\Conversation;
use App\Models\Farm;
use App\Models\Message;
use App\Models\Product;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\MarketplaceAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Buyer-farm conversations. The recipient always gets an inbox notification; an SMS goes out only
 * for the first message they have not read yet, so a lively chat does not flood their phone.
 */
class MessagingService
{
    /**
     * The thread between a buyer and a farm, created on first use.
     */
    public function conversationFor(User $buyer, Farm $farm): Conversation
    {
        // createOrFirst survives two first messages racing to create the same thread.
        return Conversation::createOrFirst(['buyer_id' => $buyer->id, 'farm_id' => $farm->id]);
    }

    public function send(
        Conversation $conversation,
        User $sender,
        ConversationSide $side,
        string $body,
        ?Product $product = null,
        ?SellerOrder $sellerOrder = null,
    ): Message {
        [$message, $recipientWasCaughtUp] = DB::transaction(function () use ($conversation, $sender, $side, $body, $product, $sellerOrder) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $recipientReadAt = $conversation->{$side->other()->readColumn()};

            // Formatted by hand: bound dates would lose their microseconds and count read messages as unread.
            $recipientWasCaughtUp = ! $conversation->messages()
                ->where('sender_side', $side)
                ->when($recipientReadAt, fn ($query) => $query->where('created_at', '>', $recipientReadAt->format('Y-m-d H:i:s.u')))
                ->exists();

            $message = $conversation->messages()->forceCreate([
                'sender_id' => $sender->id,
                'sender_side' => $side,
                'body' => $body,
                'product_id' => $product?->id,
                'seller_order_id' => $sellerOrder?->id,
            ]);

            // Writing a message means the sender has seen everything before it.
            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                $side->readColumn() => $message->created_at,
            ])->save();

            return [$message, $recipientWasCaughtUp];
        });

        $this->notifyRecipients($conversation->fresh(['buyer', 'farm.members']), $message, $sender, $recipientWasCaughtUp);

        return $message;
    }

    public function markRead(Conversation $conversation, ConversationSide $side): void
    {
        $conversation->forceFill([$side->readColumn() => now()])->save();
    }

    private function notifyRecipients(Conversation $conversation, Message $message, User $sender, bool $sms): void
    {
        $from = $message->sender_side === ConversationSide::Buyer ? Str::before($sender->name, ' ') : $conversation->farm->name;
        $preview = Str::limit($message->body, 140);

        $alert = new MarketplaceAlert(
            NotificationCategory::Messages,
            __('Message from :name', ['name' => $from]),
            $preview,
            ['conversation_id' => $conversation->id],
            sms: $sms,
            smsText: __(':name sent you a message on :app: ":message" Reply in the app.', [
                'name' => $from,
                'app' => config('app.name'),
                'message' => Str::limit($message->body, 100),
            ]),
        );

        $recipients = $message->sender_side === ConversationSide::Buyer ? $conversation->farm->members : collect([$conversation->buyer]);

        foreach ($recipients as $recipient) {
            $recipient->notify($alert);
        }
    }
}
