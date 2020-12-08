<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Traits\FiltersConversations;
use App\Actions\Reports\Traits\HasAgentReportValidation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Action;

class GetUserPerformanceReport extends Action
{
    use FiltersConversations, HasAgentReportValidation;

    /**
     * List of all sortable columns
     *
     * @var array|string[]
     */
    protected array $allowedSorts = [
        'conversations',
        'satisfied',
        'avg_duration',
        'avg_response_time',
        'satisfaction_score',
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
        $sort = $this->sortBy('conversations');
        $order = $this->sortOrder();

        $users = User::where('users.account_id', $this->user()->account_id)
            ->when($this->has('users'), fn(Builder $query) => $query->whereIn('users.id', $this->get('users')))
            ->select([
                'users.id',
                'name',
                DB::raw('count(conversation_reports.id) as conversations'),
                DB::raw('cast(sum(if (score > 3, 1, 0)) as unsigned) as satisfied'),
                DB::raw('cast(coalesce(avg(total_duration), 0) as unsigned) as avg_duration'),
                DB::raw('cast(coalesce(avg(in_queue_duration), 0) as unsigned) as avg_response_time'),
                DB::raw('cast(if(count(conversation_reports.id) > 1, round(sum(if (score > 3, 1, 0)) / count(conversation_reports.id), 2), 0) as float) as satisfaction_score'),
            ])
            ->with('media')
            ->joinConversationReports(
                $this->validated(),
                fn(JoinClause $query) => $query
                    ->where('conversation_reports.is_answered', 1)
                    ->leftJoin(
                        'conversation_reviews',
                        fn($join) => $join->on('conversation_reviews.conversation_id', '=', 'conversation_reports.conversation_id')
                            ->where('score', '>', '3')
                    ),
                $this->user()
            )
            ->when(!$isSingle, fn(Builder $query) => $query->orderBy($sort, $order))
            ->groupBy('users.id')
            ->get()
            ->map(fn(User $user) => $user->only([
                'id',
                'name',
                'avatar',
                'conversations',
                'satisfied',
                'avg_duration',
                'avg_response_time',
                'satisfaction_score',
            ]))
            ->when($isSingle, fn(Collection $users) => $users->first());

        abort_if($isSingle && empty($users), 404);

        if (!$isSingle) {
            return $users;
        }

        $report = (new GetConversationReports())->actingAs($this->user())->run($this->validated());

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

        return [
            'data' => $users ?? [],
            'chart' => $chart,
        ];
    }
}
