<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = ContentTemplate::orderByDesc('updated_at')->get();
        return response()->json($templates);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_type' => 'required|in:initial,reply,closing',
            'category' => 'nullable|string|max:100',
            'subject' => 'nullable|string|max:500',
            'body' => 'required|string',
            'greetings' => 'nullable|array',
            'signoffs' => 'nullable|array',
            'variations' => 'nullable|array',
            'placeholders' => 'nullable|array',
            'warmup_stage' => 'nullable|in:ramp_up,plateau,maintenance,any',
            'cooldown_minutes' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $validated['content_fingerprint'] = md5($validated['body'] . ($validated['subject'] ?? ''));
        $template = ContentTemplate::create($validated);

        return response()->json($template, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $template = ContentTemplate::findOrFail($id);

        $validated = $request->validate([
            'template_type' => 'sometimes|in:initial,reply,closing',
            'category' => 'nullable|string|max:100',
            'subject' => 'nullable|string|max:500',
            'body' => 'sometimes|string',
            'greetings' => 'nullable|array',
            'signoffs' => 'nullable|array',
            'variations' => 'nullable|array',
            'placeholders' => 'nullable|array',
            'warmup_stage' => 'nullable|in:ramp_up,plateau,maintenance,any',
            'cooldown_minutes' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['body']) || isset($validated['subject'])) {
            $validated['content_fingerprint'] = md5(
                ($validated['body'] ?? $template->body) . ($validated['subject'] ?? $template->subject ?? '')
            );
        }

        $template->update($validated);
        return response()->json($template);
    }

    public function destroy(int $id): JsonResponse
    {
        ContentTemplate::findOrFail($id)->delete();
        return response()->json(['message' => 'Template deleted']);
    }
}
