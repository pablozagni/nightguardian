<?php

namespace App\Ingest\Types;

use App\Enums\EventType;

/**
 * Same payload as an exception; stored and shown, never alerts.
 */
class TestType extends ExceptionType
{
    public function type(): EventType
    {
        return EventType::Test;
    }
}
