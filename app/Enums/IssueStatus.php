<?php

namespace App\Enums;

enum IssueStatus: string
{
    case New = 'new';
    case Regressed = 'regressed';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
}
