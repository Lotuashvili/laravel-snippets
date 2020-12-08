<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Traits\FiltersConversations;
use App\Actions\Reports\Traits\HasAgentReportValidation;
use App\Models\ConversationReview;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Action;

class GetUserSatisfactionReport extends Action
{
    use FiltersConversations, HasAgentReportValidation;

    /**
     * List of all sortable columns
     *
     * @var array|string[]
     */
    protected array $allowedSorts = [
        'total_reviews',
        'satisfaction',
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
     */
    public function handle()
    {
        $isSingle = $this->isSingle();

        $countScores = fn(Collection $reviews) => collect(range(1, 5))->mapWithKeys(fn(int $index) => [
            $index => $reviews->filter(fn(ConversationReview $review) => $review->score == $index)->count(),
        ]);

        $users = $this->user()->account->users()
            ->with([
                'conversations' => fn($query) => $query
                    ->whereHas('reports', fn($query) => $this->filterConversations($query))
                    ->has('review')
                    ->with('review'),
            ])
            ->when($this->has('users'), fn($query) => $query->whereIn('id', $this->get('users')))
            ->get()
            ->when($isSingle, function (Collection $users) use (&$reviews) {
                $reviews = optional(optional($users->first())->conversations)->pluck('review') ?? collect();

                return $users;
            })
            ->map(fn(User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'total_reviews' => ($reviews = $user->conversations->pluck('review'))->count(),
                'satisfaction' => $reviews->filter(fn(ConversationReview $review) => $review->score > 3)->count(),
                'scores' => $countScores($reviews),
            ])
            ->when(
                !$isSingle,
                fn(Collection $users) => $users->sortBy(
                    $this->sortBy('satisfaction'), SORT_REGULAR, $this->isDescending()
                )->values(),
                fn(Collection $users) => $users->first()
            );

        abort_if($isSingle && empty($users), 404);

        if (!$isSingle) {
            return $users;
        }

        $chart = $reviews->groupBy(fn(ConversationReview $review) => $review->created_at->format('Y-m-d'))
            ->map(fn(Collection $reviews, string $date) => [
                'date' => $date,
                'scores' => $countScores($reviews),
            ])
            ->sortBy('date');

        if ($this->shouldFillDates()) {
            $chart = collect(CarbonPeriod::create($this->startDate(), $this->endDate()))
                ->mapWithKeys(fn(Carbon $date) => [
                    ($key = $date->format('Y-m-d')) => $chart->get($key) ?? [
                            'date' => $key,
                            'scores' => [
                                1 => 0,
                                2 => 0,
                                3 => 0,
                                4 => 0,
                                5 => 0,
                            ],
                        ],
                ]);
        }

        return [
            'data' => $users ?? [],
            'chart' => $chart,
        ];
    }
}
