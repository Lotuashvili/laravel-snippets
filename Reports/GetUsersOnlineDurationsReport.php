<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Traits\FiltersDates;
use App\Actions\Reports\Traits\HasAgentReportValidation;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Action;

class GetUsersOnlineDurationsReport extends Action
{
    use FiltersDates, HasAgentReportValidation;

    /**
     * List of all sortable columns
     *
     * @var array|string[]
     */
    protected array $allowedSorts = [
        'online',
        'worked',
        'away',
        'avg_online',
        'avg_worked',
        'avg_away',
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

        if ($department = $this->get('departments')) {
            $department = array_wrap($department);
            $users = $this->user()->account->users()
                ->with('media')
                ->whereHas('departments', fn(EloquentBuilder $query) => $query->whereIn('name', $department))
                ->get();
            $userIds = $users->pluck('id');
        } elseif ($userIds = $this->get('users')) {
            $userIds = array_wrap($userIds);
            $users = $this->user()->account->users()
                ->with('media')
                ->whereIn('id', $userIds) // Check that all users belongs to that account
                ->get();
            $userIds = $users->pluck('id');
        }

        $onlineAgg = $this->getAggregatedQuery('subscribe', 'unsubscribe', 'online', $userIds ?? null);
        $awayAgg = $this->getAggregatedQuery('away', 'online', 'away', $userIds ?? null);

        $aggregatedDurations = DB::table($onlineAgg, 'online')
            ->select([
                'online.user_id',
                DB::raw('CAST(COALESCE(online.total, 0) as UNSIGNED) as online'),
                DB::raw('CAST(COALESCE(away.total, 0) as UNSIGNED) as away'),
                DB::raw('CAST(COALESCE(online.total, 0) - COALESCE(away.total, 0) as UNSIGNED) as worked'),
                DB::raw('CAST(COALESCE(online.average, 0) as UNSIGNED) as avg_online'),
                DB::raw('CAST(COALESCE(away.average, 0) as UNSIGNED) as avg_away'),
                DB::raw('CAST(COALESCE(online.average, 0) - COALESCE(away.average, 0) as UNSIGNED) as avg_worked'),
            ])->leftJoinSub($awayAgg->toSql(), 'away', 'online.user_id', '=', 'away.user_id')
            ->get()
            ->keyBy('user_id');

        $users = $users ?? $this->user()->account->users()->with('media')->get();

        abort_if($isSingle && (empty($users) || $users->isEmpty()), 404);

        $users = $users->map(function (User $user) use ($aggregatedDurations) {
            $data = data_get($aggregatedDurations, $user->id);

            return collect($user->only([
                'id',
                'name',
                'avatar',
            ]))->merge([
                'online' => data_get($data, 'online', 0),
                'worked' => data_get($data, 'worked', 0),
                'away' => data_get($data, 'away', 0),
                'avg_online' => data_get($data, 'avg_online', 0),
                'avg_worked' => data_get($data, 'avg_worked', 0),
                'avg_away' => data_get($data, 'avg_away', 0),
            ]);
        })->when(
            !$isSingle,
            fn(Collection $users) => $users->sortBy($this->sortBy('online'), SORT_REGULAR, $this->isDescending())
                ->values(),
            fn(Collection $users) => $users->first()
        );

        if (!$isSingle) {
            return $users;
        }

        $online = $this->getQuery('subscribe', 'unsubscribe', 'online', $userIds ?? null);
        $away = $this->getQuery('away', 'online', 'away', $userIds ?? null);

        $durations = DB::table($online, 'online')
            ->select([
                'online.user_id',
                DB::raw('CAST(COALESCE(online.total, 0) as UNSIGNED) as online'),
                DB::raw('CAST(COALESCE(away.total, 0) as UNSIGNED) as away'),
                DB::raw('CAST(COALESCE(online.total, 0) - COALESCE(away.total, 0) as UNSIGNED) as worked'),
                DB::raw('online.date as date'),
            ])->leftJoinSub($away->toSql(), 'away', 'online.user_id', '=', 'away.user_id');

        $chart = DB::table($durations, 'durations')
            ->select([
                DB::raw('CAST(SUM(online) as UNSIGNED) as online'),
                DB::raw('CAST(SUM(away) as UNSIGNED) as away'),
                DB::raw('CAST(SUM(worked) as UNSIGNED) as worked'),
                'date',
            ])
            ->groupBy('date')
            ->get()
            ->sortBy('date')
            ->keyBy('date');

        if ($this->shouldFillDates()) {
            $chart = collect(CarbonPeriod::create($this->startDate(), $this->endDate()))
                ->mapWithKeys(fn(Carbon $date) => [
                    ($key = $date->format('Y-m-d')) => $chart->get($key) ?? [
                            'date' => $key,
                            'online' => 0,
                            'away' => 0,
                            'worked' => 0,
                        ],
                ]);
        }

        return [
            'data' => $users ?? [],
            'chart' => $chart,
        ];
    }

    /**
     * @param string $start
     * @param string $end
     * @param string $alias
     * @param \Illuminate\Support\Collection|null $users
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getAggregatedQuery(string $start, string $end, string $alias, ?Collection $users = null): Builder
    {
        return DB::table($this->pairs($start, $end, $alias . '_pairs', $users), $alias)
            ->select([
                'user_id',
                DB::raw("SUM(TIME_TO_SEC(TIMEDIFF($end, $start))) as total"),
                DB::raw("AVG(TIME_TO_SEC(TIMEDIFF($end, $start))) as average"),
            ])
            ->whereNotNull($end)
            ->whereRaw("$alias.event = '$start'")
            ->groupBy('user_id')
            ->oldest('user_id');
    }

    /**
     * @param string $start
     * @param string $end
     * @param string $alias
     * @param \Illuminate\Support\Collection|null $users
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getQuery(string $start, string $end, string $alias, ?Collection $users = null): Builder
    {
        return DB::table($this->pairs($start, $end, $users), $alias)
            ->addSelect('user_id')
            ->selectRaw("CAST(COALESCE(TIME_TO_SEC(TIMEDIFF($end, $start)), 0) as UNSIGNED) as total")
            ->selectRaw("DATE_FORMAT($start, '%Y-%m-%d') as date")
            ->whereNotNull($end)
            ->whereRaw("$alias.event = '$start'")
            ->oldest('user_id');
    }

    /**
     * @param string $start
     * @param string $end
     * @param string $alias
     * @param \Illuminate\Support\Collection|null $users
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function pairs(string $start, string $end, string $alias, ?Collection $users = null): Builder
    {
        return DB::table(
            DB::table('user_websocket_events')
                ->select([
                    'user_id',
                    'event',
                    DB::raw("CASE WHEN event = '$start' THEN created_at END as $start"),
                    DB::raw("CASE WHEN LEAD(event, 1) OVER (PARTITION BY user_id ORDER BY created_at) = '$end'
                        THEN LEAD(created_at, 1) OVER (PARTITION BY user_id ORDER BY created_at)
                    END as $end"),
                ])
                ->whereRaw("event in ('$start', '$end')")
                ->whereRaw("created_at between '{$this->startDate()}' and '{$this->endDate()->addDay()}'")
                ->when(
                    filled($users) && $users->isNotEmpty(),
                    fn(Builder $query) => $query->whereRaw("user_id in ({$users->implode(',')})")
                ), $alias)
            ->whereRaw("$start between '{$this->startDate()}' and '{$this->endDate()}'");
    }
}
