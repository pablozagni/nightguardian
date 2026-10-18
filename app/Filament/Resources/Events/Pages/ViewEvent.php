<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Issues\IssueResource;
use App\Models\Event;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @property Event $record
 */
class ViewEvent extends ViewRecord
{
    protected static string $resource = EventResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->reference ?? __('nightguardian.events.singular');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label(__('nightguardian.events.open_issue'))
                ->icon(Heroicon::OutlinedBugAnt)
                ->url(fn (): ?string => $this->record->issue_id === null ? null : IssueResource::getUrl('view', ['record' => $this->record->issue_id]))
                ->visible(fn (): bool => $this->record->issue_id !== null),
        ];
    }
}
