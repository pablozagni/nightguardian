<?php

namespace App\Filament\Resources\Issues\Pages;

use App\Enums\IssueStatus;
use App\Filament\Resources\Issues\IssueResource;
use App\Issues\IssueActions;
use App\Models\Issue;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * @property Issue $record
 */
class ViewIssue extends ViewRecord
{
    protected static string $resource = IssueResource::class;

    public function getTitle(): string|Htmlable
    {
        return new HtmlString(e($this->record->title));
    }

    public function getContentTabLabel(): ?string
    {
        return __('nightguardian.issues.overview');
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resolve')
                ->label(__('nightguardian.actions.resolve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->authorize('update')
                ->visible(fn (): bool => $this->isOpen())
                ->requiresConfirmation()
                ->action(function (): void {
                    app(IssueActions::class)->resolve($this->record, $this->user());
                    $this->done('resolved');
                }),
            Action::make('resolveInRelease')
                ->label(__('nightguardian.actions.resolve_in_release'))
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->authorize('update')
                ->visible(fn (): bool => $this->isOpen())
                ->modalDescription(__('nightguardian.actions.resolve_in_release_help'))
                ->schema([
                    TextInput::make('release')
                        ->label(__('nightguardian.actions.release'))
                        ->placeholder('1.4.3')
                        ->required()
                        ->maxLength(128),
                ])
                ->action(function (array $data): void {
                    app(IssueActions::class)->resolve($this->record, $this->user(), (string) $data['release']);
                    $this->done('resolved');
                }),
            ActionGroup::make([
                Action::make('ignore1h')
                    ->label(__('nightguardian.actions.ignore_1h'))
                    ->action(fn () => $this->ignore(now()->addHour())),
                Action::make('ignore24h')
                    ->label(__('nightguardian.actions.ignore_24h'))
                    ->action(fn () => $this->ignore(now()->addDay())),
                Action::make('ignoreForever')
                    ->label(__('nightguardian.actions.ignore_forever'))
                    ->action(fn () => $this->ignore(null)),
            ])
                ->label(__('nightguardian.actions.ignore'))
                ->icon(Heroicon::OutlinedEyeSlash)
                ->button()
                ->color('gray')
                ->visible(fn (): bool => $this->record->status !== IssueStatus::Ignored && (Auth::user()?->can('update', $this->record) ?? false)),
            Action::make('reopen')
                ->label(__('nightguardian.actions.reopen'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->authorize('update')
                ->visible(fn (): bool => ! $this->isOpen())
                ->action(function (): void {
                    app(IssueActions::class)->reopen($this->record, $this->user());
                    $this->done('reopened');
                }),
            Action::make('merge')
                ->label(__('nightguardian.actions.merge'))
                ->icon(Heroicon::OutlinedArrowsPointingIn)
                ->color('gray')
                ->authorize('update')
                ->modalDescription(__('nightguardian.actions.merge_help'))
                ->schema([
                    Select::make('target')
                        ->label(__('nightguardian.actions.merge_into'))
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->mergeCandidates($search))
                        ->options(fn (): array => $this->mergeCandidates('')),
                ])
                ->action(function (array $data): void {
                    $target = Issue::query()
                        ->where('project_id', $this->record->project_id)
                        ->whereNull('merged_into_id')
                        ->findOrFail($data['target']);

                    app(IssueActions::class)->merge($this->record, $target, $this->user());

                    Notification::make()->success()->title(__('nightguardian.actions.merged'))->send();
                    $this->redirect(IssueResource::getUrl('view', ['record' => $target]));
                }),
        ];
    }

    private function isOpen(): bool
    {
        return in_array($this->record->status, [IssueStatus::New, IssueStatus::Regressed], true);
    }

    private function user(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }

    private function ignore(?Carbon $until): void
    {
        app(IssueActions::class)->ignore($this->record, $this->user(), $until);
        $this->done('ignored');
    }

    private function done(string $key): void
    {
        $this->record->refresh();
        Notification::make()->success()->title(__('nightguardian.actions.'.$key))->send();
    }

    /**
     * @return array<string, string>
     */
    private function mergeCandidates(string $search): array
    {
        return Issue::query()
            ->where('project_id', $this->record->project_id)
            ->whereNull('merged_into_id')
            ->whereKeyNot($this->record->getKey())
            ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.$search.'%'))
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->pluck('title', 'id')
            ->all();
    }
}
