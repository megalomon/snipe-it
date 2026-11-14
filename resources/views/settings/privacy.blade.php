@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/settings/general.privacy_title') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

    <x-container class="col-sm-10 col-sm-offset-1 col-md-8 col-md-offset-2">
        <x-form :route="route('settings.privacy.save')">
            <x-box>
                <x-slot:header>
                    <x-icon type="privacy"/> {{ trans('admin/settings/general.privacy_title') }}
                </x-slot:header>

                {{-- Single demo-mode banner. Renders nothing outside demo mode. --}}
                <x-demo-callout />

                <div class="col-md-12">

                    <x-form.checkbox-row
                        name="privacy_truncate_ip_addresses"
                        :section_label="trans('admin/settings/general.privacy_ip_addresses')"
                        :label="trans('admin/settings/general.privacy_truncate_ip_addresses')"
                        :help_text="trans('admin/settings/general.privacy_truncate_ip_addresses_help')"
                        :item="$setting"
                        :disabled="config('app.lock_passwords') === true"
                    />

                    <x-form.row
                        :label="trans('admin/settings/general.privacy_ip_retention_days')"
                        name="privacy_ip_retention_days"
                        type="number"
                        min="1"
                        max="36500"
                        :item="$setting"
                        :help_text="trans('admin/settings/general.privacy_ip_retention_days_help')"
                        :disabled="config('app.lock_passwords')"
                        input_div_class="col-md-3"
                    />

                    <x-form.row
                        :label="trans('admin/settings/general.privacy_login_attempts_retention_days')"
                        name="privacy_login_attempts_retention_days"
                        type="number"
                        min="1"
                        max="36500"
                        :item="$setting"
                        :help_text="trans('admin/settings/general.privacy_login_attempts_retention_days_help')"
                        :disabled="config('app.lock_passwords')"
                        input_div_class="col-md-3"
                    />

                    <x-form.row
                        :label="trans('admin/settings/general.privacy_anonymize_deleted_users_days')"
                        name="privacy_anonymize_deleted_users_days"
                        type="number"
                        min="1"
                        max="36500"
                        :item="$setting"
                        :help_text="trans('admin/settings/general.privacy_anonymize_deleted_users_days_help')"
                        :disabled="config('app.lock_passwords')"
                        input_div_class="col-md-3"
                    />

                    <div class="form-group">
                        <div class="col-md-8 col-md-offset-3">
                            <p class="help-block">{{ trans('admin/settings/general.privacy_schedule_help') }}</p>
                        </div>
                    </div>

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
