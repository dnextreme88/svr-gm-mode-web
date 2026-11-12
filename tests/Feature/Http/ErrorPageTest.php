<?php

use Inertia\Testing\AssertableInertia as Assert;

/*
| UI audit (2026-11-12): a missing page shows the GM Mode error page with a
| way back, keeping its status; JSON callers still get JSON.
*/

it('shows the GM Mode error page for a missing career', function () {
    $this->get('/gm/saves/999/schedule')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('gm/error')->where('status', 404));
});

it('keeps a JSON answer for fetch callers', function () {
    $this->getJson('/gm/saves/999/schedule')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});
