<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LanguageController extends Controller
{
    public function index(): JsonResponse
    {
        $languages = Language::all();

        return response()->json($languages);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:10|unique:languages,code',
            'name' => 'required|string|max:255',
        ]);

        $language = Language::create($validated);

        return response()->json($language, 201);
    }

    public function update(Request $request, Language $language): JsonResponse
    {
        $validated = $request->validate([
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:10',
                Rule::unique('languages', 'code')->ignore($language->id),
            ],
            'name' => 'sometimes|required|string|max:255',
        ]);

        $language->update($validated);

        return response()->json($language);
    }

    public function destroy(Language $language): JsonResponse
    {
        // Check if language is being used by any jobs
        $isInUse = $language->jobsAsSource()->exists() ||
                   $language->jobsAsTarget()->exists() ||
                   $language->translations()->exists();

        if ($isInUse) {
            throw ValidationException::withMessages([
                'language' => ['Cannot delete a language that is being used by jobs or translations.'],
            ]);
        }

        $language->delete();

        return response()->json(null, 204);
    }
}
