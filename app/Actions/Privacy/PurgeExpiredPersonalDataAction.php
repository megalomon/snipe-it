<?php

namespace App\Actions\Privacy;

use App\Actions\Users\AnonymizeUserAction;
use App\Exceptions\UserStillHasAssignments;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurgeExpiredPersonalDataAction
{
    /**
     * Applies the retention periods from the privacy settings: strips IP
     * addresses and user agents from older activity log entries, deletes
     * older login attempts and anonymizes users that have been deleted for
     * longer than the configured period. A period left empty keeps the data.
     *
     * @return array{ip_addresses_removed: int, login_attempts_deleted: int, users_anonymized: int, users_skipped: int}
     */
    public static function run(Setting $settings): array
    {
        $result = [
            'ip_addresses_removed' => 0,
            'login_attempts_deleted' => 0,
            'users_anonymized' => 0,
            'users_skipped' => 0,
        ];

        if ($settings->privacy_ip_retention_days) {
            $result['ip_addresses_removed'] = DB::table('action_logs')
                ->where('created_at', '<', now()->subDays($settings->privacy_ip_retention_days))
                ->where(fn ($query) => $query->whereNotNull('remote_ip')->orWhereNotNull('user_agent'))
                ->update(['remote_ip' => null, 'user_agent' => null]);
        }

        if ($settings->privacy_login_attempts_retention_days) {
            $result['login_attempts_deleted'] = DB::table('login_attempts')
                ->where('created_at', '<', now()->subDays($settings->privacy_login_attempts_retention_days))
                ->delete();
        }

        if ($settings->privacy_anonymize_deleted_users_days) {
            User::onlyTrashed()
                ->whereNull('anonymized_at')
                ->where('deleted_at', '<', now()->subDays($settings->privacy_anonymize_deleted_users_days))
                ->orderBy('id')
                ->each(function (User $user) use (&$result) {
                    try {
                        AnonymizeUserAction::run($user);
                        $result['users_anonymized']++;
                    } catch (UserStillHasAssignments) {
                        Log::warning('Privacy cleanup skipped deleted user '.$user->id.': items are still assigned to them.');
                        $result['users_skipped']++;
                    }
                });
        }

        return $result;
    }
}
