<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EventType: string implements HasColor, HasLabel
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

    public function getLabel(): string
    {
        return __('nightguardian.event_types.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Exception, self::JobFailed, self::ScheduleFailed => 'danger',
            self::Log, self::SlowRequest, self::SlowQuery => 'warning',
            self::Deploy, self::Test => 'info',
            self::Feedback => 'success',
        };
    }
}
