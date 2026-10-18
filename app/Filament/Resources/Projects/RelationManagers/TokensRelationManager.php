<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Access\TokenIssuer;
use App\Filament\Resources\Issues\IssueResource;
use App\Models\Project;
use App\Models\ProjectToken;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Ingest tokens of a project. A new token is shown once, together with the lines to paste in the monitored
 * app (.env + composer require); afterwards only its hash exists.
 */
class TokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('nightguardian.tokens.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('nightguardian.tokens.empty'))
            ->emptyStateDescription(__('nightguardian.tokens.empty_help'))
            ->columns([
                TextColumn::make('name')->label(__('nightguardian.tokens.name'))->weight('semibold'),
                TextColumn::make('status')
                    ->label(__('nightguardian.tokens.status'))
                    ->state(fn (ProjectToken $record): string => $record->isRevoked() ? 'revoked' : 'active')
                    ->formatStateUsing(fn (string $state): string => __('nightguardian.tokens.'.$state))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('last_used_at')
                    ->label(__('nightguardian.tokens.last_used'))
                    ->since()
                    ->placeholder(__('nightguardian.tokens.never_used'))
                    ->dateTimeTooltip(timezone: IssueResource::timezone()),
                TextColumn::make('created_at')
                    ->label(__('nightguardian.tokens.created'))
                    ->dateTime(timezone: IssueResource::timezone()),
            ])
            ->headerActions([
                Action::make('createToken')
                    ->label(__('nightguardian.tokens.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => $this->canManage())
                    ->modalHeading(__('nightguardian.tokens.create'))
                    ->modalSubmitActionLabel(__('nightguardian.tokens.create_submit'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('nightguardian.tokens.name'))
                            ->placeholder(__('nightguardian.tokens.name_placeholder'))
                            ->required()
                            ->maxLength(120),
                    ])
                    ->action(function (array $data): void {
                        abort_unless($this->canManage(), 403);

                        $issued = app(TokenIssuer::class)->issue($this->project(), (string) $data['name']);

                        $this->replaceMountedAction('revealToken', ['plain' => $issued['plain']]);
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('nightguardian.tokens.revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('nightguardian.tokens.revoke_help'))
                    ->visible(fn (ProjectToken $record): bool => ! $record->isRevoked() && $this->canManage())
                    ->action(function (ProjectToken $record): void {
                        abort_unless($this->canManage(), 403);

                        app(TokenIssuer::class)->revoke($record);

                        Notification::make()->success()->title(__('nightguardian.tokens.revoked_done'))->send();
                    }),
            ]);
    }

    /**
     * The modal that shows the new token. The plain value only lives in this modal's arguments.
     */
    public function revealTokenAction(): Action
    {
        return Action::make('revealToken')
            ->modalHeading(__('nightguardian.tokens.reveal_title'))
            ->modalDescription(__('nightguardian.tokens.reveal_help'))
            ->modalContent(function (array $arguments) {
                $issuer = app(TokenIssuer::class);
                $plain = (string) ($arguments['plain'] ?? '');

                return view('filament.reveal-blocks', [
                    'warning' => __('nightguardian.tokens.reveal_warning'),
                    'blocks' => [
                        ['key' => 'token', 'label' => __('nightguardian.tokens.token'), 'value' => $plain],
                        ['key' => 'env', 'label' => __('nightguardian.tokens.env_block'), 'value' => $issuer->installSnippet($plain)],
                        ['key' => 'composer', 'label' => __('nightguardian.tokens.composer'), 'value' => $issuer->composerCommand()],
                    ],
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('nightguardian.tokens.done'))
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false);
    }

    private function project(): Project
    {
        $project = $this->getOwnerRecord();
        assert($project instanceof Project);

        return $project;
    }

    private function canManage(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can('create', [ProjectToken::class, $this->project()]);
    }
}
