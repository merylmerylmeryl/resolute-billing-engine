<?php

namespace App\Http\Requests;

use App\Enums\EventStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
{
    /**
     * No authentication, since the spec doesn't ask for it.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Money must be non-negative with at most 2 decimals and fit decimal(14,2),
     * which is at most 12 whole digits. The final value is required for a final
     * event and forbidden for a pending one.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $money = ['numeric', 'min:0', 'decimal:0,2', 'max:999999999999.99'];

        return [
            'property_id' => ['required', 'integer', Rule::exists('properties', 'id')],
            'recorded_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'status' => ['required', Rule::enum(EventStatus::class)],
            'noticed_value' => ['required', ...$money],
            'final_value' => ['nullable', 'required_if:status,final', 'prohibited_if:status,pending', ...$money],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recorded_at.before_or_equal' => 'The date recorded cannot be in the future.',
            'final_value.required_if' => 'A final event needs a final value.',
            'final_value.prohibited_if' => 'A pending event cannot have a final value.',
            '*.decimal' => 'Use at most 2 decimal places.',
        ];
    }
}
