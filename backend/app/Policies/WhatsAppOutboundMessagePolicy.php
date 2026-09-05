<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsAppOutboundMessage;

class WhatsAppOutboundMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdminUser() || $user->isDirectorUser();
    }

    public function view(User $user, WhatsAppOutboundMessage $whatsAppOutboundMessage): bool
    {
        return $user->isAdminUser() || $user->isDirectorUser();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, WhatsAppOutboundMessage $whatsAppOutboundMessage): bool
    {
        return false;
    }

    public function delete(User $user, WhatsAppOutboundMessage $whatsAppOutboundMessage): bool
    {
        return false;
    }
}
