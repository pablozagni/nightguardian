<?php

namespace App\Filament\Resources\Issues;

use App\Enums\EventType;
use App\Enums\IssueStatus;
use App\Enums\Level;
use App\Filament\Resources\Issues\Pages\ListIssues;
use App\Filament\Resources\Issues\Pages\ViewIssue;
use App\Filament\Resources\Issues\RelationManagers\ActivityRelationManager;
use App\Filament\Resources\Issues\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Issues\RelationManagers\FeedbackRelationManager;
use App\Filament\Support\ScopesToUserProjects;
use App\Issues\IssueActions;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\IssueHosts;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class IssueResource extends Resource
{
    protected static ?string $model = Issue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getModelLabel(): string
    {
        return __('nightguardian.issues.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('nightguardian.issues.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('nightguardian.issues.navigation');
    }

    public static function canCreate(): bool
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
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('merged_into_id')->with('project'))
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('level')->label(__('nightguardian.issues.level'))->badge(),
                TextColumn::make('title')
                    ->label(__('nightguardian.issues.title'))
                    ->description(fn (Issue $record): ?string => $record->culprit)
                    ->searchable(['title', 'culprit'])
                    ->limit(90)
                    ->wrap(),
                TextColumn::make('project.name')->label(__('nightguardian.projects.singular'))->badge()->color('gray'),
                TextColumn::make('status')->label(__('nightguardian.issues.status'))->badge(),
                TextColumn::make('events_count')->label(__('nightguardian.issues.events'))->numeric()->sortable(),
                TextColumn::make('users_count')->label(__('nightguardian.issues.users'))->numeric()->sortable(),
                TextColumn::make('last_seen_at')
                    ->label(__('nightguardian.issues.last_seen'))
                    ->since()
                    ->dateTimeTooltip(timezone: static::timezone())
                    ->sortable(),
                TextColumn::make('first_seen_at')
                    ->label(__('nightguardian.issues.first_seen'))
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('project_id')
                    ->label(__('nightguardian.projects.singular'))
                    ->options(fn (): array => static::visibleProjects()->pluck('name', 'id')->all()),
                SelectFilter::make('status')
                    ->label(__('nightguardian.issues.status'))
                    ->multiple()
                    ->options(IssueStatus::class)
                    ->default([IssueStatus::New->value, IssueStatus::Regressed->value]),
                SelectFilter::make('environment')
                    ->label(__('nightguardian.issues.environment'))
                    ->options(fn (): array => self::environments())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereJsonContains('environments', $data['value'])
                        : $query),
                SelectFilter::make('host')
                    ->label(__('nightguardian.issues.host'))
                    ->options(fn (): array => self::hosts())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('events', fn (Builder $events) => $events->where('host', $data['value']))
                        : $query),
                SelectFilter::make('type')->label(__('nightguardian.issues.type'))->options(EventType::class),
                SelectFilter::make('level')->label(__('nightguardian.issues.level'))->options(Level::class)->multiple(),
                Filter::make('release')
                    ->label(__('nightguardian.issues.release'))
                    ->schema([TextInput::make('release')->label(__('nightguardian.issues.release'))])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['release'] ?? null)
                        ? $query->where(fn (Builder $q) => $q->where('last_release', $data['release'])->orWhere('first_release', $data['release']))
                        : $query),
            ])
            ->recordUrl(fn (Issue $record): string => static::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('resolve')
                        ->label(__('nightguardian.actions.resolve'))
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->authorizeIndividualRecords('update')
                        ->action(function (Collection $records): void {
                            $user = Auth::user();
                            assert($user instanceof User);
                            $actions = app(IssueActions::class);
                            $records->each(fn (Issue $issue) => $actions->resolve($issue, $user));
                            Notification::make()->success()->title(__('nightguardian.actions.resolved_many', ['count' => $records->count()]))->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('ignore')
                        ->label(__('nightguardian.actions.ignore'))
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->requiresConfirmation()
                        ->authorizeIndividualRecords('update')
                        ->action(function (Collection $records): void {
                            $user = Auth::user();
                            assert($user instanceof User);
                            $actions = app(IssueActions::class);
                            $records->each(fn (Issue $issue) => $actions->ignore($issue, $user));
                            Notification::make()->success()->title(__('nightguardian.actions.ignored_many', ['count' => $records->count()]))->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Grid::make(['default' => 2, 'md' => 4])->schema([
                    TextEntry::make('status')->label(__('nightguardian.issues.status'))->badge(),
                    TextEntry::make('level')->label(__('nightguardian.issues.level'))->badge(),
                    TextEntry::make('events_count')->label(__('nightguardian.issues.events'))->numeric(),
                    TextEntry::make('users_count')->label(__('nightguardian.issues.users'))->numeric(),
                    TextEntry::make('first_seen_at')->label(__('nightguardian.issues.first_seen'))->dateTime(timezone: static::timezone()),
                    TextEntry::make('last_seen_at')->label(__('nightguardian.issues.last_seen'))->dateTime(timezone: static::timezone()),
                    TextEntry::make('first_release')->label(__('nightguardian.issues.first_release'))->placeholder('—'),
                    TextEntry::make('last_release')->label(__('nightguardian.issues.last_release'))->placeholder('—'),
                    TextEntry::make('environments')->label(__('nightguardian.issues.environment'))->badge()->placeholder('—'),
                    TextEntry::make('project.name')->label(__('nightguardian.projects.singular')),
                    TextEntry::make('resolved_in_release')
                        ->label(__('nightguardian.issues.resolved_in_release'))
                        ->visible(fn (Issue $record): bool => $record->resolved_in_release !== null),
                    TextEntry::make('ignored_until')
                        ->label(__('nightguardian.issues.ignored_until'))
                        ->dateTime(timezone: static::timezone())
                        ->visible(fn (Issue $record): bool => $record->ignored_until !== null),
                ]),
            ]),
            Section::make(__('nightguardian.issues.hosts_title'))
                ->description(__('nightguardian.issues.hosts_help'))
                ->visible(fn (Issue $record): bool => IssueHosts::count($record) > 0)
                ->schema([
                    View::make('filament.issue-hosts')->viewData(fn (Issue $record): array => [
                        'hosts' => IssueHosts::breakdown($record),
                        'timezone' => static::timezone(),
                    ]),
                ]),
            Section::make(__('nightguardian.issues.latest_event'))
                ->schema([
                    View::make('filament.latest-event')->viewData(fn (Issue $record): array => [
                        'issue' => $record,
                        'event' => $record->events()->latest('occurred_at')->first(),
                    ]),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            FeedbackRelationManager::class,
            ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIssues::route('/'),
            'view' => ViewIssue::route('/{record}'),
        ];
    }

    /**
     * Global search over issue titles (event reference codes are searched by EventResource).
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'culprit'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        assert($record instanceof Issue);

        return new HtmlString(e($record->title));
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        assert($record instanceof Issue);

        return [
            __('nightguardian.projects.singular') => (string) $record->project?->name,
            __('nightguardian.issues.status') => $record->status->getLabel(),
        ];
    }

    public static function timezone(): string
    {
        $user = Auth::user();

        return $user instanceof User && $user->timezone !== '' ? $user->timezone : (string) config('app.timezone');
    }

    /**
     * @return Collection<int, Project>
     */
    public static function visibleProjects(): Collection
    {
        $user = Auth::user();
        $ids = $user instanceof User ? $user->visibleProjectIds() : [];

        return Project::query()->when($ids !== null, fn ($query) => $query->whereIn('id', $ids ?? []))->orderBy('name')->get();
    }

    /**
     * @return array<string, string>
     */
    private static function hosts(): array
    {
        return Event::query()
            ->whereIn('project_id', static::visibleProjects()->pluck('id'))
            ->whereNotNull('host')
            ->distinct()
            ->orderBy('host')
            ->limit(500)
            ->pluck('host', 'host')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function environments(): array
    {
        $names = static::getEloquentQuery()->pluck('environments')->flatten()->filter()->unique()->sort()->values();

        return $names->mapWithKeys(fn ($name) => [(string) $name => (string) $name])->all();
    }
}
