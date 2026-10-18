<?php

namespace App\Filament\Resources\Projects;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\RelationManagers\AlertChannelsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\AlertRulesRelationManager;
use App\Filament\Resources\Projects\RelationManagers\InvitationsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Projects\RelationManagers\TokensRelationManager;
use App\Filament\Support\ScopesToUserProjects;
use App\Models\Project;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('nightguardian.projects.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('nightguardian.projects.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('nightguardian.projects.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return ScopesToUserProjects::restrict(parent::getEloquentQuery(), Auth::user(), 'id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('nightguardian.projects.settings'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('nightguardian.projects.name'))
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, callable $set, callable $get, ?Project $record): void {
                            if ($record === null && blank($get('slug'))) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')
                        ->label(__('nightguardian.projects.slug'))
                        ->required()
                        ->maxLength(120)
                        ->alphaDash()
                        ->unique(ignoreRecord: true),
                    TextInput::make('platform')
                        ->label(__('nightguardian.projects.platform'))
                        ->placeholder('laravel')
                        ->maxLength(32),
                    Select::make('timezone')
                        ->label(__('nightguardian.projects.timezone'))
                        ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                        ->searchable()
                        ->required()
                        ->default('UTC'),
                    TextInput::make('retention_days')
                        ->label(__('nightguardian.projects.retention_days'))
                        ->helperText(__('nightguardian.projects.retention_help'))
                        ->numeric()->minValue(1)->maxValue(3650)->required()->default(30),
                    TimePicker::make('digest_time')
                        ->label(__('nightguardian.projects.digest_time'))
                        ->helperText(__('nightguardian.projects.digest_help'))
                        ->seconds(false),
                    TextInput::make('rate_limit_per_minute')
                        ->label(__('nightguardian.projects.rate_limit'))
                        ->helperText(__('nightguardian.projects.rate_limit_help'))
                        ->numeric()->minValue(1)->required()->default(600),
                ]),
            Section::make(__('nightguardian.projects.alerts_section'))
                ->description(__('nightguardian.projects.alerts_help'))
                ->columns(2)
                ->schema([
                    Select::make('settings.language')
                        ->label(__('nightguardian.projects.language'))
                        ->options(['en' => 'English', 'es' => 'Español'])
                        ->default('en'),
                    TextInput::make('settings.cooldown_minutes')
                        ->label(__('nightguardian.projects.cooldown'))
                        ->helperText(__('nightguardian.projects.cooldown_help'))
                        ->numeric()->minValue(1)->default(60),
                    TextInput::make('settings.max_alerts_per_hour')
                        ->label(__('nightguardian.projects.max_per_hour'))
                        ->helperText(__('nightguardian.projects.max_per_hour_help'))
                        ->numeric()->minValue(1)->default(20),
                    TextInput::make('settings.threshold_count')
                        ->label(__('nightguardian.projects.threshold_count'))
                        ->numeric()->minValue(1)->default(50),
                    TextInput::make('settings.threshold_minutes')
                        ->label(__('nightguardian.projects.threshold_minutes'))
                        ->helperText(__('nightguardian.projects.threshold_help'))
                        ->numeric()->minValue(1)->default(60),
                    Toggle::make('settings.digest_skip_empty')
                        ->label(__('nightguardian.projects.digest_skip_empty'))
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'issues as open_issues_count' => fn (Builder $q) => $q->whereIn('status', ['new', 'regressed'])->whereNull('merged_into_id'),
            ]))
            ->columns([
                TextColumn::make('name')->label(__('nightguardian.projects.name'))->searchable()->weight('semibold'),
                TextColumn::make('slug')->label(__('nightguardian.projects.slug'))->color('gray')->fontFamily('mono'),
                TextColumn::make('platform')->label(__('nightguardian.projects.platform'))->badge()->color('gray')->placeholder('—'),
                TextColumn::make('open_issues_count')->label(__('nightguardian.projects.open_issues'))->numeric(),
                TextColumn::make('retention_days')->label(__('nightguardian.projects.retention_days'))->suffix(' d'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            InvitationsRelationManager::class,
            TokensRelationManager::class,
            AlertChannelsRelationManager::class,
            AlertRulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
