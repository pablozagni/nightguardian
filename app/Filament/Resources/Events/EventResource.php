<?php

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Issues\IssueResource;
use App\Filament\Support\ScopesToUserProjects;
use App\Models\Event;
use App\Models\User;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Read-only detail page of one event. It has no menu entry: it is reached from an issue, or from the global
 * search by reference code (`NG-XXXX-XXXX`, the code shown to users on the friendly error page).
 */
class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('nightguardian.events.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('nightguardian.events.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return Auth::check();
    }

    public static function canView(Model $record): bool
    {
        $user = Auth::user();

        return $user instanceof User && $record instanceof Event
            && ($user->is_super_admin || in_array($record->project_id, $user->visibleProjectIds() ?? [], true));
    }

    public static function getEloquentQuery(): Builder
    {
        return ScopesToUserProjects::restrict(parent::getEloquentQuery(), Auth::user());
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Grid::make(['default' => 2, 'md' => 4])->schema([
                    TextEntry::make('type')->label(__('nightguardian.issues.type'))->badge(),
                    TextEntry::make('level')->label(__('nightguardian.issues.level'))->badge()->placeholder('—'),
                    TextEntry::make('reference')->label(__('nightguardian.events.reference'))->placeholder('—')->copyable()->fontFamily('mono'),
                    TextEntry::make('occurred_at')->label(__('nightguardian.events.occurred_at'))->dateTime(timezone: IssueResource::timezone()),
                    TextEntry::make('project.name')->label(__('nightguardian.projects.singular')),
                    TextEntry::make('environment')->label(__('nightguardian.issues.environment'))->badge()->color('gray')->placeholder('—'),
                    TextEntry::make('release')->label(__('nightguardian.issues.release'))->placeholder('—'),
                    TextEntry::make('server')->label(__('nightguardian.events.server'))->placeholder('—'),
                    TextEntry::make('issue.title')
                        ->label(__('nightguardian.issues.singular'))
                        ->url(fn (Event $record): ?string => $record->issue_id === null ? null : IssueResource::getUrl('view', ['record' => $record->issue_id]))
                        ->columnSpan(['default' => 2, 'md' => 3])
                        ->placeholder('—'),
                    TextEntry::make('sdk_name')
                        ->label(__('nightguardian.events.sdk'))
                        ->formatStateUsing(fn (Event $record): string => trim($record->sdk_name.' '.$record->sdk_version))
                        ->placeholder('—'),
                ]),
            ]),
            Section::make(__('nightguardian.events.detail'))->schema([
                View::make('filament.event-detail')->viewData(fn (Event $record): array => ['event' => $record]),
            ]),
        ]);
    }

    /**
     * There is no events list: breadcrumbs lead to the triage list.
     *
     * @param  array<mixed>  $parameters
     */
    public static function getIndexUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false): string
    {
        return IssueResource::getUrl('index', $parameters, $isAbsolute, $panel, $tenant);
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewEvent::route('/{record}'),
        ];
    }

    // --- global search: by reference code ---------------------------------------------------------------

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['reference'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        assert($record instanceof Event);

        return new HtmlString(e((string) $record->reference));
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        assert($record instanceof Event);

        return array_filter([
            __('nightguardian.projects.singular') => (string) $record->project?->name,
            __('nightguardian.issues.singular') => (string) $record->message,
        ]);
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return static::getUrl('view', ['record' => $record]);
    }

    /**
     * Lets people paste the code the way they typed it: lower case, without dashes, or with O/I/L for 0/1.
     *
     * @return Collection<int, GlobalSearchResult>
     */
    public static function getGlobalSearchResults(string $search): Collection
    {
        return parent::getGlobalSearchResults(static::normalizeReference($search));
    }

    public static function normalizeReference(string $search): string
    {
        $candidate = strtoupper(trim($search));

        if (preg_match('/^NG[- ]?([0-9A-Z]{4})[- ]?([0-9A-Z]{4})$/', $candidate, $m) !== 1) {
            return $search;
        }

        $fix = fn (string $part): string => strtr($part, ['O' => '0', 'I' => '1', 'L' => '1']);

        return 'NG-'.$fix($m[1]).'-'.$fix($m[2]);
    }
}
