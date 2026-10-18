<?php

namespace App\Filament\Resources\Issues\Pages;

use App\Filament\Resources\Issues\IssueResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListIssues extends ListRecords
{
    protected static string $resource = IssueResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('nightguardian.issues.navigation');
    }
}
