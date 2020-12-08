<?php

namespace App\Actions\SocialHub;

use App\Models\ConversationType;
use Lorisleiva\Actions\Action;

class GetConversation extends Action
{
    /**
     * Determine if the user is authorized to make this action.
     *
     * @return bool
     */
    public function authorize()
    {
        return (bool) $this->user();
    }

    /**
     * Get the validation rules that apply to the action.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'profile' => 'required|array',
            'profile.id' => 'required|integer',
            'profile.name' => 'required|string',
            'provider' => 'required|array',
            'provider.id' => 'required|integer',
            'provider.name' => 'required|string',
            'hub_account' => 'required|array',
            'hub_account.id' => 'required|integer',
            'hub_account.name' => 'required|string',
            'is_outgoing' => 'nullable|boolean',
        ];
    }

    /**
     * Execute the action and return a result.
     *
     * @return array
     */
    public function handle()
    {
        $data = $this->validated();
        $visitor = $this->user();

        $type = ConversationType::firstOrCreate(['name' => 'hub']);

        /** @var \App\Models\Conversation $conversation */
        $conversation = $visitor->conversations()->firstOrCreate([
            'account_id' => $visitor->account_id,
            'department_id' => $visitor->account->socialHubDepartment()->name,
            'conversation_type_id' => $type->id,
            'conversations.extra_attributes->social_hub->profile->id' => $data['profile']['id'],
            'conversations.extra_attributes->social_hub->provider->id' => $data['provider']['id'],
            'conversations.extra_attributes->social_hub->provider->name' => $data['provider']['name'],
            'conversations.extra_attributes->social_hub->account->id' => $data['hub_account']['id'],
            'conversations.extra_attributes->social_hub->account->name' => $data['hub_account']['name'],
        ]);

        if (!$this->get('is_outgoing', false)) {
            if ($isClosed = $conversation->isClosed()) {
                $conversation->users()->sync([]);
                $conversation->reopen();
            }

            $fire = $isClosed || $conversation->wasRecentlyCreated;
        } elseif ($conversation->wasRecentlyCreated) {
            $conversation->close($conversation->account, false);
        }

        return [
            'conversation' => $conversation,
            'fire' => $fire ?? false,
        ];
    }
}
