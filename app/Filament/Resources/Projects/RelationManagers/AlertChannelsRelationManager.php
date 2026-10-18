<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Alerts\ChannelRegistry;
use App\Enums\Level;
use App\Filament\Resources\Issues\IssueResource;
use App\Models\AlertChannel;
use App\Models\AlertLog;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Where alerts of a project go (Telegram, e-mail, …). Secrets (the bot token) are stored encrypted, never shown
 * again after saving, and left untouched when the field is left blank while editing.
 */
class AlertChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'alertChannels';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('alerts.channels_tab');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('alerts.name'))->required()->maxLength(255),
            Select::make('type')
                ->label(__('alerts.type'))
                ->options(fn (): array => app(ChannelRegistry::class)->options())
                ->required()
                ->live()
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(),
            Group::make()
                ->statePath('config')
                ->schema(function (Get $get): array {
                    $type = $get('type');
                    $registry = app(ChannelRegistry::class);

                    return is_string($type) && $registry->has($type) ? $registry->class($type)::configForm() : [];
                }),
            Select::make('min_level')->label(__('alerts.min_level'))->helperText(__('alerts.min_level_help'))->options(Level::class)->default(Level::Error->value)->required(),
            Toggle::make('notify_new')->label(__('alerts.notify_new'))->default(true),
            Toggle::make('notify_regressed')->label(__('alerts.notify_regressed'))->default(true),
            Toggle::make('notify_threshold')->label(__('alerts.notify_threshold'))->helperText(__('alerts.notify_threshold_help')),
            Toggle::make('include_digest')->label(__('alerts.include_digest')),
            Toggle::make('enabled')->label(__('alerts.enabled'))->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->emptyStateHeading(__('alerts.channels_empty'))
            ->emptyStateDescription(__('alerts.channels_empty_help'))
            ->columns([
                TextColumn::make('name')->label(__('alerts.name'))->weight('semibold'),
                TextColumn::make('type')->label(__('alerts.type'))->badge()->formatStateUsing(fn (string $state): string => app(ChannelRegistry::class)->options()[$state] ?? $state),
                TextColumn::make('min_level')->label(__('alerts.min_level'))->badge(),
                IconColumn::make('enabled')->label(__('alerts.enabled'))->boolean(),
                TextColumn::make('last_sent_at')->label(__('alerts.last_sent'))->since()->placeholder('—')->dateTimeTooltip(timezone: IssueResource::timezone()),
                TextColumn::make('last_error')->label(__('alerts.last_error'))->color('danger')->limit(60)->tooltip(fn (AlertChannel $record): ?string => $record->last_error)->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('alerts.add_channel'))
                    ->authorize(fn (): bool => $this->canManage())
                    ->before(function (CreateAction $action, array $data): void {
                        $this->assertValid($data['type'] ?? null, (array) ($data['config'] ?? []), $action);
                    }),
            ])
            ->recordActions([
                Action::make('test')
                    ->label(__('alerts.send_test'))
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->authorize(fn (): bool => $this->canManage())
                    ->action(fn (AlertChannel $record) => $this->sendTest($record)),
                EditAction::make()
                    ->authorize(fn (): bool => $this->canManage())
                    // Secrets are never sent back to the browser.
                    ->mutateRecordDataUsing(function (array $data): array {
                        $data['config'] = $this->blankSecrets((array) ($data['config'] ?? []));

                        return $data;
                    })
                    ->mutateDataUsing(function (array $data, AlertChannel $record): array {
                        // A blank secret means "keep the current one".
                        $data['config'] = array_merge($record->config, array_filter((array) ($data['config'] ?? []), fn ($value) => $value !== null && $value !== '' && $value !== []));

                        return $data;
                    })
                    ->before(function (EditAction $action, array $data): void {
                        $this->assertValid($data['type'] ?? null, (array) ($data['config'] ?? []), $action);
                    }),
                DeleteAction::make()->authorize(fn (): bool => $this->canManage()),
            ]);
    }

    private function sendTest(AlertChannel $channel): void
    {
        abort_unless($this->canManage(), 403);

        try {
            app(ChannelRegistry::class)->make($channel->type)->test($channel->config);
        } catch (Throwable $e) {
            $channel->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            AlertLog::create(['alert_channel_id' => $channel->id, 'kind' => 'test', 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            Notification::make()->danger()->title(__('alerts.test_failed'))->body($e->getMessage())->persistent()->send();

            return;
        }

        $channel->forceFill(['last_error' => null, 'last_sent_at' => now()])->save();
        AlertLog::create(['alert_channel_id' => $channel->id, 'kind' => 'test', 'status' => 'sent']);

        Notification::make()->success()->title(__('alerts.test_sent'))->send();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertValid(mixed $type, array $config, Action $action): void
    {
        $registry = app(ChannelRegistry::class);

        if (! is_string($type) || ! $registry->has($type)) {
            $action->halt();

            return;
        }

        $problems = $registry->class($type)::validate($config);

        if ($problems !== []) {
            Notification::make()->danger()->title(__('alerts.invalid_config'))->body(implode("\n", $problems))->send();
            $action->halt();
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function blankSecrets(array $config): array
    {
        foreach ($config as $key => $value) {
            if (preg_match('/token|secret|password|key/i', (string) $key) === 1) {
                $config[$key] = '';
            }
        }

        return $config;
    }

    private function canManage(): bool
    {
        $project = $this->getOwnerRecord();

        return $project instanceof Project && (Auth::user()?->can('update', $project) ?? false);
    }
}
