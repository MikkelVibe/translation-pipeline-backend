<?php

namespace App\Http\Controllers\Translation;

use App\Http\Controllers\Controller;
use App\Services\RabbitMQService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
class TranslationController extends Controller {
   public function __construct(
      private RabbitMQService $rabbit
   ) {}

   public function publish(Request $request): JsonResponse {
      $request->validate(
         rules: [
            'text' => ['required','string'],
         ]
      );

      $this->rabbit->publish(
         queue: 'translation_queue', 
         payload: [
         'text' => $request->text,
      ]);

      return response()->json(
         data: ['message' => 'Translation job queued'], 
         status: 202
      );
   }
}