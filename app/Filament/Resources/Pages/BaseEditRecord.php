<?php

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\EditRecord;

abstract class BaseEditRecord extends EditRecord
{
    protected function getRedirectUrl(): ?string
    {
        return $this->previousUrl ?? static::getResource()::getUrl('index');
    }
}
