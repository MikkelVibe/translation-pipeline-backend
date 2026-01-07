<?php

it('returns a successful response', function () {
    $response = $this->getJson('/api');

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Translation Pipeline API',
            'version' => '1.0.0',
        ]);
});
