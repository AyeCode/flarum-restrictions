<?php

namespace AyeCode\FlarumRestrictions\Access;

use Flarum\User\User;

class LicenseChecker
{
    private function get_cookie_data()
    {
        // Add debug logging
        file_put_contents(
            storage_path('logs/cookie-debug.log'),
            "Cookies: " . print_r($_COOKIE, true) . "\n",
            FILE_APPEND
        );

        // For testing - return null or parse your actual cookie
        return null;
    }

    public function can_access(User $user = null): bool
    {
        // Debug log the check
        file_put_contents(
            storage_path('logs/cookie-debug.log'),
            "Checking access for user: " . ($user ? $user->id : 'guest') . "\n",
            FILE_APPEND
        );

        $cookie_data = $this->get_cookie_data();

        // For now return false to restrict everyone
        return false;
    }
}