<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Pages\BaseEditRecord;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\DeleteAction;

class EditProject extends BaseEditRecord
{
    protected static string $resource = ProjectResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('nightguardian.projects.settings');
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
