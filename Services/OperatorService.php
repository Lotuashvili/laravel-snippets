<?php

namespace App\Services;

use App\Events\Conversation\ConversationServeRequestReceived;
use App\Events\Conversation\ServedRequestsDecremented;
use App\Exceptions\DepartmentClosedException;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class OperatorService
{
    /**
     * @var \App\Services\WebSocketService
     */
    private $webSocketService;

    /**
     * @var \App\Services\ConversationService
     */
    private $conversationService;

    /**
     * OperatorService constructor.
     *
     * @param \App\Services\WebSocketService $webSocketService
     * @param \App\Services\ConversationService $conversationService
     */
    public function __construct(WebSocketService $webSocketService, ConversationService $conversationService)
    {
        $this->webSocketService = $webSocketService;
        $this->conversationService = $conversationService;
    }

    /**
     * @param \App\Models\Conversation $conversation
     * @param bool $attachIfOperatorAlreadyExists
     *
     * @return bool
     * @throws \Pusher\PusherException
     */
    public function attachFreeOperatorToCreatedConversation(Conversation $conversation, bool $attachIfOperatorAlreadyExists = false): bool
    {
        if (!$attachIfOperatorAlreadyExists && $conversation->users()->exists()) {
            return false;
        }

        if ((bool) $conversation->account->setting('conversation_auto_assign', true) &&
            (bool) $conversation->type->extra_attributes->get('auto_assign', true)) {
            try {
                $operator = $this->getFirstAvailableOperator($conversation);
            } catch (DepartmentClosedException $e) {
                return false;
            }

            if ($operator) {
                $this->conversationService->join($conversation, $operator);

                if (Cache::has("conversations:serve_request_sent:{$conversation->id}")) {
                    Cache::forget("conversations:serve_request_sent:{$conversation->id}");
                    $this->sendConversationServeRequestEventToOperators($conversation, ServedRequestsDecremented::class);
                }

                return true;
            }
        }

        if (!Cache::has("conversations:serve_request_sent:{$conversation->id}")) {
            Cache::set("conversations:serve_request_sent:{$conversation->id}", 1, 3600);
            $this->sendConversationServeRequestEventToOperators($conversation);
        }

        return false;
    }

    /**
     * @param \App\Models\Conversation $conversation
     *
     * @return \App\Models\User|null
     * @throws \Pusher\PusherException
     * @throws \App\Exceptions\DepartmentClosedException
     */
    public function getFirstAvailableOperator(Conversation $conversation): ?User
    {
        $department = $this->conversationService->getDepartmentForAccount($conversation->account, $conversation->department_id);

        if ($department && $department->isClosed()) {
            throw new DepartmentClosedException;
        }

        $channel = new PresenceChannel('online-users.' . $conversation->account_id);

        $onlineOperators = $this->webSocketService->getOnlineMembers($channel);

        return User::where('account_id', $conversation->account_id)
            ->isNotAway()
            ->where(fn(Builder $query) => $query->doesntHave('conversations')
                ->orWhereHas('conversations',
                    fn(Builder $query) => $query->open(),
                    '<', $conversation->account->setting('auto_assign_conversations_per_user', 1)))
            ->when($conversation->department_id, fn(Builder $query) => $query->inDepartment($conversation->department_id))
            ->whereIn('id', $onlineOperators)->inRandomOrder()->first();
    }

    /**
     * @param \App\Models\Conversation $conversation
     * @param string|null $event
     *
     * @return bool
     */
    public function sendConversationServeRequestEventToOperators(Conversation $conversation, string $event = null): bool
    {
        $event = $event ?? ConversationServeRequestReceived::class;
        $department = $this->conversationService->getDepartmentForAccount($conversation->account, $conversation->department_id);

        $users = $conversation->account->users()
            ->inDepartment($department)
            ->get();

        $event::dispatch($users, $conversation);

        return true;
    }
}
