<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Records every login, logout and failed login in the activity log, and
 * stamps the user's last login time and IP. Registered by event discovery
 * (each handle* method is bound to the event it type-hints).
 */
class LogAuthenticationEvents
{
    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->saveQuietly();

        activity('auth')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->event('login')
            ->withProperties(['ip' => request()->ip(), 'user_agent' => request()->userAgent()])
            ->log('Logged in');
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        activity('auth')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->event('logout')
            ->withProperties(['ip' => request()->ip()])
            ->log('Logged out');
    }

    public function handleFailed(Failed $event): void
    {
        $logger = activity('auth')
            ->event('login_failed')
            ->withProperties([
                'username' => $event->credentials['username'] ?? null,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

        if ($event->user instanceof User) {
            $logger->performedOn($event->user);
        }

        $logger->log('Failed login');
    }
}
