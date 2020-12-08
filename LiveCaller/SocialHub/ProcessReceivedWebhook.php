<?php

namespace App\Actions\SocialHub;

use Exception;
use Lorisleiva\Actions\Action;

class ProcessReceivedWebhook extends Action
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
            'payload' => 'required|array',
        ];
    }

    /**
     * Execute the action and return a result.
     *
     * @return mixed
     * @throws \Throwable
     */
    public function handle()
    {
        $data = $this->get('payload');

        $event = data_get($data, 'event');

        $handlers = [
            'message_received' => ReceiveMessage::class,
            'messages_seen' => MarkMessagesAsRead::class,
        ];

        throw_if(!array_key_exists($event, $handlers), new Exception("Event $event is not supported"));

        /** @var \Lorisleiva\Actions\Action $action */
        $action = new $handlers[$event];
        $action->actingAs($this->user())->run($data);
    }
}
