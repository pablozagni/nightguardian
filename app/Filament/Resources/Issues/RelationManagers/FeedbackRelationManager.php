<?php

namespace App\Filament\Resources\Issues\RelationManagers;

use App\Filament\Resources\Issues\IssueResource;
use App\Models\Issue;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What users wrote on the friendly error page, linked to the issue of the event they were looking at.
 */
class FeedbackRelationManager extends RelationManager
{
    protected static string $relationship = 'feedback';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('nightguardian.issues.feedback_tab');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord instanceof Issue ? $ownerRecord->feedback()->count() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('comment')
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('nightguardian.feedback.empty'))
            ->emptyStateDescription(__('nightguardian.feedback.empty_help'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('nightguardian.feedback.received'))
                    ->dateTime(timezone: IssueResource::timezone())
                    ->sortable(),
                TextColumn::make('comment')->label(__('nightguardian.feedback.comment'))->wrap()->grow(),
                TextColumn::make('reference')->label(__('nightguardian.events.reference'))->placeholder('—')->copyable()->fontFamily('mono'),
                TextColumn::make('email')->label(__('nightguardian.feedback.email'))->placeholder('—'),
            ]);
    }
}
