<?php

namespace App\Filament\Resources\Deploys\Pages;

use App\Filament\Resources\Deploys\DeployResource;
use Filament\Resources\Pages\ListRecords;

class ListDeploys extends ListRecords
{
    protected static string $resource = DeployResource::class;
}
