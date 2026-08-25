<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PromotionResource\Pages;
use App\Models\Promotion;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * See docs/ROADMAP.md, Phase 8. percent/fixed codes are redeemed at
 * checkout (App\Services\Promotions\PromotionService::redeemForBooking());
 * free_search_bonus codes are redeemed standalone from the account area.
 * The referral program is a separate, code-free mechanism — see
 * App\Observers\BookingObserver.
 */
class PromotionResource extends Resource
{
    protected static ?string $model = Promotion::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?int $navigationSort = 87;

    public static function getNavigationGroup(): ?string
    {
        return 'Administration';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Promotion')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('code')
                            ->required()
                            ->maxLength(50)
                            ->unique(Promotion::class, 'code', ignoreRecord: true)
                            ->helperText('What the customer types in — case-sensitive as stored.'),
                    ]),

                    Grid::make(2)->schema([
                        Select::make('type')
                            ->options([
                                Promotion::TYPE_PERCENT => 'Percent off',
                                Promotion::TYPE_FIXED => 'Fixed amount off (cents)',
                                Promotion::TYPE_FREE_SEARCH_BONUS => 'Bonus searches (standalone)',
                            ])
                            ->native(false)
                            ->required()
                            ->live(),

                        TextInput::make('value')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText(fn (Get $get) => match ($get('type')) {
                                Promotion::TYPE_PERCENT => '1-100',
                                Promotion::TYPE_FIXED => 'Cents, e.g. 500 = $5.00 off',
                                Promotion::TYPE_FREE_SEARCH_BONUS => 'Extra searches granted for today',
                                default => null,
                            }),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('usage_limit')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Total redemptions across all customers. Leave blank for unlimited.'),

                        TextInput::make('per_user_limit')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ]),

                    Grid::make(2)->schema([
                        DateTimePicker::make('starts_at')->native(false),
                        DateTimePicker::make('ends_at')->native(false),
                    ]),

                    Toggle::make('is_active')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->badge()->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('value'),
                TextColumn::make('redemptions_count')->counts('redemptions')->label('Redeemed'),
                TextColumn::make('ends_at')->label('Ends')->dateTime('d M Y')->default('—'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options([
                    Promotion::TYPE_PERCENT => 'Percent off',
                    Promotion::TYPE_FIXED => 'Fixed off',
                    Promotion::TYPE_FREE_SEARCH_BONUS => 'Bonus searches',
                ]),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromotions::route('/'),
            'create' => Pages\CreatePromotion::route('/create'),
            'edit' => Pages\EditPromotion::route('/{record}/edit'),
        ];
    }
}
