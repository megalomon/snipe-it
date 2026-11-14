<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePrivacySettings extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('superuser');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'privacy_truncate_ip_addresses' => 'nullable|boolean',
            'privacy_ip_retention_days' => 'nullable|integer|min:1|max:36500',
            'privacy_login_attempts_retention_days' => 'nullable|integer|min:1|max:36500',
            'privacy_anonymize_deleted_users_days' => 'nullable|integer|min:1|max:36500',
        ];
    }
}
