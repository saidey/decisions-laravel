<?php

namespace DecisionsAi\Laravel\Sensors;

use Throwable;

class Users
{
    public function identity(bool $details = false): array
    {
        try {
            $id = auth()->id();
            $identity = ['user' => $id === null ? null : (string) $id];
            if ($details && config('decisions.user_details', false)) {
                $user = auth()->user();
                $identity['user_name'] = $user?->name;
                $identity['user_email'] = $user?->email;
            }

            return $identity;
        } catch (Throwable) {
            return ['user' => null];
        }
    }
}
