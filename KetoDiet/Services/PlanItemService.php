<?php

namespace App\Services;

use App\Models\Dish;
use App\Models\Meal;
use App\Models\Plan;
use App\Models\PlanItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PlanItemService
{
    /**
     * @var int
     */
    private $days;

    /**
     * @var int
     */
    private $dishesPerDay;

    /**
     * @var int
     */
    private $dishMaximumOccurencePerWeek;

    /**
     * @var \App\Models\Plan
     */
    private $plan;

    /**
     * @var \Illuminate\Database\Eloquent\Collection
     */
    private $meals;

    /**
     * @var \Illuminate\Support\Collection|array
     */
    private $related = [];

    /**
     * @var \Illuminate\Support\Collection|array
     */
    private $unrelated = [];

    /**
     * @var array
     */
    private $byWeek = [];

    /**
     * @var array
     */
    private $items = [];

    public function __construct()
    {
        $this->days = config('plan.days');
        $this->meals = Meal::oldest('id')->get();
        $this->dishesPerDay = $this->meals->count() ?: config('plan.dishes_per_day');
        $this->dishMaximumOccurencePerWeek = config('plan.dish_maximum_occurence_per_week');
    }

    public function generate(Plan $plan): bool
    {
        $this->plan = $plan;

        if ($plan->items()->count()) {
            $plan->items()->delete();
        }

        return $this->categorizeDishes()
            ->build()
            ->save();
    }

    private function categorizeDishes()
    {
        $includedIngredients = $this->plan->ingredients()
            ->wherePivot('score', '>', 0)
            ->pluck('id');

        $excludedIngredients = $this->plan->ingredients()
            ->wherePivot('score', '<', 0)
            ->pluck('id');

        $neutralIngredients = $this->plan->ingredients()
            ->wherePivot('score', 0)
            ->pluck('id');

        Dish::withoutGlobalScope('dish')
            ->available()
            ->with('ingredients')
            ->whereDoesntHave('ingredients', function (Builder $query) use ($excludedIngredients, $includedIngredients, $neutralIngredients) {
                $query->whereIn('id', $excludedIngredients)->orWhere(function (Builder $query) use ($includedIngredients, $excludedIngredients, $neutralIngredients) {
                    $query->whereIn('parent_id', $excludedIngredients)
                        ->whereNotIn('id', $includedIngredients)
                        ->whereNotIn('id', $neutralIngredients);
                });
            })
            ->select(['id', 'name'])
            ->get()
            ->each(function (Dish $dish) use ($includedIngredients) {
                $ingredients = $dish->ingredients->pluck('id');

                $score = $ingredients->intersect($includedIngredients)->count();

                $collection = $score > 0 ? 'related' : 'unrelated';

                $dish->meals->each(function (Meal $meal) use ($dish, $collection) {
                    $this->{$collection}[$meal->id][] = ($meal->name == 'Drink' ? 'drink' : 'dish') . ':' . $dish->id;
                });
            });

        return $this;
    }

    private function build()
    {
        foreach (range(1, $this->days) as $day) {
            foreach ($this->meals as $meal) {
                if (!$this->pickItemRecursive(collect(data_get($this->related, $meal->id)), $day, $meal->id)) {
                    if (!$this->pickItemRecursive(collect(data_get($this->unrelated, $meal->id)), $day, $meal->id, 50)) {
                        break 2;
                    }
                }
            }
        }

        return $this;
    }

    private function pickItemRecursive(Collection $collection, int $day, int $meal, int $tries = 10): bool
    {
        if ($collection->isEmpty()) {
            return false;
        }

        $collection = $collection->shuffle();
        $item = $collection->first();

        if (!$this->check($item, $day, $meal)) {
            if ($tries > 0) {
                return $this->pickItemRecursive($collection, $day, $meal, $tries - 1);
            }

            return false;
        }

        $this->items[$day][$meal] = $item;
        $this->byWeek[(int) ceil($day / config('plan.days_in_week'))][] = $item;

        return true;
    }

    private function save(): bool
    {
        // Uncomment to check if there are no less items
        // if (count(array_flatten($this->items)) !== $this->days * $this->dishesPerDay) {
        //     return false;
        // }

        foreach ($this->items as $day => $dayItems) {
            foreach ($dayItems as $mealId => $dish) {
                [$type, $id] = explode(':', $dish);

                PlanItem::create([
                    'plan_id' => $this->plan->id,
                    'meal_id' => $mealId,
                    'dish_type' => $type,
                    'dish_id' => $id,
                    'day' => $day,
                ]);
            }
        }

        return true;
    }

    private function check(string $item, int $day, int $meal): bool
    {
        $week = (int) ceil($day / config('plan.days_in_week'));

        // Check if dish was used more than 3 times in this week
        $checkWeek = (array_count_values($this->byWeek[$week] ?? [])[$item] ?? 0) >= $this->dishMaximumOccurencePerWeek;
        $checkDay = in_array($item, $this->items[$day] ?? []);
        $checkPreviousDayIndex = $day > 1 ? data_get($this->items, $day - 1 . '.' . $meal) === $item : false;

        return !($checkWeek || $checkDay || $checkPreviousDayIndex);
    }
}
