<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Alerts\RuleMatcher;
use App\Models\AlertRule;
use App\Models\Project;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * "Always notify", "never notify" and "treat as critical" rules (docs/alerts.md).
 */
class AlertRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'alertRules';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('alerts.rules_tab');
    }

    public function form(Schema $schema): Schema
    {
        $fields = [];
        foreach ([...RuleMatcher::FIELDS, 'tag'] as $field) {
            $fields[$field] = __('alerts.fields.'.$field);
        }

        return $schema->components([
            TextInput::make('name')->label(__('alerts.name'))->required()->maxLength(255),
            Select::make('field')->label(__('alerts.field'))->options($fields)->required()->live()->dehydrated(),
            TextInput::make('tag_key')
                ->label(__('alerts.tag_key'))
                ->visible(fn ($get): bool => $get('field') === 'tag')
                ->required(fn ($get): bool => $get('field') === 'tag')
                ->maxLength(64),
            Select::make('operator')->label(__('alerts.operator'))->options([
                'equals' => __('alerts.operators.equals'),
                'contains' => __('alerts.operators.contains'),
                'starts_with' => __('alerts.operators.starts_with'),
                'regex' => __('alerts.operators.regex'),
            ])->required(),
            TextInput::make('value')->label(__('alerts.value'))->required()->maxLength(500),
            Select::make('action')->label(__('alerts.action'))->options([
                'always_notify' => __('alerts.actions.always_notify'),
                'never_notify' => __('alerts.actions.never_notify'),
                'set_level_critical' => __('alerts.actions.set_level_critical'),
            ])->required(),
            TextInput::make('position')->label(__('alerts.position'))->numeric()->default(0),
            Toggle::make('enabled')->label(__('alerts.enabled'))->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('position')
            ->emptyStateHeading(__('alerts.rules_empty'))
            ->emptyStateDescription(__('alerts.rules_empty_help'))
            ->columns([
                TextColumn::make('name')->label(__('alerts.name'))->weight('semibold'),
                TextColumn::make('condition')
                    ->label(__('alerts.condition'))
                    ->state(fn (AlertRule $record): string => str_starts_with($record->field, 'tag:')
                        ? __('alerts.fields.tag').' '.substr($record->field, 4)
                        : __('alerts.fields.'.$record->field))
                    ->description(fn (AlertRule $record): string => __('alerts.operators.'.$record->operator).' '.$record->value),
                TextColumn::make('action')->label(__('alerts.action'))->badge()->formatStateUsing(fn (string $state): string => __('alerts.actions.'.$state)),
                IconColumn::make('enabled')->label(__('alerts.enabled'))->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('alerts.add_rule'))
                    ->authorize(fn (): bool => $this->canManage())
                    ->mutateDataUsing(fn (array $data): array => $this->withTagField($data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (): bool => $this->canManage())
                    ->mutateRecordDataUsing(function (array $data): array {
                        if (is_string($data['field'] ?? null) && str_starts_with($data['field'], 'tag:')) {
                            $data['tag_key'] = substr($data['field'], 4);
                            $data['field'] = 'tag';
                        }

                        return $data;
                    })
                    ->mutateDataUsing(fn (array $data): array => $this->withTagField($data)),
                DeleteAction::make()->authorize(fn (): bool => $this->canManage()),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withTagField(array $data): array
    {
        if (($data['field'] ?? null) === 'tag') {
            $data['field'] = 'tag:'.trim((string) ($data['tag_key'] ?? ''));
        }

        unset($data['tag_key']);

        return $data;
    }

    private function canManage(): bool
    {
        $project = $this->getOwnerRecord();

        return $project instanceof Project && (Auth::user()?->can('update', $project) ?? false);
    }
}
