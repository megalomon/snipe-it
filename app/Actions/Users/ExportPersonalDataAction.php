<?php

namespace App\Actions\Users;

use App\Enums\ActionType;
use App\Http\Transformers\PersonalDataTransformer;
use App\Models\Actionlog;
use App\Models\User;

class ExportPersonalDataAction
{
    /**
     * Builds the personal data export of a user and records in the user's
     * history who requested it, so that disclosures stay traceable.
     *
     * @return array<string, mixed>
     */
    public static function run(User $user, ?User $requestedBy): array
    {
        $export = (new PersonalDataTransformer)->transformPersonalData($user);

        $log = new Actionlog;
        $log->item_type = User::class;
        $log->item_id = $user->id;
        $log->target_type = User::class;
        $log->target_id = $user->id;
        $log->created_by = $requestedBy?->id;
        $log->logaction(ActionType::PersonalDataExported);

        return $export;
    }
}
