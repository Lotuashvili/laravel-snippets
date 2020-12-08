<?php

namespace App\Actions\SocialHub;

use App\Models\Visitor;
use Lorisleiva\Actions\Action;

class GetVisitor extends Action
{
    /**
     * Determine if the user is authorized to make this action.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
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
            'profile.avatar' => 'nullable|string|url',
            'provider' => 'required|array',
            'provider.id' => 'required|integer',
            'provider.name' => 'required|string',
        ];
    }

    /**
     * Execute the action and return a result.
     *
     * @return mixed
     * @throws \Spatie\MediaLibrary\Exceptions\FileCannotBeAdded
     * @throws \Spatie\MediaLibrary\Exceptions\FileCannotBeAdded\DiskDoesNotExist
     * @throws \Spatie\MediaLibrary\Exceptions\FileCannotBeAdded\FileDoesNotExist
     * @throws \Spatie\MediaLibrary\Exceptions\FileCannotBeAdded\FileIsTooBig
     */
    public function handle()
    {
        $data = $this->validated();
        /** @var \App\Models\Account $account */
        $account = $this->user();

        $visitor = Visitor::firstOrCreate([
            'extra_attributes->social_hub->profile->id' => $data['profile']['id'],
            'extra_attributes->social_hub->provider->id' => $data['provider']['id'],
            'extra_attributes->social_hub->provider->name' => $data['provider']['name'],
            'account_id' => $account->id,
        ], [
            'name' => $data['profile']['name'],
        ]);

        if ($account->blacklistedModels()
            ->where('lockable_type', 'visitor')
            ->where('lockable_id', $visitor->id)
            ->active()
            ->exists()) {
            return false;
        }

        if ($visitor->sessions()->doesntExist()) {
            $visitor->sessions()->create([
                'device_info' => [
                    'device' => [
                        'browser' => [
                            'name' => ucfirst($data['provider']['name']),
                            'version' => '1.0.0',
                        ],
                        'os' => [
                            'name' => ucfirst($data['provider']['name']),
                            'version' => '1.0.0',
                            'versionName' => 'Social Hub',
                        ],
                        'platform' => [
                            'type' => 'desktop',
                        ],
                        'engine' => [
                            'name' => ucfirst($data['provider']['name']),
                        ],
                    ],
                    'network' => [
                        'ip' => '0.0.0.0',
                    ],
                ],
            ]);
        }

        if ($data['profile']['avatar'] ?? null) {
            if (!$visitor->hasMedia('') || now()->subDay()->gte($visitor->getFirstMedia('avatar')->created_at)) {
                $visitor->clearMediaCollection('avatar')
                    ->addMediaFromUrl($data['profile']['avatar'])
                    ->addCustomHeaders([
                        'visibility' => 'public',
                    ])
                    ->toMediaCollection('avatar');
            }
        }

        return $visitor;
    }
}
