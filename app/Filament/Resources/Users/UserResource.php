<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Super admins only (UserPolicy). People join through invitation links, not through a "create user" form.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('nightguardian.users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('nightguardian.users.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('nightguardian.users.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('nightguardian.members.name'))->autofocus()->required()->maxLength(255),
                TextInput::make('email')->label(__('nightguardian.members.email'))->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                Select::make('locale')->label(__('nightguardian.users.locale'))->options(['en' => 'English', 'es' => 'Español'])->required(),
                Select::make('timezone')
                    ->label(__('nightguardian.projects.timezone'))
                    ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                    ->searchable()
                    ->required(),
                Toggle::make('is_super_admin')
                    ->label(__('nightguardian.users.super_admin'))
                    ->helperText(__('nightguardian.users.super_admin_help'))
                    ->disabled(fn (?User $record): bool => $record !== null && $record->is(auth()->user())),
                TextInput::make('password')
                    ->label(__('nightguardian.users.new_password'))
                    ->helperText(__('nightguardian.users.new_password_help'))
                    ->password()
                    ->revealable()
                    ->minLength(12)
                    ->dehydrated(fn (?string $state): bool => filled($state)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('projects'))
            ->columns([
                TextColumn::make('name')->label(__('nightguardian.members.name'))->weight('semibold')->searchable(),
                TextColumn::make('email')->label(__('nightguardian.members.email'))->searchable(),
                IconColumn::make('is_super_admin')->label(__('nightguardian.users.super_admin'))->boolean(),
                TextColumn::make('projects_count')->label(__('nightguardian.projects.plural'))->numeric(),
                TextColumn::make('locale')->label(__('nightguardian.users.locale'))->badge()->color('gray'),
            ])
            ->recordUrl(fn (User $record): string => static::getUrl('edit', ['record' => $record]))
            ->recordActions([
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
