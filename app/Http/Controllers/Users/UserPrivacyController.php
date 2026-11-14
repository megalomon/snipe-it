<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\AnonymizeUserAction;
use App\Actions\Users\ExportPersonalDataAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Data protection actions on a single user: downloading everything stored
 * about them, and erasing it. Both are reserved for superusers.
 */
class UserPrivacyController extends Controller
{
    /**
     * Download the user's personal data as a JSON file.
     */
    public function export(User $user): Response
    {
        abort_unless(auth()->user()?->isSuperUser(), 403);

        $export = ExportPersonalDataAction::run($user, auth()->user());

        return response()->json($export, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Content-Disposition', 'attachment; filename="personal-data-user-'.$user->id.'-'.now()->format('Y-m-d').'.json"');
    }

    /**
     * Irreversibly anonymize the user's personal data.
     */
    public function anonymize(User $user): RedirectResponse
    {
        abort_unless(auth()->user()?->isSuperUser(), 403);

        if (config('app.lock_passwords')) {
            return redirect()->route('users.show', $user)->with('error', trans('general.feature_disabled'));
        }

        if ($user->id === auth()->id()) {
            return redirect()->route('users.show', $user)->with('error', trans('admin/users/message.anonymize.not_self'));
        }

        if ($user->anonymized_at) {
            return redirect()->route('users.show', $user)->with('error', trans('admin/users/message.anonymize.already'));
        }

        AnonymizeUserAction::run($user, auth()->user());

        return redirect()->route('users.show', $user)->with('success', trans('admin/users/message.anonymize.success'));
    }
}
