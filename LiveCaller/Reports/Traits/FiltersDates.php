<?php

namespace App\Actions\Reports\Traits;

use Carbon\CarbonImmutable;

trait FiltersDates
{
    /**
     * Get start date of filters
     *
     * @return \Carbon\CarbonImmutable
     */
    public function startDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->get('from', now()->startOfWeek()))->startOfDay();
    }

    /**
     * Get end date of filters
     *
     * @return \Carbon\CarbonImmutable
     */
    public function endDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->get('to', $this->startDate()->addWeek()))->endOfDay();
    }

    /**
     * Return start and end dates as an array
     *
     * @param bool $keys
     *
     * @return array
     */
    public function dates(bool $keys = false): array
    {
        $dates = [
            'from' => $this->startDate(),
            'to' => $this->endDate(),
        ];

        return $keys ? $dates : array_values($dates);
    }
}
