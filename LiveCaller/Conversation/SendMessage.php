<?php

namespace App\Actions\Conversation;

use App\Events\Conversation\ConversationMessageCreated;
use App\Models\Account;
use App\Models\AccountFieldValue;
use App\Models\Conversation;
use App\Models\ConversationMessageType;
use App\Models\User;
use App\Models\Visitor;
use App\Services\ConversationService;
use Exception;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Action;

class SendMessage extends Action
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
        $user = $this->user();
        $account = is_a($user, Account::class) ? $user : $user->account;
        $extensions = $account->getAcceptedExtensions();

        return [
            'conversation' => 'required',
            'content' => 'required_without_all:attachments,attachmentUrls|nullable|string|min:1|max:3000',
            'endpoint' => 'required|in:app,widget',
            'type_id' => 'nullable|integer|exists:conversation_message_types,id',
            'type_name' => 'nullable|string|exists:conversation_message_types,name',
            'attachments' => 'nullable|array',
            'attachments.*' => 'required|max:' . (1024 * 15) . "|mimes:$extensions|extensions:$extensions",
            'attachmentUrls' => 'nullable|array',
            'attachmentUrls.*.url' => 'required|string|url',
            'extra_attributes' => 'nullable|array',
        ];
    }

    /**
     * Execute the action and return a result.
     *
     * @param \App\Services\ConversationService $conversationService
     *
     * @return mixed
     * @throws \Spatie\MediaLibrary\Exceptions\FileCannotBeAdded
     * @throws \Spatie\MediaLibrary\Exceptions\FileCannotBeAdded\DiskDoesNotExist
     * @throws \Spatie\MediaLibrary\Exceptions\FileCannotBeAdded\FileDoesNotExist
     * @throws \Spatie\MediaLibrary\Exceptions\FileCannotBeAdded\FileIsTooBig
     * @throws \Throwable
     */
    public function handle(ConversationService $conversationService)
    {
        $data = $this->validated();
        /** @var \App\Models\Conversation $conversation */
        $conversation = $data['conversation'];
        $user = $this->user();
        $senderType = strtolower(class_basename(get_class($user)));

        if ($messageIds = $this->get('extra_attributes.social_hub.message_ids')) {
            if ($conversation->messages()
                ->socialHubMessageId(array_first($messageIds))
                ->exists()) {
                return false;
            }
        }

        if (!empty($data['type_id'])) {
            $type = ConversationMessageType::findOrFail($data['type_id']);
        } elseif (!empty($data['type_name'])) {
            $type = ConversationMessageType::where('name', $data['type_name'])->firstOrFail();
        } else {
            $type = ConversationMessageType::where('name', 'text')->firstOrFail();
        }

        if (is_a($user, User::class) && $type->name !== 'comment') {
            throw_if($user->departments()
                ->where('name', $conversation->department_id)
                ->doesntExist(), ValidationException::withMessages(['conversation' => 'You are not in department.']));
            if ($conversation->shouldReopen()) {
                $conversation->reopen();
                $conversationService->join($conversation, $user);
            }

            throw_if($conversation->isClosed(), ValidationException::withMessages(['conversation' => 'Conversation is closed.']));

            if ($conversation->users()->doesntExist()) {
                $conversationService->join($conversation, $user);
            } elseif ($conversation->users()->where('id', '!=', $user->id)->exists()) {
                throw ValidationException::withMessages(['conversation' => 'Conversation is already served.']);
            }
        }

        $relation = is_a($user, Account::class) ? 'sentMessages' : 'messages';

        /** @var \App\Models\ConversationMessage $message */
        $message = $user->$relation()->create([
            'content' => $this->replaceVariables($data['content'] ?? null, $conversation),
            'conversation_id' => $conversation->id,
            'type_id' => $type->id,
            'extra_attributes' => $this->get('extra_attributes'),
        ], [
            'read_at' => now(),
            'is_sender' => true,
        ]);

        if (!empty($data['attachments']) || !empty($data['attachmentUrls'])) {
            $uploadedBy = $senderType . ':' . $user->id;

            $headers = [
                'Tagging' =>
                    http_build_query([
                        'account_id' => $conversation->account_id,
                        'conversation_id' => $conversation->id,
                        'uploaded_by' => $uploadedBy,
                    ]),
            ];

            foreach ($data['attachments'] ?? [] as $image) {
                $message->addMedia($image)->addCustomHeaders($headers)->toMediaCollection('attachments');
            }

            foreach ($data['attachmentUrls'] ?? [] as $attachment) {
                $message->addMediaFromUrl($attachment['url'])
                    ->addCustomHeaders($headers)
                    ->toMediaCollection('attachments');
            }
        }

        foreach (['users', 'visitors'] as $relation) { // bots, accounts (?)
            if ($relation == str_plural($senderType)) {
                continue;
            }

            try {
                $message->$relation()->syncWithoutDetaching($conversation->$relation);
            } catch (Exception $e) {
                //
            }
        }

        if (!$conversation->isClosed() || $conversation->type->name == 'hub') {
            ConversationMessageCreated::dispatch($message, $data['endpoint']);
        }

        $message->loadMissing(['visitors', 'users', 'type', 'attachments']);

        $sender = $message->owners->firstWhere('pivot.is_sender', true);

        if ($sender) {
            $message->sender_type = $sender->pivot->owner_type;
            $message->sender_id = $sender->pivot->owner_id;
            $message->loadMissing('sender');
        }

        return $message;
    }

    /**
     * @param string|null $message
     * @param \App\Models\Conversation $conversation
     *
     * @return string|null
     */
    protected function replaceVariables(?string $message, Conversation $conversation): ?string
    {
        /** @var \App\Models\Visitor $visitor */
        if (is_null($message) || is_a($this->user(), Visitor::class) ||
            !$visitor = $conversation->loadMissing('visitors.fieldValues.field')->visitors->first()) {
            return $message;
        }

        $fields = collect($visitor->modelFillable)->mapWithKeys(fn(string $field) => [
            '${' . $field . '}' => $visitor->getRawOriginal($field) ?? '',
        ])->merge($visitor->fieldValues->mapWithKeys(fn(AccountFieldValue $value) => [
            '${' . $value->field->name . '}' => $value->value ?? '',
        ]));

        return preg_replace('/\${.*?}/', '',
            str_replace($fields->keys()->all(), $fields->values()->all(), $message)
        );
    }
}
