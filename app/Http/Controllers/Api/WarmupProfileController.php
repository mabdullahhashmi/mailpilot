<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarmupProfileController extends Controller
{
    public function index(): JsonResponse
    {
        $profiles = \App\Models\WarmupProfile::withCount('campaigns')
            ->orderBy('profile_name')
            ->get();

        return response()->json($profiles);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile_name' => 'required|string|max:255|unique:warmup_profiles,profile_name',
            'description' => 'nullable|string',
            'profile_type' => 'sometimes|in:default,aggressive,conservative,maintenance,custom',
            'day_rules' => 'nullable|array',
            'thread_length_distribution' => 'nullable|array',
            'reply_delay_distribution' => 'nullable|array',
            'provider_distribution' => 'nullable|array',
        ]);

        $profile = \App\Models\WarmupProfile::create($validated);
        return response()->json($profile, 201);
    }

    public function show(int $id): JsonResponse
    {
        $profile = \App\Models\WarmupProfile::withCount('campaigns')
            ->findOrFail($id);

        return response()->json($profile);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $profile = \App\Models\WarmupProfile::findOrFail($id);

        $validated = $request->validate([
            'profile_name' => 'sometimes|string|max:255|unique:warmup_profiles,profile_name,' . $id,
            'description' => 'nullable|string',
            'profile_type' => 'sometimes|in:default,aggressive,conservative,maintenance,custom',
            'day_rules' => 'sometimes|array',
            'thread_length_distribution' => 'nullable|array',
            'reply_delay_distribution' => 'nullable|array',
            'provider_distribution' => 'nullable|array',
        ]);

        $profile->update($validated);
        return response()->json($profile);
    }

    public function destroy(int $id): JsonResponse
    {
        $profile = \App\Models\WarmupProfile::findOrFail($id);

        if ($profile->campaigns()->where('status', 'active')->exists()) {
            return response()->json(['error' => 'Cannot delete profile with active campaigns'], 422);
        }

        $profile->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
