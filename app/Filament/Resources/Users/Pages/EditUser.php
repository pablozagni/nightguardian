<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Pages\BaseEditRecord;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;

class EditUser extends BaseEditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * is_super_admin is deliberately not mass assignable on the model, so it is forced here.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->forceFill($data)->save();

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
