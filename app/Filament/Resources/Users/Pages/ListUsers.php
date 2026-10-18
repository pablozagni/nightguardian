<?php

namespace App\Filament\Resources\Users\Pages;

use App\Access\InvitationService;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('nightguardian.users.invite'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->visible(fn (): bool => (bool) Auth::user()?->is_super_admin)
                ->modalHeading(__('nightguardian.users.invite'))
                ->modalDescription(__('nightguardian.users.invite_help'))
                ->schema([
                    TextInput::make('email')->label(__('nightguardian.members.email'))->email()->helperText(__('nightguardian.invitations.email_help')),
                    Toggle::make('super_admin')->label(__('nightguardian.users.super_admin'))->default(false),
                ])
                ->action(function (array $data): void {
                    $user = Auth::user();
                    abort_unless($user instanceof User && $user->is_super_admin, 403);

                    $created = app(InvitationService::class)->invite($user, null, null, $data['email'] ?? null, (bool) ($data['super_admin'] ?? false));

                    $this->replaceMountedAction('revealInvitation', ['url' => $created['url']]);
                }),
        ];
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
}
