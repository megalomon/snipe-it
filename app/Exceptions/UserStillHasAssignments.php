<?php

namespace App\Exceptions;

use App\Models\User;
use Exception;

/**
 * Thrown when a user who still has items checked out, or who still manages
 * users or locations, is about to be anonymized. Those links have to be
 * resolved first, exactly as for deleting the user.
 */
class UserStillHasAssignments extends Exception
{
    public function __construct(public User $user)
    {
        parent::__construct('User '.$user->id.' still has assignments');
    }
}
