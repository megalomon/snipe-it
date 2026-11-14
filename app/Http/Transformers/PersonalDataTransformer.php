<?php

namespace App\Http\Transformers;

use App\Enums\ActionType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Collects everything Snipe-IT stores about one person into a single
 * machine-readable document, for requests of access and portability
 * (Art. 15 and 20 GDPR).
 *
 * Data is read straight from the tables rather than through the models so
 * that company scoping, soft deletes and accessors can't hide anything.
 * Secrets (password hash, 2FA secret, tokens) are never exported, only
 * whether they exist. Log entries the person created about other people
 * are listed without their notes and change details, since those describe
 * third parties (Art. 15(4) GDPR).
 */
class PersonalDataTransformer
{
    /**
     * Columns of the users table that are secrets and are therefore only
     * reported as present or absent.
     */
    private const SECRET_COLUMNS = [
        'password',
        'remember_token',
        'two_factor_secret',
        'persist_code',
        'reset_password_code',
        'activation_code',
    ];

    /**
     * @return array<string, mixed>
     */
    public function transformPersonalData(User $user): array
    {
        $loginNames = array_values(array_filter([$user->username, $user->email]));

        return [
            'generated_at' => now()->toIso8601String(),
            'user_id' => $user->id,
            'profile' => $this->profile($user),
            'groups' => $this->rows(
                DB::table('users_groups')
                    ->join('permission_groups', 'permission_groups.id', '=', 'users_groups.group_id')
                    ->where('users_groups.user_id', $user->id)
                    ->select('permission_groups.id', 'permission_groups.name')
            ),
            'companies' => $this->rows(
                DB::table('company_user')
                    ->join('companies', 'companies.id', '=', 'company_user.company_id')
                    ->where('company_user.user_id', $user->id)
                    ->select('companies.id', 'companies.name', 'company_user.created_at')
            ),
            'assigned_items' => [
                'assets' => $this->rows(
                    DB::table('assets')
                        ->where('assigned_type', User::class)
                        ->where('assigned_to', $user->id)
                        ->whereNull('deleted_at')
                        ->select('id', 'asset_tag', 'name', 'serial', 'last_checkout', 'expected_checkin')
                ),
                'licenses' => $this->rows(
                    DB::table('license_seats')
                        ->join('licenses', 'licenses.id', '=', 'license_seats.license_id')
                        ->where('license_seats.assigned_to', $user->id)
                        ->whereNull('license_seats.deleted_at')
                        ->select('license_seats.id as seat_id', 'licenses.id as license_id', 'licenses.name', 'license_seats.updated_at as assigned_at')
                ),
                'accessories' => $this->rows(
                    DB::table('accessories_checkout')
                        ->join('accessories', 'accessories.id', '=', 'accessories_checkout.accessory_id')
                        ->where('accessories_checkout.assigned_type', User::class)
                        ->where('accessories_checkout.assigned_to', $user->id)
                        ->select('accessories_checkout.id', 'accessories.id as accessory_id', 'accessories.name', 'accessories_checkout.note', 'accessories_checkout.created_at as assigned_at')
                ),
                'consumables' => $this->rows(
                    DB::table('consumables_users')
                        ->join('consumables', 'consumables.id', '=', 'consumables_users.consumable_id')
                        ->where('consumables_users.assigned_to', $user->id)
                        ->select('consumables_users.id', 'consumables.id as consumable_id', 'consumables.name', 'consumables_users.note', 'consumables_users.created_at as assigned_at')
                ),
            ],
            'acceptances' => $this->rows(
                DB::table('checkout_acceptances')
                    ->where('assigned_to_id', $user->id)
                    ->select('id', 'checkoutable_type', 'checkoutable_id', 'qty', 'note', 'signature_filename', 'stored_eula_file', 'accepted_at', 'declined_at', 'created_at', 'deleted_at')
            ),
            'checkout_requests' => $this->rows(
                DB::table('checkout_requests')
                    ->where('user_id', $user->id)
                    ->select('id', 'requestable_type', 'requestable_id', 'quantity', 'fulfilled_quantity', 'state', 'notes', 'start_date', 'end_date', 'created_at', 'canceled_at', 'fulfilled_at', 'deleted_at')
            ),
            'asset_requests' => $this->rows(
                DB::table('requested_assets')
                    ->where('user_id', $user->id)
                    ->select('id', 'asset_id', 'notes', 'accepted_at', 'denied_at', 'created_at')
            ),
            'maintenances' => $this->rows(
                DB::table('maintenances')
                    ->where(function (Builder $query) use ($user) {
                        $query->where(fn (Builder $q) => $q->where('checked_out_to_type', User::class)->where('checked_out_to_id', $user->id))
                            ->orWhere('responsible_party_id', $user->id);
                    })
                    ->select('id', 'name', 'item_type', 'item_id', 'checked_out_to_id', 'responsible_party_id', 'start_date', 'completed_at', 'created_at')
            ),
            'manages' => [
                'user_ids' => DB::table('users')->where('manager_id', $user->id)->pluck('id')->all(),
                'locations' => $this->rows(DB::table('locations')->where('manager_id', $user->id)->select('id', 'name')),
                'departments' => $this->rows(DB::table('departments')->where('manager_id', $user->id)->select('id', 'name')),
            ],
            'uploaded_files' => $this->rows(
                DB::table('action_logs')
                    ->where('item_type', User::class)
                    ->where('item_id', $user->id)
                    ->where('action_type', ActionType::Uploaded->value)
                    ->whereNotNull('filename')
                    ->select('id', 'filename', 'note', 'created_at')
            ),
            'activity' => [
                'about_user' => $this->rows(
                    DB::table('action_logs')
                        ->where(function (Builder $query) use ($user) {
                            $query->where(fn (Builder $q) => $q->where('item_type', User::class)->where('item_id', $user->id))
                                ->orWhere(fn (Builder $q) => $q->where('target_type', User::class)->where('target_id', $user->id));
                        })
                        ->orderBy('id')
                        ->select('id', 'action_type', 'item_type', 'item_id', 'target_type', 'target_id', 'note', 'log_meta', 'filename', 'accept_signature', 'stored_eula', 'action_date', 'action_source', 'remote_ip', 'user_agent', 'created_by', 'created_at')
                ),
                'performed_by_user' => $this->rows(
                    DB::table('action_logs')
                        ->where('created_by', $user->id)
                        ->orderBy('id')
                        ->select('id', 'action_type', 'item_type', 'item_id', 'target_type', 'target_id', 'action_source', 'remote_ip', 'user_agent', 'created_at')
                ),
            ],
            'login_attempts' => $loginNames === [] ? [] : $this->rows(
                DB::table('login_attempts')
                    ->whereIn('username', $loginNames)
                    ->orderBy('id')
                    ->select('id', 'username', 'successful', 'remote_ip', 'user_agent', 'created_at')
            ),
            'api_tokens' => $this->rows(
                DB::table('oauth_access_tokens')
                    ->where('user_id', $user->id)
                    ->select('name', 'scopes', 'revoked', 'created_at', 'expires_at')
            ),
            'password_reset_requests' => $user->email ? $this->rows(
                DB::table('password_resets')->where('email', $user->email)->select('created_at')
            ) : [],
            'saved_searches' => $this->rows(
                DB::table('saved_searches')
                    ->where('created_by', $user->id)
                    ->select('id', 'name', 'table_name', 'query_string', 'created_at')
            ),
            'report_templates' => $this->rows(
                DB::table('report_templates')
                    ->where('created_by', $user->id)
                    ->select('id', 'name', 'type', 'is_shared', 'created_at', 'deleted_at')
            ),
            'calendar_events' => $this->rows(
                DB::table('calendar_events')
                    ->where('source_type', User::class)
                    ->where('source_id', $user->id)
                    ->select('event_type', 'source_field', 'start', 'end')
            ),
            'not_included' => [
                'Application log files and backups of this Snipe-IT installation',
                'Emails sent to the user (not stored by Snipe-IT)',
                'Signature images and acceptance PDFs themselves (listed by file name under acceptances and activity)',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        $row = (array) DB::table('users')->where('id', $user->id)->first();

        $profile = array_diff_key($row, array_flip(self::SECRET_COLUMNS));
        $profile['has_password'] = ! empty($row['password']) && $row['password'] !== $user->noPassword();
        $profile['has_two_factor_secret'] = ! empty($row['two_factor_secret']);

        return array_map(fn ($value) => $this->value($value), $profile);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(Builder $query): array
    {
        return $query->get()
            ->map(fn (object $row) => array_map(fn ($value) => $this->value($value), (array) $row))
            ->values()
            ->all();
    }

    private function value(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        // log_meta holds JSON; hand it over as structure, not as a string.
        if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $value;
    }
}
