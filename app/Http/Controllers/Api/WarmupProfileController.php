<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarmupProfileController extends Controller
{
    public function index(): JsonResponse
    {
        $profiles = \App\Models\WarmupProfile::withCount('warmupCampaigns')
            ->orderBy('name')
            ->get();

        return response()->json($profiles);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'planned_duration_days' => 'required|integer|min:7|max:60',
            'daily_rules' => 'required|array',
            'thread_length_distribution' => 'nullable|array',
            'reply_delay_distribution' => 'nullable|array',
            'provider_distribution' => 'nullable|array',
        ]);

        $profile = \App\Models\WarmupProfile::create($validated);
        return response()->json($profile, 201);
    }

    public function show(int $id): JsonResponse
    {
        $profile = \App\Models\WarmupProfile::withCount('warmupCampaigns')
            ->findOrFail($id);

        return response()->json($profile);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $profile = \App\Models\WarmupProfile::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'planned_duration_days' => 'sometimes|integer|min:7|max:60',
            'daily_rules' => 'sometimes|array',
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

        if ($profile->warmupCampaigns()->where('status', 'active')->exists()) {
            return response()->json(['error' => 'Cannot delete profile with active campaigns'], 422);
        }

        $profile->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
