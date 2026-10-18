<?php

namespace App\Policies;

use App\Models\Save;
use App\Models\User;

/**
 * Saves are private to the user who created them.
 */
class SavePolicy
{
    public function view(User $user, Save $save): bool
    {
        return $save->user_id === $user->id;
    }

    public function update(User $user, Save $save): bool
    {
        return $save->user_id === $user->id;
    }

    public function delete(User $user, Save $save): bool
    {
        return $save->user_id === $user->id;
    }
}
