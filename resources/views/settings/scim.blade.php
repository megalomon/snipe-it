@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/settings/general.scim_title') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

    <x-container class="col-sm-10 col-sm-offset-1 col-md-8 col-md-offset-2">
        <x-form :route="route('settings.scim.save')">
            <x-box>
                <x-slot:header>
                    <x-icon type="scim"/> {{ trans('admin/settings/general.scim_title') }}
                </x-slot:header>

                {{-- Single demo-mode banner. Renders nothing outside demo mode. --}}
                <x-demo-callout />

                <div class="col-md-12">

                    {{-- Read-only display, so hand-rolled rather than <x-form.row>. --}}
                    <div class="form-group">
                        <div class="col-md-3 control-label">
                            <strong>{{ trans('admin/settings/general.scim_base_url') }}</strong>
                        </div>
                        <div class="col-md-8">
                            <p class="form-control-static"><code>{{ $scimBaseUrl }}</code></p>
                            <p class="help-block">{{ trans('admin/settings/general.scim_base_url_help') }}</p>
                        </div>
                    </div>

                    <x-form.checkbox-row
                        name="scim_existing_groups_only"
                        :section_label="trans('admin/settings/general.scim_groups')"
                        :label="trans('admin/settings/general.scim_existing_groups_only')"
                        :help_text="trans('admin/settings/general.scim_existing_groups_only_help')"
                        :item="$setting"
                        :disabled="config('app.lock_passwords') === true"
                    />

                    <x-form.checkbox-row
                        name="scim_require_group_membership"
                        :section_label="trans('admin/settings/general.scim_accounts')"
                        :label="trans('admin/settings/general.scim_require_group_membership')"
                        :help_text="trans('admin/settings/general.scim_require_group_membership_help')"
                        :item="$setting"
                        :disabled="config('app.lock_passwords') === true"
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
