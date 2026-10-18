<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('nightguardian.members.plural');
    }

    /**
     * Members are managed by project admins; UserPolicy (super admins only) is about the global users list.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('update', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label(__('nightguardian.members.name'))->weight('semibold')->searchable(),
                TextColumn::make('email')->label(__('nightguardian.members.email'))->searchable(),
                TextColumn::make('role')
                    ->label(__('nightguardian.members.role'))
                    ->state(fn (User $record): string => (string) $record->getAttribute('pivot')?->role)
                    ->formatStateUsing(fn (string $state): string => ProjectRole::from($state)->getLabel())
                    ->badge()
                    ->color(fn (string $state): string => ProjectRole::from($state)->getColor()),
            ])
            ->headerActions([
                // Not an AttachAction: its picker would list every user of the instance to a project admin.
                Action::make('addMember')
                    ->label(__('nightguardian.members.add'))
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->visible(fn (): bool => static::canViewForRecord($this->getOwnerRecord(), static::class))
                    ->modalHeading(__('nightguardian.members.add'))
                    ->modalDescription(__('nightguardian.members.add_help'))
                    ->schema([
                        TextInput::make('email')->label(__('nightguardian.members.email'))->email()->required(),
                        Select::make('role')
                            ->label(__('nightguardian.members.role'))
                            ->options(ProjectRole::class)
                            ->default(ProjectRole::Member->value)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        abort_unless(static::canViewForRecord($this->getOwnerRecord(), static::class), 403);

                        $project = $this->getOwnerRecord();
                        assert($project instanceof Project);
                        $user = User::query()->where('email', (string) $data['email'])->first();
                        $role = $data['role'] instanceof ProjectRole ? $data['role']->value : (string) $data['role'];

                        if ($user === null || $project->users()->whereKey($user->getKey())->exists()) {
                            Notification::make()->warning()->title(__('nightguardian.members.add_failed'))->send();

                            return;
                        }

                        $project->users()->attach($user->getKey(), ['role' => $role]);
                        Notification::make()->success()->title(__('nightguardian.members.added'))->send();
                    }),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label(__('nightguardian.members.change_role'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([
                        Select::make('role')
                            ->label(__('nightguardian.members.role'))
                            ->options(ProjectRole::class)
                            ->required(),
                    ])
                    ->fillForm(fn (User $record): array => ['role' => $record->getAttribute('pivot')?->role])
                    ->action(function (User $record, array $data): void {
                        $project = $this->getOwnerRecord();
                        assert($project instanceof Project);

                        $project->users()->updateExistingPivot($record->getKey(), ['role' => $data['role'] instanceof ProjectRole ? $data['role']->value : $data['role']]);

                        Notification::make()->success()->title(__('nightguardian.members.role_changed'))->send();
                    }),
                DetachAction::make()->label(__('nightguardian.members.remove'))->modalHeading(__('nightguardian.members.remove')),
            ]);
    }
}
