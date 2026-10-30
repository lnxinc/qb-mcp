<?php

namespace App\Services;

use App\Models\TeamInvitation;
use App\Models\User;

class RegistrationAccess
{
    /**
     * Determine if anyone may create an account without an invitation.
     *
     * The very first account is always allowed so a fresh install can be set up.
     */
    public function isOpen(): bool
    {
        return config('app.open_registration') || User::query()->doesntExist();
    }

    /**
     * Determine if the given email address may create an account.
     */
    public function allows(string $email, ?string $invitationCode = null): bool
    {
        if ($this->isOpen()) {
            return true;
        }

        $invitation = $this->pendingInvitation($invitationCode);

        return $invitation !== null && strtolower($invitation->email) === strtolower($email);
    }

    /**
     * Find the pending, unexpired invitation with the given code.
     */
    public function pendingInvitation(?string $invitationCode): ?TeamInvitation
    {
        if (! is_string($invitationCode) || $invitationCode === '') {
            return null;
        }

        return TeamInvitation::query()
            ->with('team')
            ->where('code', $invitationCode)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->first();
    }
}
