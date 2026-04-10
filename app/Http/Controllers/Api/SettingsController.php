<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = SystemSetting::all()->groupBy('group')->map(function ($items) {
            return $items->pluck('value', 'key');
        });

        $user = Auth::user();

        return response()->json([
            'settings' => $settings,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'sometimes|array',
            'settings.*.key' => 'required|string|max:100',
            'settings.*.value' => 'nullable|string|max:1000',
            'settings.*.group' => 'sometimes|string|max:50',
        ]);

        if (isset($validated['settings'])) {
            foreach ($validated['settings'] as $item) {
                SystemSetting::set(
                    $item['key'],
                    $item['value'] ?? '',
                    $item['group'] ?? 'general'
                );
            }
        }

        return response()->json(['message' => 'Settings saved']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $user->id,
        ]);

        $user->update($validated);

        return response()->json(['message' => 'Profile updated', 'user' => $user->only('id', 'name', 'email')]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $user = Auth::user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['error' => 'Current password is incorrect'], 422);
        }

        $user->update(['password' => Hash::make($validated['new_password'])]);

        return response()->json(['message' => 'Password updated']);
    }
}
