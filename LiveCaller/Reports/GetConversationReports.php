<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Traits\FiltersConversations;
use App\Actions\Reports\Traits\HasAgentReportValidation;
use App\Models\ConversationReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Lorisleiva\Actions\Action;

class GetConversationReports extends Action
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
        $query = $this->get('query', ConversationReport::query());

        return $query->where('conversation_reports.account_id', $this->user()->account_id)
            ->tap(fn(Builder $query) => $this->filterConversations($query))
            ->when(is_callable($this->get('closure')), fn(JoinClause $query) => $this->get('closure')($query));
    }
}
