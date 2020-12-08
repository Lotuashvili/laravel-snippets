<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Traits\FiltersConversations;
use App\Actions\Reports\Traits\HasAgentReportValidation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Lorisleiva\Actions\Action;

class JoinConversationReports extends Action
{
    use FiltersConversations, HasAgentReportValidation;

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
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function handle(): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query = $this->get('query', User::query());

        return $query->join(
            'conversation_reports',
            fn(JoinClause $join) => $join->whereRaw("JSON_CONTAINS(conversation_reports.user_ids, CAST(users.id as JSON), '$')")
                ->where('conversation_reports.account_id', $this->user()->account_id)
                ->tap(fn(JoinClause $query) => $this->filterConversations($query))
                ->when(is_callable($this->get('closure')), fn(JoinClause $query) => $this->get('closure')($query)),
            null, null, $this->get('joinType', 'inner')
        );
    }
}
