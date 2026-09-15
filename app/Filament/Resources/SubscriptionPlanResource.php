<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Models\SubscriptionPlan;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * See docs/ROADMAP.md, Phase 7. A plan is a bundle of search-quota
 * overrides plus flat boolean perks — subscription_tier_rules grant these
 * same bundles for free once a user qualifies by spend/tenure, rather than
 * duplicating limit/benefit fields of their own.
 */
class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    public static function getNavigationLabel(): string
    {
        return __('Subscription Plans');
    }

    public static function getModelLabel(): string
    {
        return __('Subscription Plan');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Subscription Plans');
    }

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Billing');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('Plan'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(100),

                        TextInput::make('code')
                            ->label(__('Code'))
                            ->required()
                            ->maxLength(50)
                            ->unique(SubscriptionPlan::class, 'code', ignoreRecord: true)
                            ->helperText(__('A short unique slug (e.g. "plus-monthly").')),
                    ]),

                    Grid::make(3)->schema([
                        TextInput::make('price_cents')
                            ->label(__('Price (cents)'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->helperText(__('999 = $9.99')),

                        TextInput::make('currency')
                            ->label(__('Currency'))
                            ->default('USD')
                            ->maxLength(3)
                            ->required(),

                        Select::make('billing_interval')
                            ->label(__('Billing interval'))
                            ->options(['month' => __('Monthly'), 'year' => __('Yearly')])
                            ->default('month')
                            ->native(false)
                            ->required(),
                    ]),

                    Toggle::make('is_active')->label(__('Is active'))->default(true),
                ]),

            Section::make(__('Search Quota Overrides'))
                ->description(__('Leave blank to fall back to the account-wide default (System Settings → Search Quotas). -1 means unlimited.'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('daily_search_limit')
                            ->label(__('Daily search limit'))
                            ->numeric()
                            ->helperText(__('-1 = unlimited')),

                        TextInput::make('monthly_search_limit')
                            ->label(__('Monthly search limit'))
                            ->numeric()
                            ->helperText(__('-1 = unlimited')),
                    ]),
                ]),

            Section::make(__('Benefits'))
                ->description(__('Flat boolean perks, e.g. fee_free_changes = true. Read by SubscriptionService::hasBenefit().'))
                ->schema([
                    KeyValue::make('benefits')
                        ->keyLabel(__('Benefit'))
                        ->valueLabel(__('Enabled (true/false)'))
                        ->default([]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('code')->label(__('Code'))->badge(),
                TextColumn::make('price_cents')
                    ->label(__('Price'))
                    ->formatStateUsing(fn (SubscriptionPlan $record) => "{$record->currency} ".number_format($record->price_cents / 100, 2).' / '.$record->billing_interval),
                TextColumn::make('daily_search_limit')->label(__('Daily limit'))->formatStateUsing(fn (?int $state) => $state === null ? '—' : ($state === -1 ? __('Unlimited') : $state)),
                TextColumn::make('monthly_search_limit')->label(__('Monthly limit'))->formatStateUsing(fn (?int $state) => $state === null ? '—' : ($state === -1 ? __('Unlimited') : $state)),
                IconColumn::make('is_active')->label(__('Is active'))->boolean(),
            ])
            ->defaultSort('price_cents')
            ->actions([EditAction::make(), DeleteAction::make()])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}
