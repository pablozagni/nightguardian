<?php

namespace App\Filament\Resources\Deploys;

use App\Filament\Resources\Deploys\Pages\ListDeploys;
use App\Filament\Resources\Issues\IssueResource;
use App\Filament\Support\ScopesToUserProjects;
use App\Models\Deploy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Deploys reported by `php artisan nightguardian:deploy`, newest first. Read-only.
 */
class DeployResource extends Resource
{
    protected static ?string $model = Deploy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('nightguardian.deploys.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('nightguardian.deploys.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('nightguardian.deploys.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return ScopesToUserProjects::restrict(parent::getEloquentQuery(), Auth::user());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('project'))
            ->defaultSort('deployed_at', 'desc')
            ->columns([
                TextColumn::make('deployed_at')
                    ->label(__('nightguardian.deploys.deployed_at'))
                    ->dateTime(timezone: IssueResource::timezone())
                    ->description(fn (Deploy $record): string => $record->deployed_at->diffForHumans())
                    ->sortable(),
                TextColumn::make('project.name')->label(__('nightguardian.projects.singular'))->badge()->color('gray'),
                TextColumn::make('environment')->label(__('nightguardian.issues.environment'))->badge(),
                TextColumn::make('release')->label(__('nightguardian.issues.release'))->searchable()->fontFamily('mono'),
                TextColumn::make('commit')->label(__('nightguardian.deploys.commit'))->limit(10)->placeholder('—')->fontFamily('mono'),
                TextColumn::make('branch')->label(__('nightguardian.deploys.branch'))->placeholder('—'),
                TextColumn::make('deployer')->label(__('nightguardian.deploys.deployer'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('project_id')
                    ->label(__('nightguardian.projects.singular'))
                    ->options(fn (): array => IssueResource::visibleProjects()->pluck('name', 'id')->all()),
                SelectFilter::make('environment')
                    ->label(__('nightguardian.issues.environment'))
                    ->options(fn (): array => static::getEloquentQuery()->distinct()->pluck('environment', 'environment')->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeploys::route('/'),
        ];
    }
}
