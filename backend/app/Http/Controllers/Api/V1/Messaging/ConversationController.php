<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Enums\ConversationSide;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\StartConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Farm;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Buyer-farm enquiries and order conversations.
 */
class ConversationController extends Controller
{
    private const MESSAGE_RELATIONS = ['sender', 'product', 'sellerOrder'];

    public function __construct(private MessagingService $messaging) {}

    /**
     * The signed-in buyer's threads or, with farm_id, the farm's inbox. Most recent first,
     * each with unread_count for that side.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['farm_id' => ['nullable', 'uuid']]);

        if (isset($filters['farm_id'])) {
            $farm = Farm::findOrFail($filters['farm_id']);
            Gate::authorize('message', $farm);
            [$query, $side] = [Conversation::where('farm_id', $farm->id), ConversationSide::Farm];
        } else {
            [$query, $side] = [Conversation::where('buyer_id', $request->user()->id), ConversationSide::Buyer];
        }

        $conversations = $query->whereNotNull('last_message_at')
            ->select('conversations.*')
            ->withLastMessage()
            ->withUnreadCountFor($side)
            ->with(['farm', 'buyer'])
            ->orderByDesc('last_message_at')
            ->paginate(20);

        return ConversationResource::collection($conversations);
    }

    /**
     * Write to a farm (or about an order), continuing the existing thread if there is one.
     */
    public function store(StartConversationRequest $request): JsonResponse
    {
        $conversation = $this->messaging->conversationFor($request->buyer(), $request->farm());
        $side = $conversation->sideOf($request->user());

        // Staff can see orders but do not take part in conversations.
        abort_if($side === null, 403, __('Only the buyer and the farm can write in this conversation.'));

        $message = $this->messaging->send(
            $conversation,
            $request->user(),
            $side,
            $request->validated('body'),
            $request->product(),
            $request->sellerOrder(),
        );

        return (new MessageResource($message->load(self::MESSAGE_RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * Newest first, 30 at a time; follow meta.next_cursor for older messages.
     */
    public function messages(Conversation $conversation): AnonymousResourceCollection
    {
        Gate::authorize('view', $conversation);

        $messages = $conversation->messages()
            ->with(self::MESSAGE_RELATIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(30);

        return MessageResource::collection($messages);
    }

    public function reply(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('participate', $conversation);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $message = $this->messaging->send($conversation, $request->user(), $conversation->sideOf($request->user()), $validated['body']);

        return (new MessageResource($message->load(self::MESSAGE_RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * Everything up to now is read for the caller's side (for a farm, for all its members).
     */
    public function read(Request $request, Conversation $conversation): Response
    {
        Gate::authorize('participate', $conversation);

        $this->messaging->markRead($conversation, $conversation->sideOf($request->user()));

        return response()->noContent();
    }
}
