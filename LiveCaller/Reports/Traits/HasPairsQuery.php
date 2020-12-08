<?php

namespace App\Actions\Reports\Traits;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

trait HasPairsQuery
{
    use FiltersDates;

    /**
     * Conversation events pairing query builder
     *
     * @param string $start
     * @param string $end
     * @param string $partition
     * @param string $alias
     * @param bool $filterDates
     * @param callable|null $callback
     * @param bool $filterAccount
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getPairs(string $start, string $end, string $partition, string $alias, bool $filterDates = true, callable $callback = null, bool $filterAccount = true): Builder
    {
        $tmp = $alias . '_tmp';

        return DB::table(
            DB::table('conversation_events', $alias)
                ->addSelect('conversation_id')
                ->addSelect('event')
                ->selectRaw("CASE WHEN event = '$start' THEN {$alias}.created_at END as `$start`")
                ->selectRaw("CASE
                    WHEN LEAD(event, 1) OVER (PARTITION BY $partition ORDER BY {$alias}.created_at) = '$end'
                        THEN LEAD({$alias}.created_at, 1) OVER (PARTITION BY $partition ORDER BY {$alias}.created_at)
                    END as `$end`")
                ->whereIn('event', [$start, $end])
                ->when($filterDates, fn(Builder $query) => $query->whereBetween($alias . '.created_at', [
                    $this->startDate(),
                    $this->endDate()->addDay(),
                ]))
                ->when(is_callable($callback), fn(Builder $query) => $callback($query))
                ->join('conversations', fn($join) => $join->on('conversations.id', '=', $alias . '.conversation_id')
                    ->when($filterAccount, fn($query) => $query->where('conversations.account_id', $this->user()->account_id))),
            $tmp
        )->when($filterDates, fn(Builder $query) => $query->whereBetween($tmp . '.' . $start, [
            $this->startDate(),
            $this->endDate(),
        ]));
    }

    /**
     * Pair conversation open/close events
     *
     * @param bool $filterDates
     * @param callable|null $callback
     * @param bool $filterAccount
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function conversationPairs(bool $filterDates = true, callable $callback = null, bool $filterAccount = true): Builder
    {
        return $this->getPairs('open', 'close', 'conversation_id', 'event_pairs', $filterDates, $callback, $filterAccount);
    }

    /**
     * Pair user join/leave events
     *
     * @param bool $filterDates
     * @param callable|null $callback
     * @param bool $filterAccount
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function userPairs(bool $filterDates = true, callable $callback = null, bool $filterAccount = true): Builder
    {
        return $this->getPairs('join', 'leave', 'conversation_id, join_pairs.extra_attributes->"$.user_id"', 'join_pairs', $filterDates, $callback, $filterAccount);
    }

    /**
     * @param bool $filterDates
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function eventsWithUserIds(bool $filterDates = true): Builder
    {
        return DB::table(
            $this->conversationPairs($filterDates),
            'events'
        )->select([
            'events.conversation_id as id',
            'open',
            'close',
            DB::raw('group_concat(distinct joins.user_id) as user_ids'),
            DB::raw('min(joins.join) as answered_at'),
            DB::raw('cast(coalesce(time_to_sec(timediff(close, open)), 0) as unsigned) as duration'),
            DB::raw('cast(coalesce(time_to_sec(timediff(close, min(joins.join))), 0) as unsigned) as talk_time'),
            DB::raw('cast(coalesce(time_to_sec(timediff(min(joins.join), open)), 0) as unsigned) as response_time'),
        ])->leftJoinSub(
            $this->userPairs(
                $filterDates,
                fn(Builder $query) => $query->selectRaw('join_pairs.extra_attributes->"$.user_id" as user_id')
                    ->whereRaw('join_pairs.extra_attributes->"$.user_id" is not null')
            ), 'joins',
            fn($join) => $join->on('joins.conversation_id', '=', 'events.conversation_id')
                ->where('joins.event', 'join')
                ->whereRaw('joins.join between events.open and events.close')
        )->where('events.event', 'open')
            ->whereNotNull('events.close')
            ->groupBy('events.conversation_id', 'open', 'close')
            ->latest('events.conversation_id')
            ->oldest('close');
    }
}
