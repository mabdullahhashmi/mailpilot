<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(): JsonResponse
    {
        $alerts = SystemAlert::where('is_dismissed', false)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($alerts);
    }

    public function unreadCount(): JsonResponse
    {
        $count = SystemAlert::where('is_read', false)
            ->where('is_dismissed', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(int $id): JsonResponse
    {
        $alert = SystemAlert::findOrFail($id);
        $alert->update(['is_read' => true]);
        return response()->json(['message' => 'Marked as read']);
    }

    public function markAllRead(): JsonResponse
    {
        SystemAlert::where('is_read', false)->update(['is_read' => true]);
        return response()->json(['message' => 'All marked as read']);
    }

    public function dismiss(int $id): JsonResponse
    {
        $alert = SystemAlert::findOrFail($id);
        $alert->update(['is_dismissed' => true]);
        return response()->json(['message' => 'Alert dismissed']);
    }
}
