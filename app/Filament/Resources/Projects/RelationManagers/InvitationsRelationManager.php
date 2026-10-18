<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Access\InvitationService;
use App\Enums\ProjectRole;
use App\Filament\Resources\Issues\IssueResource;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Pending invitation links of a project. The link is shown once, when created.
 */
class InvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('nightguardian.invitations.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('accepted_at')->where('expires_at', '>', now()))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('nightguardian.invitations.empty'))
            ->columns([
                TextColumn::make('email')->label(__('nightguardian.members.email'))->placeholder(__('nightguardian.invitations.any_email')),
                TextColumn::make('role')->label(__('nightguardian.members.role'))->badge(),
                TextColumn::make('expires_at')->label(__('nightguardian.invitations.expires'))->dateTime(timezone: IssueResource::timezone()),
                TextColumn::make('creator.name')->label(__('nightguardian.invitations.created_by')),
            ])
            ->headerActions([
                Action::make('invite')
                    ->label(__('nightguardian.invitations.create'))
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->visible(fn (): bool => $this->canInvite())
                    ->modalHeading(__('nightguardian.invitations.create'))
                    ->schema([
                        TextInput::make('email')->label(__('nightguardian.members.email'))->email()->helperText(__('nightguardian.invitations.email_help')),
                        Select::make('role')->label(__('nightguardian.members.role'))->options(ProjectRole::class)->default(ProjectRole::Member->value)->required(),
                    ])
                    ->action(function (array $data): void {
                        abort_unless($this->canInvite(), 403);

                        $user = Auth::user();
                        assert($user instanceof User);

                        $created = app(InvitationService::class)->invite(
                            $user,
                            $this->project(),
                            $data['role'] instanceof ProjectRole ? $data['role'] : ProjectRole::from((string) $data['role']),
                            $data['email'] ?? null,
                        );

                        $this->replaceMountedAction('revealInvitation', ['url' => $created['url']]);
                    }),
            ])
            ->recordActions([
                DeleteAction::make()->label(__('nightguardian.invitations.revoke'))->modalHeading(__('nightguardian.invitations.revoke')),
            ]);
    }

    public function revealInvitationAction(): Action
    {
        return Action::make('revealInvitation')
            ->modalHeading(__('nightguardian.invitations.reveal_title'))
            ->modalDescription(__('nightguardian.invitations.reveal_help', ['days' => InvitationService::VALID_DAYS]))
            ->modalContent(fn (array $arguments) => view('filament.reveal-blocks', [
                'blocks' => [['key' => 'invitation', 'label' => __('nightguardian.invitations.link'), 'value' => (string) ($arguments['url'] ?? '')]],
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('nightguardian.tokens.done'))
            ->closeModalByClickingAway(false);
    }

    private function project(): Project
    {
        $project = $this->getOwnerRecord();
        assert($project instanceof Project);

        return $project;
    }

    private function canInvite(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can('update', $this->project());
    }
}
