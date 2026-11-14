<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A logout received from the OpenID Connect provider via back-channel
 * logout. See EndOidcSessionsLoggedOutAtProvider for how it is enforced.
 */
class OidcLogout extends Model
{
    protected $fillable = ['issuer', 'subject', 'sid', 'logged_out_at'];

    protected $casts = [
        'logged_out_at' => 'datetime',
    ];
}
