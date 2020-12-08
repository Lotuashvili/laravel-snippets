<?php

namespace App\Actions\Reports\Traits;

use Illuminate\Support\Collection;

trait FiltersConversations
{
    use FiltersDates;

    /**
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation|\Illuminate\Database\Query\Builder $query
     * @param bool $filterDates
     *
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Relations\Relation|mixed
     */
    public function filterConversations($query, bool $filterDates = true)
    {
        return $this->filterConversationTypes(
            $query->when($filterDates, fn($query) => $query->whereBetween('opened_at', $this->dates()))
        );
    }

    /**
     * @param $query
     *
     * @return mixed
     */
    protected function filterConversationTypes($query)
    {
        return $query->when($this->has('types'), function ($query) {
            $types = $this->getTypes();

            return $query->where(fn($query) => $query->when(
                ($messageTypes = collect(['audio', 'text'])->intersect($types))->isNotEmpty(),
                function ($query) use ($messageTypes) {
                    $type = $messageTypes->first();
                    $type = $type == 'audio' ? 'audio_call' : $type;

                    return $query->whereRaw("FIND_IN_SET('$type', message_types) > 0");
                }
            )->when(
                ($providers = collect(['messenger', 'viber', 'telegram', 'whatsapp'])->intersect($types))->isNotEmpty(),
                fn($query) => $query->orWhereIn('hub_provider', $providers->all())
            )->when(
                $types->contains('website'),
                fn($query) => $query->orWhere('from_website', 1)
            )->when(
                $types->contains('hub'),
                fn($query) => $query->orWhere('from_hub', 1)
            ));
        });
    }

    protected function getTypes(): Collection
    {
        $types = collect($this->get('types'));
        $generalTypes = collect(['audio', 'text']);

        if ($generalTypes->diff($types)->isEmpty()) {
            $types = $types->diff($generalTypes);
        }

        return $types;
    }
}
