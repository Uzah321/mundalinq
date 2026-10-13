<?php

namespace App\Http\Requests\Quotes;

use App\Enums\QuoteFrequency;
use App\Models\Unit;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body: {"category_id", "title", "quantity": "200", "unit": "kg", "frequency": "weekly", "needed_by": "2026-11-06",
 * "fulfilment": "pickup|delivery|either", "province", "district", "notes"?, "open_days"? (default 7)}.
 */
class StoreQuoteRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'uuid', Rule::exists('categories', 'id')->where('is_active', true)],
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'quantity' => ['required', DecimalString::quantity()],
            'unit' => ['required', 'string', Rule::exists('units', 'code')],
            'frequency' => ['required', Rule::enum(QuoteFrequency::class)],
            'needed_by' => ['required', 'date', 'after_or_equal:today'],
            'fulfilment' => ['required', Rule::in(['pickup', 'delivery', 'either'])],
            'province' => ['required', 'string', Rule::in(config('mundalinq.countries.ZW.provinces'))],
            'district' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'open_days' => ['nullable', 'integer', 'between:1,30'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $validated = $this->safe()->except(['unit', 'open_days']);

        return [...$validated, 'unit_id' => Unit::idFor($this->validated('unit'))];
    }

    public function openDays(): int
    {
        return (int) ($this->validated('open_days') ?? 7);
    }
}
