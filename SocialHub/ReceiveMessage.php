<?php

namespace App\Actions\SocialHub;

use App\Actions\Conversation\SendMessage;
use App\Events\Conversation\ConversationCreated;
use Lorisleiva\Actions\Action;

class ReceiveMessage extends Action
{
    /**
     * @var int
     */
    public int $tries = 1;

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
            'event' => 'nullable|in:message_received',
            'provider' => 'required|array',
            'provider.id' => 'required|integer',
            'provider.name' => 'required|string',
            'data.message_id' => 'required|string',
            'data.account' => 'required|array',
            'data.account.id' => 'required|integer',
            'data.account.name' => 'required|string',
            'data.content' => 'required_without:data.attachments|nullable|string',
            'data.attachments' => 'required_without:data.content|nullable|array',
            'data.attachments.*.url' => 'required|string|url',
            'data.is_outgoing' => 'required|boolean',
            'data.profile' => 'required|array',
            'data.profile.id' => 'required|integer',
            'data.profile.name' => 'required|string',
            'data.profile.avatar' => 'nullable|string|url',
            'data.sent_at' => 'required|date',
            'data.read_at' => 'nullable|date',
        ];
    }

    /**
     * Execute the action and return a result.
     *
     * @return mixed
     */
    public function handle()
    {
        $isOutgoing = (bool) $this->get('data.is_outgoing');

        $visitor = (new GetVisitor)->actingAs($this->user())->run([
            'profile' => $this->get('data.profile'),
            'provider' => $this->get('provider'),
        ]);

        if (!$visitor) {
            // If visitor is blocked
            return;
        }

        app()->setLocale($this->user()->getLocale());

        $conversationObject = (new GetConversation)->actingAs($visitor)->run([
            'profile' => $this->get('data.profile'),
            'provider' => $this->get('provider'),
            'hub_account' => $this->get('data.account'),
            'is_outgoing' => $isOutgoing,
        ]);
        $conversation = $conversationObject['conversation'];
        $fire = $conversationObject['fire'];

        (new SendMessage)->actingAs($isOutgoing ? $conversation->account : $visitor)->run([
            'conversation' => $conversation,
            'content' => $this->get('data.content'),
            'attachmentUrls' => $this->get('data.attachments'),
            'endpoint' => 'app',
            'extra_attributes' => [
                'social_hub' => [
                    'message_ids' => (array) $this->get('data.message_id'),
                    'is_outgoing' => $isOutgoing,
                ],
            ],
        ]);

        if ($fire) {
            event(new ConversationCreated($conversation->fresh()));
        }
    }
}
