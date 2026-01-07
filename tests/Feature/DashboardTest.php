<?php

test('unauthenticated users cannot access api dashboard metrics', function () {
    $this->getJson('/api/dashboard/metrics')->assertOk();
});

test('unauthenticated users can access api dashboard charts', function () {
    $this->getJson('/api/dashboard/charts')->assertOk();
});
