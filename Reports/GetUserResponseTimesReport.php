<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Traits\FiltersConversations;
use App\Actions\Reports\Traits\HasAgentReportValidation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Action;

class GetUserResponseTimesReport extends Action
{
    use FiltersConversations, HasAgentReportValidation;

    /**
     * List of all sortable columns
     *
     * @var array|string[]
     */
    protected array $allowedSorts = [
        'avg_response_time',
        'avg_chat_duration',
        'total_talk_time',
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
     * Execute the action and return a result.
     *
     * @return mixed
     * @throws \Illuminate\Validation\ValidationException
     */
    public function handle()
    {
        $isSingle = $this->isSingle();

        $users = User::where('users.account_id', $this->user()->account_id)
            ->when($this->has('users'), fn(Builder $query) => $query->whereIn('users.id', $this->get('users')))
            ->select([
                'users.id',
                'name',
                DB::raw('cast(coalesce(avg(in_queue_duration), 0) as unsigned) as avg_response_time'),
                DB::raw('cast(coalesce(avg(answered_duration), 0) as unsigned) as avg_chat_duration'),
                DB::raw('cast(coalesce(sum(answered_duration), 0) as unsigned) as total_talk_time'),
            ])
            ->with('media')
            ->joinConversationReports(
                $this->validated(),
                null,
                $this->user()
            )
            ->groupBy('users.id')
            ->when(
                !$isSingle,
                fn(Builder $query) => $query
                    ->orderBy($this->sortBy('avg_response_time'), $this->sortOrder('asc'))
                    ->oldest('avg_response_time')
                    ->latest('total_talk_time')
            )->get()
            ->map(fn(User $user) => $user->only([
                'id',
                'name',
                'avatar',
                'avg_response_time',
                'avg_chat_duration',
                'total_talk_time',
                'avg_response_time',
            ]))
            ->when($isSingle, fn(Collection $users) => $users->first());

        abort_if($isSingle && empty($users), 404);

        if (!$isSingle) {
            return $users;
        }

        $report = (new GetConversationReports)->actingAs($this->user())->run($this->validated());

        $chart = DB::table(
            DB::table($report, 'cr')
                ->select([
                    '*',
                    DB::raw("DATE_FORMAT(cr.answered_at, '%Y-%m-%d') as date"),
                ])
                ->whereRaw("JSON_CONTAINS(cr.user_ids, CAST({$users['id']} as JSON), '$')")
            , 'cr2')
            ->select([
                'date',
                DB::raw('cast(coalesce(avg(in_queue_duration), 0) as unsigned) as avg_response_time'),
                DB::raw('cast(coalesce(count(id), 0) as unsigned) as conversations'),
            ])
            ->groupBy('date')
            ->get()
            ->sortBy('date')
            ->keyBy('date');

        $users = array_merge($users, [
            'avg_conversations_per_day' => round($chart->avg('conversations')),
        ]);

        return [
            'data' => $users,
            'chart' => $chart,
        ];
    }
}
