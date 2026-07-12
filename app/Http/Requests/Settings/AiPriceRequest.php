<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiPriceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'model_prefix' => ['required', 'string', 'max:100'],
            'input_price' => ['required', 'numeric', 'min:0', 'max:9999.9999'],
            'output_price' => ['required', 'numeric', 'min:0', 'max:9999.9999'],
            'effective_from' => [
                'required',
                'date',
                Rule::unique('ai_prices', 'effective_from')
                    ->where('model_prefix', (string) $this->string('model_prefix'))
                    ->ignore($this->route('price')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'effective_from.unique' => __('A price for this model already starts on this date.'),
        ];
    }
}
