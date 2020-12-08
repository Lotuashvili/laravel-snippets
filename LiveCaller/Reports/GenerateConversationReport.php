<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Traits\HasPairsQuery;
use App\Models\ConversationReport;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Action;
use stdClass;

class GenerateConversationReport extends Action
{
    use HasPairsQuery;

    /**
     * Get the validation rules that apply to the action.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'conversation' => 'nullable|exists:conversations,id',
            'conversations' => 'nullable|array',
            // უშვებს ძალიან ბევრს query-ს შესამოწმებლად, როცა გადაეცემა ბევრი id
            // 'conversations.*' => 'required|exists:conversations,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'force' => 'nullable|boolean',
        ];
    }

    /**
     * Execute the action and return a result.
     *
     * @return void
     */
    public function handle(): void
    {
        if (!$this->get('force', false)) {
            $this->getReport()
                ->selectSub($this->uniqueMessageTypesSubQuery(), 'message_types')
                ->get()
                ->each(function (stdClass $report) {
                    ConversationReport::firstOrCreate([
                        'conversation_id' => $report->conversation_id,
                        'opened_at' => $report->opened_at,
                    ], json_decode(json_encode($report), true));
                });

            return;
        }

        // TODO უკეთესი რამეა მოსაფიქრებელი
        // ეს გაეშვება მხოლოდ ერთხელ, მიგრაციის დროს ძველი conversation-ების რეპორტების დასაგენერირებლად
        // მომავალში რეპორტები დაგენერირდება ზედა კოდით
        $sqlMode = DB::selectOne("SELECT @@SESSION.sql_mode as 'mode'")->mode;

        DB::statement('SET @@session.sql_mode = :mode', [
            'mode' => str_replace(['NO_ZERO_IN_DATE', 'NO_ZERO_DATE'], '', $sqlMode),
        ]);
        ConversationReport::insertUsing([
            'account_id',
            'conversation_id',
            'department_id',
            'visitor_id',
            'is_answered',
            'is_missed',
            'from_website',
            'from_hub',
            'user_ids',
            'user_durations',
            'in_queue_duration',
            'answered_duration',
            'total_duration',
            'hub_provider',
            'widget_id',
            'opened_at',
            'answered_at',
            'closed_at',
            'created_at',
            'updated_at',
            'message_types',
        ], $this->getReport()
            ->selectRaw('now() as created_at')
            ->selectRaw('now() as updated_at')
            ->addSelect('ct.types as message_types')
            ->leftJoin('v_conversation_unique_message_types AS ct', 'ct.id', '=', 'p2.conversation_id')
        );

        DB::statement('SET @@session.sql_mode = :mode', ['mode' => $sqlMode]);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    protected function getReport(): Builder
    {
        return DB::table(
            DB::table(
                DB::table(
                    DB::table(
                        $this->conversationPairs(
                            filled($this->get('from')),
                            fn(Builder $query) => $query->when(
                                $this->shouldFilterConversations(),
                                fn(Builder $query) => $query->whereIn('event_pairs.conversation_id', $this->conversations())
                            )->addSelect('account_id')
                                ->addSelect('conversations.department_id')
                                ->addSelect('conversations.extra_attributes->widget_id as widget_id')
                                ->addSelect('conversations.extra_attributes->social_hub->provider->name as hub_provider'),
                            false
                        ),
                        'events'
                    )->select([
                        'events.conversation_id',
                        'events.account_id',
                        'events.department_id',
                        'events.widget_id',
                        'events.hub_provider',
                        'open',
                        'close',
                        'joins.user_id',
                        'joins.join',
                        DB::raw('coalesce(joins.leave, close) as `leave`'),
                    ])->leftJoinSub(
                        $this->userPairs(
                            filled($this->get('from')),
                            fn(Builder $query) => $query->selectRaw('join_pairs.extra_attributes->"$.user_id" as user_id')
                                ->whereRaw('join_pairs.extra_attributes->"$.user_id" is not null')
                                ->when(
                                    $this->shouldFilterConversations(),
                                    fn(Builder $query) => $query->whereIn('join_pairs.conversation_id', $this->conversations())
                                ),
                            false
                        ), 'joins',
                        fn($join) => $join->on('joins.conversation_id', '=', 'events.conversation_id')
                            ->where('joins.event', 'join')
                            ->whereRaw('joins.join between events.open and events.close')
                    )->where('events.event', 'open')
                        ->whereNotNull('events.close')
                        ->latest('events.conversation_id')
                        ->oldest('close'),
                    'pairs'
                )->select([
                    'conversation_id',
                    'account_id',
                    'department_id',
                    'widget_id',
                    'hub_provider',
                    'user_id',
                    'open',
                    'close',
                    DB::raw('sum(TIME_TO_SEC(TIMEDIFF(pairs.leave, pairs.join))) as duration'),
                    DB::raw('min(`join`) as first_join'),
                ])->groupBy('conversation_id', 'user_id', 'open', 'close'),
                'p1'
            )->addSelect('conversation_id')
                ->addSelect('open')
                ->addSelect('account_id')
                ->addSelect('department_id')
                ->addSelect('widget_id')
                ->addSelect('hub_provider')
                ->addSelect('close')
                ->selectRaw('if (count(user_id) > 0, json_arrayagg(user_id), null) as user_ids')
                ->selectRaW("if (count(user_id) > 0, json_objectagg(IFNULL(user_id, ''), coalesce(duration, 0)), null) as user_durations")
                ->selectRaw('if (count(user_id) > 0, 1, 0) as is_answered')
                ->selectRaw('if (count(user_id) > 0, 0, 1) as is_missed')
                ->selectRaw('min(first_join) as answered_at')
                ->groupBy('conversation_id', 'open', 'close'),
            'p2'
        )->select([
            'p2.account_id as account_id',
            'p2.conversation_id as conversation_id',
            'p2.department_id as department_id',
            'visitors.id as visitor_id',
            'is_answered',
            'is_missed',
            DB::raw('if (hub_provider is null, 1, 0) as from_website'),
            DB::raw('if (hub_provider is not null, 1, 0) as from_hub'),
            'user_ids',
            'user_durations',
            DB::raw('TIMESTAMPDIFF(SECOND, p2.open, COALESCE(answered_at, p2.close)) as in_queue_duration'),
            DB::raw('IF (answered_at is not null, TIMESTAMPDIFF(SECOND, answered_at, p2.close), null) as answered_duration'),
            DB::raw('TIMESTAMPDIFF(SECOND, p2.open, p2.close) as total_duration'),
            'hub_provider',
            'widgets.id as widget_id',
            'open as opened_at',
            'answered_at',
            'close as closed_at',
        ])->oldest('close')
            ->leftJoin('conversation_members as v', fn(JoinClause $join) => $join->on('v.conversation_id', '=', 'p2.conversation_id')
                ->where('v.member_type', 'visitor')
            )->leftJoin('visitors', 'visitors.id', '=', 'v.member_id')
            ->leftJoin('widgets', 'widgets.id', '=', 'widget_id')
            ->latest('p2.conversation_id');
    }

    protected function shouldFilterConversations(): bool
    {
        return filled($this->get('conversations')) || filled($this->get('conversation'));
    }

    protected function conversations(): array
    {
        return (array) $this->get('conversation', $this->get('conversations'));
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    protected function uniqueMessageTypesSubQuery(): Builder
    {
        return DB::table("conversation_messages", "cm")
            ->selectRaw("GROUP_CONCAT(DISTINCT conversation_message_types.`name`) as 'types'")
            ->leftJoin('conversation_message_types', 'cm.type_id', '=', 'conversation_message_types.id')
            ->whereColumn('cm.conversation_id', '=', 'p2.conversation_id')
            ->groupBy('cm.conversation_id');
    }
}
