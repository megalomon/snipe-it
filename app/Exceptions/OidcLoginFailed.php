<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised for any failure in the OpenID Connect browser login flow
 * (discovery, token exchange, ID token validation). The message is for
 * the server log only and must never be shown to the user.
 */
class OidcLoginFailed extends Exception {}
