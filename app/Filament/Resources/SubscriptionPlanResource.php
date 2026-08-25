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

    protected static ?int $navigationSort = 85;

    public static function getNavigationGroup(): ?string
    {
        return 'Administration';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Plan')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('code')
                            ->required()
                            ->maxLength(50)
                            ->unique(SubscriptionPlan::class, 'code', ignoreRecord: true)
                            ->helperText('A short unique slug (e.g. "plus-monthly").'),
                    ]),

                    Grid::make(3)->schema([
                        TextInput::make('price_cents')
                            ->label('Price (cents)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->helperText('999 = $9.99'),

                        TextInput::make('currency')
                            ->default('USD')
                            ->maxLength(3)
                            ->required(),

                        Select::make('billing_interval')
                            ->options(['month' => 'Monthly', 'year' => 'Yearly'])
                            ->default('month')
                            ->native(false)
                            ->required(),
                    ]),

                    Toggle::make('is_active')->default(true),
                ]),

            Section::make('Search Quota Overrides')
                ->description('Leave blank to fall back to the account-wide default (System Settings → Search Quotas). -1 means unlimited.')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('daily_search_limit')
                            ->numeric()
                            ->helperText('-1 = unlimited'),

                        TextInput::make('monthly_search_limit')
                            ->numeric()
                            ->helperText('-1 = unlimited'),
                    ]),
                ]),

            Section::make('Benefits')
                ->description('Flat boolean perks, e.g. fee_free_changes = true. Read by SubscriptionService::hasBenefit().')
                ->schema([
                    KeyValue::make('benefits')
                        ->keyLabel('Benefit')
                        ->valueLabel('Enabled (true/false)')
                        ->default([]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge(),
                TextColumn::make('price_cents')
                    ->label('Price')
                    ->formatStateUsing(fn (SubscriptionPlan $record) => "{$record->currency} ".number_format($record->price_cents / 100, 2).' / '.$record->billing_interval),
                TextColumn::make('daily_search_limit')->label('Daily limit')->formatStateUsing(fn (?int $state) => $state === null ? '—' : ($state === -1 ? 'Unlimited' : $state)),
                TextColumn::make('monthly_search_limit')->label('Monthly limit')->formatStateUsing(fn (?int $state) => $state === null ? '—' : ($state === -1 ? 'Unlimited' : $state)),
                IconColumn::make('is_active')->boolean(),
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
