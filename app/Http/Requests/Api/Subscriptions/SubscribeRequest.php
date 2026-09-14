<?php

namespace App\Http\Requests\Api\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Checks is_active here rather than relying on the controller's
            // findOrFail(), so an inactive/retired plan id fails validation
            // with a clear 422 instead of a generic 404 "no query results".
            'plan_id' => ['required', 'integer', Rule::exists('subscription_plans', 'id')->where('is_active', true)],
            'gateway' => ['required', 'in:stripe,paypal'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.exists' => 'This plan is not available.',
        ];
    }
}
