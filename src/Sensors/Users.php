<?php

namespace DecisionsAi\Laravel\Sensors;

use Throwable;

class Users
{
    // Requests carry the name only, so dashboards can label users; exceptions also carry the email.
    public function identity(bool $details = false, bool $email = true): array
    {
        try {
            $id = auth()->id();
            $identity = ['user' => $id === null ? null : (string) $id];
            if ($details && config('decisions.user_details', false)) {
                $user = auth()->user();
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
