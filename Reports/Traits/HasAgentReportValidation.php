<?php

namespace App\Actions\Reports\Traits;

use Validator;

trait HasAgentReportValidation
{
    /**
     * Get the validation rules that apply to the action.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'types' => 'nullable|array',
            'types.*' => 'required|string|in:audio,text,website,hub,messenger,viber,telegram,whatsapp',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'users' => 'nullable|array',
            'users.*' => 'required|exists:users,id,account_id,' . $this->user()->account_id,
            'sort' => 'nullable|string',
            'sort_order' => 'nullable|string|in:asc,desc',
        ];
    }

    /**
     * Check if action is running for a single user (to display detailed information and chart)
     *
     * @return bool
     */
    public function isSingle(): bool
    {
        return count((array) $this->get('users', [])) == 1;
    }

    /**
     * Check if all dates in period should be filled with zero values
     *
     * @return bool
     */
    public function shouldFillDates(): bool
    {
        return (bool) $this->get('fill_dates', true);
    }

    /**
     * Get sort column name
     *
     * @param string|null $default
     *
     * @return string|null
     * @throws \Illuminate\Validation\ValidationException
     */
    public function sortBy(string $default = null): ?string
    {
        $sort = $this->get('sort', $default);

        if (property_exists($this, 'allowedSorts')) {
            Validator::validate(compact('sort'), [
                'sort' => 'required|string|in:' . implode(',', $this->allowedSorts),
            ]);
        }

        return $sort;
    }

    /**
     * Get sort order
     *
     * @param string|null $default
     *
     * @return string|null
     */
    public function sortOrder(string $default = 'desc'): ?string
    {
        return $this->get('sort_order', $default);
    }

    /**
     * Check if sort order is descending
     *
     * @param string $defaultOreder
     *
     * @return bool
     */
    public function isDescending(string $defaultOreder = 'desc'): bool
    {
        return $this->sortOrder($defaultOreder) === 'desc';
    }
}
