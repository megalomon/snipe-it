<?php

namespace App\Actions\Users;

use App\Enums\ActionType;
use App\Enums\FileStorage;
use App\Models\Actionlog;
use App\Models\CalendarEvent;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AnonymizeUserAction
{
    /**
     * Erases a user's personal data while keeping the account row, so that
     * the asset history (who had which device when) stays intact but no
     * longer points at an identifiable person (Art. 17 GDPR).
     *
     * The profile is overwritten, the account is deactivated and deleted,
     * and the identifiers the user can be traced by (name, username, email,
     * employee number) are replaced in the notes and change records of the
     * activity log. IP addresses and user agents of every log entry about or
     * by the user are removed, as are login attempts, password reset tokens,
     * API tokens, private saved searches and report templates, calendar
     * entries, the avatar and uploaded files.
     *
     * Signed acceptances and their EULA PDFs are deliberately kept: they
     * document the handover of company property. Items still checked out
     * to the user stay assigned to the anonymized account, and users or
     * locations the person manages keep pointing at it, so nothing about
     * the inventory is lost and items can still be checked in afterwards.
     */
    public static function run(User $user, ?User $performedBy = null): void
    {
        // Stored values use the installation's language, not that of
        // whoever happens to trigger the anonymization.
        $locale = Setting::getSettings()?->locale;
        $identifiers = self::identifiersOf($user);
        $placeholder = trans('admin/users/general.anonymized_placeholder', [], $locale);
        $firstName = trans('admin/users/general.anonymized_first_name', [], $locale);
        $filesToDelete = [];

        DB::transaction(function () use ($user, $performedBy, $identifiers, $placeholder, $firstName, &$filesToDelete) {
            $filesToDelete = self::removeUploads($user);

            self::scrubActivityLog($user, $identifiers, $placeholder);

            $loginNames = array_values(array_filter([$user->username, $user->email]));
            if ($loginNames !== []) {
                DB::table('login_attempts')->whereIn('username', $loginNames)->delete();
            }

            if ($user->email) {
                DB::table('password_resets')->where('email', $user->email)->delete();
            }

            self::removeApiTokens($user);

            DB::table('saved_searches')->where('created_by', $user->id)->delete();
            DB::table('report_templates')->where('created_by', $user->id)->where('is_shared', 0)->delete();
            DB::table('throttle')->where('user_id', $user->id)->delete();
            CalendarEvent::withTrashed()
                ->where('source_type', User::class)
                ->where('source_id', $user->id)
                ->forceDelete();

            if ($user->oidc_issuer && $user->oidc_subject) {
                DB::table('oidc_logouts')
                    ->where('issuer', $user->oidc_issuer)
                    ->where('subject', $user->oidc_subject)
                    ->delete();
            }

            DB::table('users_groups')->where('user_id', $user->id)->delete();

            // Written with the query builder on purpose: a model save would
            // run the user observer, which records the old values (i.e. the
            // very personal data being erased) in a new log entry.
            $now = now();
            DB::table('users')->where('id', $user->id)->update([
                'first_name' => $firstName,
                'last_name' => '#'.$user->id,
                'display_name' => null,
                'username' => 'anonymized-'.$user->id,
                'email' => null,
                'password' => $user->noPassword(),
                'permissions' => null,
                'activated' => 0,
                'activation_code' => null,
                'activated_at' => null,
                'last_login' => null,
                'persist_code' => null,
                'reset_password_code' => null,
                'remember_token' => null,
                'website' => null,
                'country' => null,
                'gravatar' => null,
                'avatar' => null,
                'location_id' => null,
                'department_id' => null,
                'manager_id' => null,
                'phone' => null,
                'mobile' => null,
                'jobtitle' => null,
                'employee_num' => null,
                'notes' => null,
                'address' => null,
                'city' => null,
                'state' => null,
                'zip' => null,
                'locale' => null,
                'skin' => null,
                'nav_link_color' => null,
                'link_light_color' => null,
                'link_dark_color' => null,
                'two_factor_secret' => null,
                'two_factor_enrolled' => 0,
                'two_factor_optin' => 0,
                'ldap_import' => 0,
                'remote' => 0,
                'vip' => 0,
                'start_date' => null,
                'end_date' => null,
                'scim_externalid' => null,
                'oidc_issuer' => null,
                'oidc_subject' => null,
                'anonymized_at' => $now,
                'deleted_at' => $user->deleted_at ?? $now,
                'updated_at' => $now,
            ]);

            $log = new Actionlog;
            $log->item_type = User::class;
            $log->item_id = $user->id;
            $log->target_type = User::class;
            $log->target_id = $user->id;
            $log->created_by = $performedBy?->id;
            $log->logaction(ActionType::Anonymized);
        });

        // Files go only once the database changes are committed, so a
        // rolled-back anonymization never leaves records without files.
        $avatar = $user->avatar;
        if ($avatar && preg_match('#^https?://#i', $avatar) !== 1) {
            $filesToDelete[] = ['public', FileStorage::Avatars->publicPath().basename($avatar)];
        }

        foreach ($filesToDelete as [$disk, $path]) {
            if (Storage::disk($disk)->exists($path) && ! Storage::disk($disk)->delete($path)) {
                Log::warning('Anonymization of user '.$user->id.' could not delete file '.$path);
            }
        }
    }

    /**
     * The strings the user can be recognised by in free text, longest first
     * so that "Jane Doe" is replaced before a bare "Jane" could be.
     *
     * @return list<string>
     */
    private static function identifiersOf(User $user): array
    {
        $first = trim((string) $user->first_name);
        $last = trim((string) $user->last_name);

        $candidates = [
            trim($first.' '.$last),
            trim($last.' '.$first),
            $last !== '' && $first !== '' ? $last.', '.$first : '',
            (string) $user->display_name,
            (string) $user->username,
            (string) $user->email,
            (string) $user->employee_num,
        ];

        // Very short strings ("Al", "42") would mangle unrelated text.
        $identifiers = array_values(array_unique(array_filter(
            array_map('trim', $candidates),
            fn (string $candidate) => mb_strlen($candidate) >= 3
        )));

        usort($identifiers, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $identifiers;
    }

    /**
     * Removes IP addresses and user agents from every log entry about, for,
     * or by the user, and replaces the user's identifiers in their notes.
     * Change records of the user's own profile (old/new values of name,
     * email, phone, address ...) are dropped entirely.
     *
     * @param  list<string>  $identifiers
     */
    private static function scrubActivityLog(User $user, array $identifiers, string $placeholder): void
    {
        DB::table('action_logs')
            ->where(function ($query) use ($user) {
                $query->where(fn ($q) => $q->where('item_type', User::class)->where('item_id', $user->id))
                    ->orWhere(fn ($q) => $q->where('target_type', User::class)->where('target_id', $user->id))
                    ->orWhere('created_by', $user->id);
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($user, $identifiers, $placeholder) {
                foreach ($rows as $row) {
                    $isAboutUser = $row->item_type === User::class && (int) $row->item_id === $user->id;

                    $changes = [
                        'remote_ip' => null,
                        'user_agent' => null,
                        'note' => self::replaceIdentifiers($row->note, $identifiers, $placeholder),
                        'log_meta' => $isAboutUser
                            ? null
                            : self::replaceIdentifiers($row->log_meta, $identifiers, $placeholder),
                    ];

                    DB::table('action_logs')->where('id', $row->id)->update($changes);
                }
            });
    }

    /**
     * @param  list<string>  $identifiers
     */
    private static function replaceIdentifiers(?string $text, array $identifiers, string $placeholder): ?string
    {
        if ($text === null || $text === '' || $identifiers === []) {
            return $text;
        }

        foreach ($identifiers as $identifier) {
            $text = preg_replace(
                '/(?<![\p{L}\p{N}])'.preg_quote($identifier, '/').'(?![\p{L}\p{N}])/iu',
                $placeholder,
                $text
            ) ?? $text;
        }

        return $text;
    }

    /**
     * Drops the user's file uploads (and the log entries naming the files)
     * and returns the files to delete once the transaction has committed.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function removeUploads(User $user): array
    {
        $uploadLogs = DB::table('action_logs')
            ->where('item_type', User::class)
            ->where('item_id', $user->id)
            ->whereIn('action_type', [ActionType::Uploaded->value, ActionType::UploadDeleted->value]);

        $files = (clone $uploadLogs)
            ->where('action_type', ActionType::Uploaded->value)
            ->whereNotNull('filename')
            ->pluck('filename')
            ->map(fn (string $filename) => [
                config('filesystems.default'),
                FileStorage::Users->privateStorageKey().basename($filename),
            ])
            ->all();

        $uploadLogs->delete();

        return $files;
    }

    private static function removeApiTokens(User $user): void
    {
        $tokenIds = DB::table('oauth_access_tokens')->where('user_id', $user->id)->pluck('id');

        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->delete();
        DB::table('oauth_access_tokens')->where('user_id', $user->id)->delete();
        DB::table('oauth_auth_codes')->where('user_id', $user->id)->delete();
    }
}
