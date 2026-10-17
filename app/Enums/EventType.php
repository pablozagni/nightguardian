<?php

namespace App\Enums;

enum EventType: string
{
    case Exception = 'exception';
    case Log = 'log';
    case JobFailed = 'job_failed';
    case ScheduleFailed = 'schedule_failed';
    case SlowRequest = 'slow_request';
    case SlowQuery = 'slow_query';
    case Deploy = 'deploy';
    case Feedback = 'feedback';
    case Test = 'test';
}
