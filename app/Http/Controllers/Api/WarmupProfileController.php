<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WarmupProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarmupProfileController extends Controller
{
    public function index(): JsonResponse
    {
        $profiles = WarmupProfile::withCount('campaigns')
            ->when($this->tenantUserId(), fn ($query, $ownerId) => $query->where('user_id', $ownerId))
            ->orderBy('profile_name')
            ->get();

        return response()->json($profiles);
    }

    public function store(Request $request): JsonResponse
    {
        $ownerId = $this->tenantUserId() ?? auth()->id();

        $validated = $request->validate([
            'profile_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('warmup_profiles', 'profile_name')->where(fn ($query) => $query->where('user_id', $ownerId)),
            ],
            'description' => 'nullable|string',
            'profile_type' => 'sometimes|in:default,aggressive,conservative,maintenance,custom',
            'day_rules' => 'nullable|array',
            'thread_length_distribution' => 'nullable|array',
            'reply_delay_distribution' => 'nullable|array',
            'provider_distribution' => 'nullable|array',
        ]);

        $validated['user_id'] = $ownerId;
        $profile = WarmupProfile::create($validated);
        return response()->json($profile, 201);
    }

    public function show(int $id): JsonResponse
    {
        $profile = $this->ownedProfileQuery()->withCount('campaigns')
            ->findOrFail($id);

        return response()->json($profile);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $profile = $this->ownedProfileQuery()->findOrFail($id);

        $validated = $request->validate([
            'profile_name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('warmup_profiles', 'profile_name')
                    ->ignore($profile->id)
                    ->where(fn ($query) => $query->where('user_id', $profile->user_id)),
            ],
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
        $profile = $this->ownedProfileQuery()->findOrFail($id);

        if ($profile->campaigns()->where('status', 'active')->exists()) {
            return response()->json(['error' => 'Cannot delete profile with active campaigns'], 422);
        }

        $profile->delete();
        return response()->json(['message' => 'Deleted']);
    }

    private function tenantUserId(): ?int
    {
        $user = auth()->user();
        return $user && $user->isAdmin() ? null : auth()->id();
    }

    private function ownedProfileQuery()
    {
        return WarmupProfile::query()->when($this->tenantUserId(), fn ($query, $ownerId) => $query->where('user_id', $ownerId));
    }
}
