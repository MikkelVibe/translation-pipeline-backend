<?php

namespace App\Http\Controllers\Api;

use App\Enums\JobItemStatus;
use App\Http\Controllers\Controller;
use App\Models\Prompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PromptController extends Controller
{
    public function index(): JsonResponse
    {
        $prompts = Prompt::all()->map(function ($prompt) {
            $prompt->is_active = $this->isPromptActive($prompt);

            return $prompt;
        });

        return response()->json(['data' => $prompts]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $prompt = Prompt::create($validated);

        return response()->json(['data' => $prompt], 201);
    }

    public function show(Prompt $prompt): JsonResponse
    {
        $prompt->is_active = $this->isPromptActive($prompt);

        return response()->json(['data' => $prompt]);
    }

    public function update(Request $request, Prompt $prompt): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
        ]);

        $prompt->update($validated);

        return response()->json(['data' => $prompt]);
    }

    public function destroy(Prompt $prompt): JsonResponse
    {
        // Check if prompt is being used by active jobs
        if ($this->isPromptActive($prompt)) {
            throw ValidationException::withMessages([
                'prompt' => ['Cannot delete a prompt that is being used by active jobs.'],
            ]);
        }

        $prompt->delete();

        return response()->json(null, 204);
    }

    private function isPromptActive(Prompt $prompt): bool
    {
        return $prompt->jobs()
            ->whereHas('items', fn ($q) => $q->whereIn('status', [JobItemStatus::Queued, JobItemStatus::Processing])
            )
            ->exists();
    }
}
