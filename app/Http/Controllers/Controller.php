<?php

namespace App\Http\Controllers;

abstract class Controller
{
    // Provide tenant helper: returns null for admin users so controllers can skip tenant scoping.
    protected function tenantUserId(): ?int
    {
        $user = auth()->user();
        return $user && method_exists($user, 'isAdmin') && $user->isAdmin() ? null : auth()->id();
    }

    protected function isAdminUser(): bool
    {
        $user = auth()->user();
        return $user && method_exists($user, 'isAdmin') && $user->isAdmin();
    }
}
