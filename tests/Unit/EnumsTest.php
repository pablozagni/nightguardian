<?php

use App\Enums\Level;
use App\Enums\ProjectRole;

it('orders PSR-3 levels by severity', function () {
    expect(Level::Debug->severity())->toBe(0)
        ->and(Level::Emergency->severity())->toBe(7)
        ->and(Level::Critical->atLeast(Level::Error))->toBeTrue()
        ->and(Level::Warning->atLeast(Level::Error))->toBeFalse();
});

it('treats project roles as cumulative', function () {
    expect(ProjectRole::Admin->atLeast(ProjectRole::Member))->toBeTrue()
        ->and(ProjectRole::Member->atLeast(ProjectRole::Member))->toBeTrue()
        ->and(ProjectRole::Viewer->atLeast(ProjectRole::Member))->toBeFalse();
});
