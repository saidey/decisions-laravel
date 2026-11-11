<?php

namespace DecisionsAi\Laravel\Sensors;

use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

class Users
{
    // Requests carry the name only, so dashboards can label users; exceptions also carry the email.
    public function identity(bool $details = false, bool $email = true, ?Authenticatable $user = null): array
    {
        try {
            $id = $user ? $user->getAuthIdentifier() : auth()->id();
            $identity = ['user' => $id === null ? null : (string) $id];
            if ($details && config('decisions.user_details', false)) {
                $user ??= auth()->user();
                $identity['user_name'] = $user?->name;
                if ($email) {
                    $identity['user_email'] = $user?->email;
                }
            }

            return $identity;
        } catch (Throwable) {
            return ['user' => null];
        }
    }
}
