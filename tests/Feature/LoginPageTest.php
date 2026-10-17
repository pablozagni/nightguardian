<?php

it('shows the Filament login page at the root', function () {
    $this->get('/login')->assertOk()->assertSee('NightGuardian');
});

it('redirects guests from the panel root to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});
