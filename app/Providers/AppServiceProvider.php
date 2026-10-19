<?php

namespace App\Providers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services (panel-wide Filament defaults, see the Filament standards).
     */
    public function boot(): void
    {
        Table::configureUsing(fn (Table $table) => $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false));

        Select::configureUsing(fn (Select $select) => $select
            ->native(false)->preload()->searchable());

        CreateAction::configureUsing(fn (CreateAction $action) => $action->disableCreateAnother());

        DeleteAction::configureUsing(fn (DeleteAction $action) => $action
            ->button()->color('danger')->size('xs'));

        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_START,
            fn (): string => '<b>'.e(config('app.name')).'</b>',
        );
    }
}
