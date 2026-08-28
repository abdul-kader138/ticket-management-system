<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FlightProviderResource\Pages;
use App\Models\FlightProvider;
use App\Services\Flights\DuffelClient;
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
 * Replaces the old single-provider "Flight API" tab on System Settings —
 * see docs/ROADMAP.md, Phase 2. Each row is one flight search/booking API
 * account; App\Services\Flights\FlightProviderManager fans a search out to
 * every enabled one by priority.
 */
class FlightProviderResource extends Resource
{
    protected static ?string $model = FlightProvider::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Driver classes available to pick in the form. A second/third provider
     * (Amadeus, Sabre, …) becomes one more entry here plus its own class
     * implementing FlightProviderContract — nothing else in this resource
     * changes.
     *
     * @return array<string, string>
     */
    private static function drivers(): array
    {
        return [
            DuffelClient::class => 'Duffel',
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Administration';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Provider')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->label('Display Name')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('code')
                            ->label('Code')
                            ->required()
                            ->maxLength(50)
                            ->unique(FlightProvider::class, 'code', ignoreRecord: true)
                            ->helperText('A short unique slug (e.g. "duffel"). Used internally to tag search results.'),
                    ]),

                    Grid::make(2)->schema([
                        Select::make('driver_class')
                            ->label('Driver')
                            ->options(self::drivers())
                            ->required()
                            ->native(false),

                        Select::make('environment')
                            ->label('Environment')
                            ->options([
                                'sandbox' => 'Test (sandbox airlines)',
                                'live' => 'Live / Production',
                            ])
                            ->default('sandbox')
                            ->native(false)
                            ->required(),
                    ]),

                    TextInput::make('base_url')
                        ->label('API Base URL')
                        ->url()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    // Dot notation reads/writes the decrypted 'credentials'
                    // array (see FlightProvider's `encrypted:array` cast) —
                    // Filament resolves nested state on JSON-backed columns
                    // the same way it would a relationship path.
                    TextInput::make('credentials.token')
                        ->label('API Access Token')
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->helperText('Stored encrypted. Leave blank when editing to keep the current token.'),

                    Grid::make(2)->schema([
                        TextInput::make('timeout')
                            ->label('Request Timeout (seconds)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(120)
                            ->default(30),

                        TextInput::make('priority')
                            ->label('Search Priority')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('Lower runs first when more than one provider is enabled.'),
                    ]),

                    Toggle::make('is_enabled')
                        ->label('Enabled')
                        ->helperText('Only enabled, correctly-configured providers are used for search.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->badge()
                    ->sortable(),

                TextColumn::make('environment')
                    ->badge()
                    ->color(fn (string $state) => $state === 'live' ? 'success' : 'warning'),

                IconColumn::make('is_enabled')
                    ->label('Enabled')
                    ->boolean(),

                TextColumn::make('priority')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('priority')
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlightProviders::route('/'),
            'create' => Pages\CreateFlightProvider::route('/create'),
            'edit' => Pages\EditFlightProvider::route('/{record}/edit'),
        ];
    }
}
