<?php

namespace App\Http\Requests;

use App\Exceptions\OidcLoginFailed;
use App\Models\Setting;
use App\Services\Oidc\OidcLoginClient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;

class StoreOidcSettings extends FormRequest
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
            'oidc_enabled' => 'nullable|boolean',
            'oidc_issuer' => 'nullable|required_if_accepted:oidc_enabled|url:http,https|max:191',
            'oidc_client_id' => 'nullable|required_if_accepted:oidc_enabled|string|max:191',
            'oidc_client_secret' => 'nullable|string|max:1000',
            'oidc_clear_client_secret' => 'nullable|boolean',
            'oidc_scopes' => 'nullable|string|max:191',
            'oidc_username_claim' => 'nullable|string|max:191',
            'oidc_groups_claim' => 'nullable|string|max:191',
            'oidc_button_label' => 'nullable|string|max:191',
            'oidc_forcelogin' => 'nullable|boolean',
            'oidc_slo' => 'nullable|boolean',
            'oidc_provision_users' => 'nullable|boolean',
        ];
    }

    /**
     * Refuse to switch OIDC on against an issuer whose discovery document
     * can't be loaded: with "primary login" enabled, a typo would otherwise
     * send every visitor into an error page.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || ! $this->boolean('oidc_enabled')) {
                    return;
                }

                $probe = (new Setting)->forceFill([
                    'oidc_issuer' => $this->input('oidc_issuer'),
                    'oidc_client_id' => $this->input('oidc_client_id'),
                ]);

                try {
                    (new OidcLoginClient($probe))->discovery(refresh: true);
                } catch (OidcLoginFailed $e) {
                    Log::warning('[OIDC settings] Discovery failed: '.$e->getMessage());
                    $validator->errors()->add('oidc_issuer', trans('admin/settings/general.oidc_discovery_failed'));
                }
            },
        ];
    }
}
