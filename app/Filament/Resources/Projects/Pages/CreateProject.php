<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Pages\BaseCreateRecord;
use App\Filament\Resources\Projects\ProjectResource;

class CreateProject extends BaseCreateRecord
{
    protected static string $resource = ProjectResource::class;

    // After creating a project the admin goes on to its tabs (members, tokens, channels…), not back to the list.
    protected function getRedirectUrl(): string
    {
        return ProjectResource::getUrl('edit', ['record' => $this->record]);
    }
}
