<?php

namespace App\Filament\Resources\Issues\RelationManagers;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Issues\IssueResource;
use App\Models\Event;
use App\Models\Issue;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('nightguardian.issues.events_tab');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return $ownerRecord instanceof Issue ? (string) $ownerRecord->events()->count() : null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('message')
            ->defaultSort('occurred_at', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('nightguardian.events.occurred_at'))
                    ->dateTime(timezone: IssueResource::timezone())
                    ->sortable(),
                TextColumn::make('reference')->label(__('nightguardian.events.reference'))->placeholder('—')->copyable()->fontFamily('mono'),
                TextColumn::make('environment')->label(__('nightguardian.issues.environment'))->badge()->color('gray')->placeholder('—'),
                TextColumn::make('release')->label(__('nightguardian.issues.release'))->placeholder('—'),
                TextColumn::make('request_route')->label(__('nightguardian.events.route'))->placeholder('—'),
                TextColumn::make('user_identifier')->label(__('nightguardian.events.user'))->placeholder('—'),
            ])
            ->recordUrl(fn (Event $record): string => EventResource::getUrl('view', ['record' => $record]));
    }
}
