<?php

namespace App\Ingest;

use App\Ingest\Types\DeployType;
use App\Ingest\Types\EventTypeHandler;
use App\Ingest\Types\ExceptionType;
use App\Ingest\Types\FeedbackType;
use App\Ingest\Types\JobFailedType;
use App\Ingest\Types\LogType;
use App\Ingest\Types\ScheduleFailedType;
use App\Ingest\Types\SlowQueryType;
use App\Ingest\Types\SlowRequestType;
use App\Ingest\Types\TestType;

class EventTypeRegistry
{
    /** @var array<string, EventTypeHandler> */
    private array $handlers = [];

    public function __construct()
    {
        foreach ([
            new ExceptionType, new LogType, new JobFailedType, new ScheduleFailedType, new SlowRequestType,
            new SlowQueryType, new DeployType, new FeedbackType, new TestType,
        ] as $handler) {
            $this->handlers[$handler->type()->value] = $handler;
        }
    }

    public function find(string $type): ?EventTypeHandler
    {
        return $this->handlers[$type] ?? null;
    }
}
