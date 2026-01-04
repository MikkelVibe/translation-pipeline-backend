<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $user = User::first();

        if (!$user) {
            return response()->json([
                'data' => [
                    'max_retries' => 3,
                    'retry_delay' => 5000,
                    'score_threshold' => 70,
                    'manual_check_threshold' => 60,
                ],
            ]);
        }

        $settings = Setting::firstOrCreate(
            ['user_id' => $user->id],
            [
                'max_retries' => 3,
                'retry_delay' => 5000,
                'score_threshold' => 70,
                'manual_check_threshold' => 60,
            ]
        );

        return response()->json(['data' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'max_retries' => 'sometimes|integer|min:1|max:10',
            'retry_delay' => 'sometimes|integer|min:1000|max:60000',
            'score_threshold' => 'sometimes|integer|min:0|max:100',
            'manual_check_threshold' => 'sometimes|integer|min:0|max:100',
        ]);

        $user = User::first();

        if (!$user) {
            return response()->json(['error' => 'No user found'], 404);
        }

        $settings = Setting::firstOrCreate(
            ['user_id' => $user->id],
            [
                'max_retries' => 3,
                'retry_delay' => 5000,
                'score_threshold' => 70,
                'manual_check_threshold' => 60,
            ]
        );

        $settings->update($validated);

        return response()->json(['data' => $settings]);
    }
}
