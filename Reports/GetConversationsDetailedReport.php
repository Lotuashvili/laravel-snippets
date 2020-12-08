<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Traits\FiltersConversations;
use App\Actions\Reports\Traits\HasAgentReportValidation;
use App\Charts\ConversationReviewsChart;
use App\Charts\ConversationsChart;
use App\Models\ConversationReview;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use DB;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Lorisleiva\Actions\Action;

class GetConversationsDetailedReport extends Action
{
    use HasAgentReportValidation, FiltersConversations;

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
     */
    public function handle()
    {
        $data = $this->validated();
        $account = $this->user()->account;
        $timezone = $account->timezone ?? 'UTC';

        DB::statement('SET @@session.time_zone = "' . $timezone . '"');

        $from = CarbonImmutable::parse($data['from'] ?? now()->startOfWeek())->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? $from->endOfWeek())->endOfDay();
        $period = collect(CarbonPeriod::create($from, $to))->mapWithKeys(fn(Carbon $date) => [$date->format('Y-m-d') => []]);

        $query = DB::table('conversation_reports as v')
            ->where('v.account_id', $account->id)
            ->whereBetween('v.opened_at', [$from, $to])
            ->tap(fn(Builder $query) => $this->filterConversations($query));

        $chartDataDB = (clone $query)
            ->selectRaw("DATE_FORMAT(v.opened_at , '%Y-%c-%d') AS date")
            ->selectRaw("CAST(SUM(IF(v.is_answered = 1, 1, 0)) AS SIGNED) AS served")
            ->selectRaw("CAST(SUM(IF(v.is_missed = 1, 1, 0)) AS SIGNED) AS missed")
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $chartData = $period->merge($chartDataDB)
            ->mapWithKeys(fn($item, $key) => [Carbon::parse($key)->format('j M') => collect($item)->except('date')]);

        $stats = collect((clone $query)
            ->selectRaw("CAST(COALESCE(SUM(is_missed), 0) AS SIGNED) AS missed")
            ->selectRaw("CAST(COALESCE(SUM(is_answered), 0) AS SIGNED) AS completed")
            ->selectRaw("CAST(AVG(in_queue_duration) AS DECIMAL(10,2)) AS avg_response_time")
            ->selectRaw("CAST(AVG(answered_duration) AS DECIMAL(10,2)) AS avg_duration")
            ->selectRaw("CAST(AVG(IF(is_answered = 1, in_queue_duration, null)) AS DECIMAL(10,2)) AS avg_response_time_served")
            ->selectRaw("CAST(AVG(IF(is_answered = 0, in_queue_duration, null)) AS DECIMAL(10,2)) AS avg_response_time_missed")
            ->selectRaw("CAST(SUM(IF(FIND_IN_SET('text', message_types) > 0 AND v.is_answered = 1, 1, 0)) AS SIGNED) AS chats_served")
            ->selectRaw("CAST(SUM(IF((FIND_IN_SET('text', message_types) > 0 OR message_types IS NULL) AND v.is_missed = 1, 1, 0)) AS SIGNED) AS chats_missed")
            ->selectRaw("CAST(SUM(IF(FIND_IN_SET('audio_call', message_types) > 0 AND v.is_answered = 1, 1, 0)) AS SIGNED) AS calls_served")
            ->selectRaw("CAST(SUM(IF(FIND_IN_SET('audio_call', message_types) > 0 AND v.is_missed = 1, 1, 0)) AS SIGNED) AS calls_missed")
            ->selectRaw("CAST(COALESCE(SUM(is_answered), 0) / COUNT(v.id) * 100 AS DECIMAL(10,2)) AS acceptance")
            ->selectRaw("COALESCE(ROUND(SUM(IF(r.score > 3, 1, 0)) / SUM(IF(r.score > 0, 1, 0)) * 100, 2), 0) AS satisfaction")
            ->selectRaw("ROUND(SUM(IF(r.score > 0, 1, 0)) / COUNT(v.id) * 100, 2) AS total_rated")
            ->selectRaw("CAST(COALESCE(COUNT(DISTINCT v.visitor_id), 0) AS SIGNED) AS uniques")
            ->selectRaw("CAST(COALESCE(COUNT(v.id) - count(DISTINCT v.visitor_id), 0) AS SIGNED) AS duplicates")
            ->selectRaw("COUNT(v.id) AS total")
            ->leftJoin('conversation_reviews AS r', 'r.conversation_id', '=', 'v.id')
            ->get()
            ->first()
        )->map(fn($value) => is_string($value) ? (float) $value : $value);

        $users = User::joinConversationReports([
            'from' => $from,
            'to' => $to,
            'types' => $this->get('types'),
        ], fn(JoinClause $query) => $query->where('conversation_reports.is_answered', 1), $this->user())
            ->with('media')
            ->where('users.account_id', $account->id)
            ->select([
                'users.id',
                'users.name',
                DB::raw("count(conversation_reports.id) as conversations"),
            ])
            ->groupBy('users.id')
            ->latest('conversations')
            ->take(5)
            ->get()
            ->map(fn(User $user) => [
                'user_id' => $user->id,
                'conversations' => $user->conversations,
                'name' => $user->name,
                'avatar' => $user->avatar,
            ]);

        $timing = collect([
            '<15s',
            '16s-30s',
            '31s-45s',
            '46s-1m',
            '>1m',
        ])->mapWithKeys(fn($range) => [
            $range => [
                'range' => $range,
                'count' => 0,
            ],
        ])->merge(DB::query()
            ->fromSub((clone $query)
                ->selectRaw('TIME_TO_SEC(TIMEDIFF(v.answered_at, v.opened_at)) AS time')
                ->whereNotNull('v.opened_at')
                ->whereNotNull('v.answered_at')
                , 'v')
            ->selectRaw("
                CASE
                    WHEN time < 15 THEN '<15s'
                    WHEN time BETWEEN 15 AND 30 THEN '16s-30s'
                    WHEN time BETWEEN 31 AND 45 THEN '31s-45s'
                    WHEN time BETWEEN 46 AND 60 THEN '46s-1m'
                    ELSE '>1m'
                END AS 'range'
            ")
            ->selectRaw('COUNT(*) as count')
            ->groupBy('range')
            ->get()
            ->keyBy('range')
        )->values();

        $chart = new ConversationsChart('Conversations', $chartData);

        $reviews = ConversationReview::where('account_id', $account->id)
            ->whereHas('conversation', fn(EloquentBuilder $query) => $query->whereBetween('created_at', [$from, $to]))
            ->select('score')
            ->selectRaw('COUNT(score) AS count')
            ->groupBy('score')
            ->get();

        $reviewsChart = new ConversationReviewsChart($reviews);

        DB::statement('SET @@session.time_zone = @@global.time_zone');

        return [
            'stats' => $stats,
            'leaderboard' => $users,
            'charts' => [
                'conversations' => $chart->toArray(),
                'reviews' => $reviewsChart->toArray(),
            ],
            'timing' => $timing,
        ];
    }
}
