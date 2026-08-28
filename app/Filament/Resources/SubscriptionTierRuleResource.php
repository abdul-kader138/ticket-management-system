<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionTierRuleResource\Pages;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTierRule;
use Filament\Forms\Components\Grid;
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
 * Automatic, spend/tenure-based loyalty tiers — see docs/ROADMAP.md, Phase
 * 7 and SubscriptionService::matchedTierRule(). Independent of any
 * purchased plan; a user can hold both at once, with the higher of the two
 * winning per-benefit.
 */
class SubscriptionTierRuleResource extends Resource
{
    protected static ?string $model = SubscriptionTierRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'Billing';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Tier Rule')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->helperText('e.g. "Gold Tier" — shown to the customer on their account page.'),

                        Select::make('subscription_plan_id')
                            ->label('Grants the benefits of')
                            ->options(fn () => SubscriptionPlan::pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ]),

                    Grid::make(3)->schema([
                        TextInput::make('min_total_spend_cents')
                            ->label('Minimum lifetime spend (cents)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required()
                            ->helperText('50000 = $500.00'),

                        TextInput::make('min_account_age_days')
                            ->label('Minimum account age (days)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),

                        TextInput::make('priority')
                            ->numeric()
                            ->default(0)
                            ->required()
                            ->helperText('Higher wins when more than one rule qualifies.'),
                    ]),

                    Toggle::make('is_active')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('subscriptionPlan.name')->label('Grants'),
                TextColumn::make('min_total_spend_cents')
                    ->label('Min. spend')
                    ->formatStateUsing(fn (int $state) => '$'.number_format($state / 100, 2)),
                TextColumn::make('min_account_age_days')->label('Min. age (days)'),
                TextColumn::make('priority')->sortable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('priority', 'desc')
            ->actions([EditAction::make(), DeleteAction::make()])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionTierRules::route('/'),
            'create' => Pages\CreateSubscriptionTierRule::route('/create'),
            'edit' => Pages\EditSubscriptionTierRule::route('/{record}/edit'),
        ];
    }
}
