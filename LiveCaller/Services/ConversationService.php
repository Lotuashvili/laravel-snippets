<?php

namespace App\Services;

use App\Events\Conversation\ConversationQueuePositionChanged;
use App\Events\Conversation\ConversationTransferred;
use App\Events\Conversation\ParticipantDetachedFromConversation;
use App\Events\Conversation\ServedRequestsDecremented;
use App\Events\UserAttachedToConversation;
use App\Models\Account;
use App\Models\Asterisk\Queue;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Exception;
use Illuminate\Database\Eloquent\Builder;

class ConversationService
{
    /**
     * @param \App\Models\Account $account
     * @param string|null $departmentId
     *
     * @return \App\Models\Asterisk\Queue|null
     */
    public function getDepartmentForAccount(Account $account, string $departmentId = null): ?Queue
    {
        return Queue::whereAccountId($account->id)
            ->when($departmentId, function (Builder $query) use ($departmentId) {
                $query->where('name', $departmentId);
            })->firstOr(function () use ($account) {
                return Queue::whereAccountId($account->id)->first();
            });
    }

    /**
     * @param \App\Models\Conversation $conversation
     * @param \App\Models\User|\App\Models\Visitor $participant
     * @param string $relation
     *
     * @return bool
     */
    public function markAsRead(Conversation $conversation, $participant, string $relation): bool
    {
        $conversation->messages()->whereHas($relation, function (Builder $query) use ($participant) {
            $query->where('owner_id', $participant->id)->whereNull('read_at');
        })->get()->each(function (ConversationMessage $message) use ($participant, $relation) {
            $message->$relation()->updateExistingPivot($participant, ['read_at' => now()]);
        });

        return true;
    }

    /**
     * @param \App\Models\User|null $operator
     *
     * @return mixed
     */
    public function getRequestedConversationsQuery(User $operator = null)
    {
        $operator = $operator ?? auth()->user();

        $departments = $operator->departments->pluck('name');

        return $operator->account->conversations()
            ->whereIn('department_id', $departments)
            ->whereHas('type', function (Builder $query) {
                $query->whereNull('extra_attributes')->orWhereJsonDoesntContain('extra_attributes', [
                    'auto_assign' => false,
                ]);
            })
            ->unhandled()
            ->oldest('created_at')
            ->open();
    }

    /**
     * @param \App\Models\User|null $operator
     *
     * @return int
     */
    public function getRequestedConversationsCount(User $operator = null): int
    {
        return $this->getRequestedConversationsQuery($operator)->count();
    }

    /**
     * @param \App\Models\Conversation $conversation
     *
     * @return int
     */
    public function getConversationPositionInDepartmentQueue(Conversation $conversation): int
    {
        return Conversation::where('department_id', $conversation->department_id)
                ->where('account_id', $conversation->account_id)
                ->open()
                ->unhandled()
                ->where('created_at', '<', $conversation->created_at)
                ->count() + 1;
    }

    public function sendQueuePositionChangedEvent(Conversation $conversation): bool
    {
        Conversation::where('department_id', $conversation->department_id)
            ->where('account_id', $conversation->account_id)
            ->open()
            ->unhandled()
            ->where('created_at', '>', $conversation->created_at)
            ->get()
            ->each(function (Conversation $conversation) {
                ConversationQueuePositionChanged::dispatch($conversation);
            });

        return true;
    }

    /**
     * @param \App\Models\Conversation $conversation
     * @param \App\Models\User $detach
     * @param \App\Models\User $attach
     * @param bool $events
     *
     * @return bool
     */
    public function transfer(Conversation $conversation, User $detach, User $attach, bool $events = true): bool
    {
        if ($events) {
            ConversationTransferred::dispatch($conversation, $detach, $attach);
        }

        // Must join first to avoid firing ServedRequestsDecremented event
        $this->join($conversation, $attach, $events);

        $this->detach($conversation, $detach, $events);

        return true;
    }

    /**
     * @param \App\Models\Conversation $conversation
     * @param \App\Models\User|\App\Models\Bot|null $joinable
     * @param bool $events
     * @param bool $serveRequestEvents
     *
     * @return bool
     */
    public function join(Conversation $conversation, $joinable = null, bool $events = true, bool $serveRequestEvents = false): bool
    {
        $joinable = $joinable ?? auth()->user();
        $isUser = is_a($joinable, User::class);
        $fireServedRequestsEvent = $serveRequestEvents && $conversation->users()->doesntExist() && $isUser;

        $relation = $joinable instanceof Bot ? 'bots' : 'users';

        $conversation->$relation()->syncWithoutDetaching($joinable);

        $conversation->messages()
            ->whereDoesntHave($relation, fn($query) => $query->where($relation . '.id', $joinable->id))
            ->get()
            ->each(function (ConversationMessage $message) use ($joinable) {
                try {
                    $joinable->messages()->attach($message->id);
                } catch (Exception $e) {
                    //
                }
            });

        if ($events && $isUser) {
            UserAttachedToConversation::dispatch($joinable, $conversation);
        }

        if ($fireServedRequestsEvent) {
            app(OperatorService::class)->sendConversationServeRequestEventToOperators($conversation, ServedRequestsDecremented::class);
        }

        return true;
    }

    /**
     * @param \App\Models\Conversation $conversation
     * @param \App\Models\User|null $operator
     * @param bool $event
     *
     * @return bool
     */
    public function detach(Conversation $conversation, User $operator = null, bool $event = true): bool
    {
        $operator = $operator ?? auth()->user();
        $conversation->users()->detach($operator);

        if ($event) {
            ParticipantDetachedFromConversation::dispatch($conversation, $operator);
        }

        return true;
    }
}
