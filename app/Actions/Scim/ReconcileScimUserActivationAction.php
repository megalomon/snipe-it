<?php

namespace App\Actions\Scim;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ReconcileScimUserActivationAction
{
    /**
     * With "group membership required" enabled, an account managed via
     * SCIM is active only if the SCIM client reports it as active and it
     * belongs to at least one Snipe-IT group. Accounts that don't meet
     * both are deactivated, accounts that do are activated again.
     *
     * Only accounts linked to the SCIM client (externalId) are touched;
     * local accounts are left alone. Changes go through the model, so they
     * show up in the user's history.
     *
     * @return array{activated: int, deactivated: int}
     */
    public static function run(): array
    {
        $result = ['activated' => 0, 'deactivated' => 0];

        if (! Setting::getSettings()?->scim_require_group_membership) {
            return $result;
        }

        $managed = fn (): Builder => User::query()->whereNotNull('scim_externalid');

        $managed()
            ->where('activated', 1)
            ->where(fn (Builder $query) => $query->where('scim_active', false)->orWhereDoesntHave('groups'))
            ->each(function (User $user) use (&$result) {
                $user->activated = 0;
                $user->forceSave();
                $result['deactivated']++;
            });

        $managed()
            ->where('activated', 0)
            ->where('scim_active', true)
            ->whereHas('groups')
            ->each(function (User $user) use (&$result) {
                $user->activated = 1;
                $user->forceSave();
                $result['activated']++;
            });

        return $result;
    }
}
