<?php

namespace App\Filament\Resources\Issues\RelationManagers;

use App\Filament\Resources\Issues\IssueResource;
use App\Models\IssueActivity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ActivityRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('nightguardian.issues.activity_tab');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('action')
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25])
            ->emptyStateHeading(__('nightguardian.activity.empty'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('nightguardian.activity.when'))
                    ->dateTime(timezone: IssueResource::timezone())
                    ->sortable(),
                TextColumn::make('user.name')->label(__('nightguardian.activity.who'))->placeholder(__('nightguardian.activity.system')),
                TextColumn::make('action')
                    ->label(__('nightguardian.activity.what'))
                    ->state(fn (IssueActivity $record): string => __('nightguardian.activity.'.$record->action, [
                        'release' => (string) ($record->meta['release'] ?? ''),
                        'title' => (string) ($record->meta['title'] ?? ''),
                        'until' => isset($record->meta['until']) ? Carbon::parse($record->meta['until'])->timezone(IssueResource::timezone())->isoFormat('lll') : __('nightguardian.activity.forever'),
                    ]))
                    ->wrap(),
            ]);
    }
}
