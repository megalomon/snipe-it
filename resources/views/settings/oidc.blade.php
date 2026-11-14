@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/settings/general.oidc_title') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

    <x-container class="col-sm-10 col-sm-offset-1 col-md-8 col-md-offset-2">
        <x-form :route="route('settings.oidc.save')">
            <x-box>
                <x-slot:header>
                    <x-icon type="oidc"/> {{ trans('admin/settings/general.oidc_title') }}
                </x-slot:header>

                {{-- Single demo-mode banner. Renders nothing outside demo mode. --}}
                <x-demo-callout />

                <div class="col-md-12">

                    {{-- URLs the provider's client must allow. Read-only
                         display, so hand-rolled rather than <x-form.row>. --}}
                    <div class="form-group">
                        <div class="col-md-3 control-label">
                            <strong>{{ trans('admin/settings/general.redirect_url') }}</strong>
                        </div>
                        <div class="col-md-8">
                            <p class="form-control-static"><code>{{ $redirectUri }}</code></p>
                            <p class="help-block">{{ trans('admin/settings/general.oidc_redirect_uri_help') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-md-3 control-label">
                            <strong>{{ trans('admin/settings/general.oidc_post_logout_redirect_uri') }}</strong>
                        </div>
                        <div class="col-md-8">
                            <p class="form-control-static"><code>{{ $postLogoutRedirectUri }}</code></p>
                            <p class="help-block">{{ trans('admin/settings/general.oidc_post_logout_redirect_uri_help') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-md-3 control-label">
                            <strong>{{ trans('admin/settings/general.oidc_backchannel_logout_url') }}</strong>
                        </div>
                        <div class="col-md-8">
                            <p class="form-control-static"><code>{{ $backchannelLogoutUrl }}</code></p>
                            <p class="help-block">{{ trans('admin/settings/general.oidc_backchannel_logout_url_help') }}</p>
                        </div>
                    </div>

                    {{-- Enable OIDC login --}}
                    <x-form.checkbox-row
                        name="oidc_enabled"
                        :section_label="trans('admin/settings/general.oidc_integration')"
                        :label="trans('admin/settings/general.oidc_enabled')"
                        :item="$setting"
                        :disabled="config('app.lock_passwords') === true"
                    />

                    <x-form.row
                        :label="trans('admin/settings/general.oidc_issuer')"
                        name="oidc_issuer"
                        type="url"
                        :item="$setting"
                        :help_text="trans('admin/settings/general.oidc_issuer_help')"
                        :placeholder="trans('general.example') . 'https://id.example.com/realms/opendesk'"
                        :disabled="config('app.lock_passwords')"
                        input_div_class="col-md-8"
                    />

                    <x-form.row
                        :label="trans('admin/settings/general.client_id')"
                        name="oidc_client_id"
                        type="text"
                        :item="$setting"
                        :disabled="config('app.lock_passwords')"
                        input_div_class="col-md-8"
                    />

                    {{-- The stored secret is never rendered back into the page. --}}
                    <x-form.row
                        :label="trans('admin/settings/general.client_secret')"
                        name="oidc_client_secret"
                        input_div_class="col-md-8"
                        :help_text="$setting->oidc_client_secret ? trans('admin/settings/general.oidc_client_secret_stored_help') : trans('admin/settings/general.oidc_client_secret_help')"
                    >
                        <x-slot:input>
                            <x-input.password
                                name="oidc_client_secret"
                                autocomplete="new-password"
                                :placeholder="$setting->oidc_client_secret ? '••••••••••••' : null"
                                :disabled="config('app.lock_passwords') === true"
                            />
                        </x-slot:input>
                    </x-form.row>

                    @if ($setting->oidc_client_secret)
                        <x-form.checkbox-row
                            name="oidc_clear_client_secret"
                            :label="trans('admin/settings/general.oidc_clear_client_secret')"
                            :checked="false"
                            :disabled="config('app.lock_passwords') === true"
                        />
                    @endif

                    <x-form.row
                        :label="trans('admin/settings/general.oidc_scopes')"
                        name="oidc_scopes"
                        type="text"
                        :item="$setting"
                        :help_text="trans('admin/settings/general.oidc_scopes_help')"
                        :placeholder="\App\Services\Oidc\OidcLoginClient::DEFAULT_SCOPES"
                        :disabled="config('app.lock_passwords')"
                        input_div_class="col-md-8"
                    />

                    <x-form.row
                        :label="trans('admin/settings/general.oidc_username_claim')"
                        name="oidc_username_claim"
                        type="text"
                        :item="$setting"
                        :help_text="trans('admin/settings/general.oidc_username_claim_help')"
                        :placeholder="\App\Services\Oidc\OidcLoginClient::DEFAULT_USERNAME_CLAIM"
                        :disabled="config('app.lock_passwords')"
                        input_div_class="col-md-8"
                    />

                    <x-form.row
                        :label="trans('admin/settings/general.oidc_button_label')"
                        name="oidc_button_label"
                        type="text"
                        :item="$setting"
                        :placeholder="trans('auth/general.oidc_login')"
                        :disabled="config('app.lock_passwords')"
                        input_div_class="col-md-8"
                    />

                    <x-form.checkbox-row
                        name="oidc_forcelogin"
                        :label="trans('admin/settings/general.oidc_forcelogin')"
                        :help_html="trans('admin/settings/general.oidc_forcelogin_help')"
                        :item="$setting"
                        :disabled="config('app.lock_passwords') === true"
                    />

                    <x-form.checkbox-row
                        name="oidc_slo"
                        :label="trans('admin/settings/general.oidc_slo')"
                        :help_text="trans('admin/settings/general.oidc_slo_help')"
                        :item="$setting"
                        :disabled="config('app.lock_passwords') === true"
                    />

                    <x-form.checkbox-row
                        name="oidc_provision_users"
                        :section_label="trans('admin/settings/general.oidc_provisioning')"
                        :label="trans('admin/settings/general.oidc_provision_users')"
                        :help_text="trans('admin/settings/general.oidc_provision_users_help')"
                        :item="$setting"
                        :disabled="config('app.lock_passwords') === true"
                    />

                    <x-form.row
                        :label="trans('admin/settings/general.oidc_groups_claim')"
                        name="oidc_groups_claim"
                        type="text"
                        :item="$setting"
                        :help_text="trans('admin/settings/general.oidc_groups_claim_help')"
                        :placeholder="trans('general.example') . 'groups'"
                        :disabled="config('app.lock_passwords')"
                        input_div_class="col-md-8"
                    />

                </div>

                {{-- Explicit footer preserves the demo-mode-disabled save state
                     that the default x-box.footer doesn't offer. --}}
                <x-slot:customfooter>
                    <div class="box-footer">
                        <div class="text-left col-md-6">
                            <a class="btn btn-link text-left" href="{{ route('settings.index') }}">{{ trans('button.cancel') }}</a>
                        </div>
                        <div class="text-right col-md-6">
                            <x-button.submit class="btn-success" :disabled="config('app.lock_passwords') === true" />
                        </div>
                    </div>
                </x-slot:customfooter>
            </x-box>
        </x-form>
    </x-container>

@stop
