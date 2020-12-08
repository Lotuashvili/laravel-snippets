<?php

namespace App\Actions\Reports;

use Carbon\CarbonImmutable;
use DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\Action;

class GetConversationsReport extends Action
{
    protected $attributes = [
        'group_by' => [],
    ];

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
            'group_by' => 'nullable|array',
            'group_by.*' => 'string|in:queue,agent',
            'timezone' => 'nullable|timezone',
            'departments' => 'nullable|array',
            'departments.*' => [
                'string',
                'max:255',
                Rule::exists('queues', 'name')->where('account_id', $this->user()->account_id),
            ],
        ];
    }

    /**
     * Execute the action and return a result.
     *
     * @return mixed
     */
    public function handle()
    {
        $data = $this->validated();

        $timezone = $data['timezone'] ?? $this->user()->account->timezone;
        $now = CarbonImmutable::now($timezone);
        $range = [$now->startOfWeek(), $now->endOfWeek()];

        $departments = $data['departments'] ?? $this->user()->account->queues->pluck('name');

        $live = DB::table('v_conversations_report')
            ->selectRaw('COALESCE(SUM(is_in_queue), 0) AS in_queue_count')
            ->selectRaw('COALESCE(SUM(is_talking_now), 0) AS talking_now_count')
            ->where(fn(Builder $query) => $query->where('is_in_queue', 1)->orWhere('is_talking_now', 1))
            // ->whereBetween('created_at', $range) // Doesn't work on reopened conversations
            ->where('account_id', $this->user()->account_id)
            ->whereIn('department_id', $departments)
            ->when(count($data['group_by']) > 0, fn(Builder $query) => $query->addSelect($data['group_by'])
                ->groupBy($data['group_by']))
            ->first();

        $reports = DB::table('conversation_reports')
            ->selectRaw('COALESCE(SUM(is_missed), 0) AS missed_count')
            ->selectRaw('COALESCE(SUM(is_answered), 0) AS completed_count')
            ->selectRaw('AVG(IF(is_answered = 1, in_queue_duration, null)) AS avg_response_time')
            ->whereBetween('opened_at', $range)
            ->whereIn('department_id', $departments)
            ->where('account_id', $this->user()->account_id)
            ->first();

        return collect($live)->merge($reports);
    }
}
