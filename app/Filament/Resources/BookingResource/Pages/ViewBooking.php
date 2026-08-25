<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Booking')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')->badge(),
                    TextEntry::make('user.name')->label('Customer'),
                    TextEntry::make('flightProvider.name')->label('Provider'),
                    TextEntry::make('pnr')->label('PNR')->default('—'),
                    TextEntry::make('total_price_cents')
                        ->label('Total')
                        ->formatStateUsing(fn (Booking $record) => "{$record->currency} ".number_format($record->total_price_cents / 100, 2)),
                    TextEntry::make('expires_at')->label('Hold expires')->dateTime('d M Y H:i')->default('—'),
                ]),

            Section::make('Segments')
                ->schema([
                    RepeatableEntry::make('segments')
                        ->label('')
                        ->schema([
                            TextEntry::make('carrier_name')->label('Carrier'),
                            TextEntry::make('flight_number')->label('Flight'),
                            TextEntry::make('origin')->label('From'),
                            TextEntry::make('destination')->label('To'),
                            TextEntry::make('departs_at')->label('Departs')->dateTime('d M Y H:i'),
                        ])
                        ->columns(5),
                ]),

            Section::make('Passengers')
                ->schema([
                    RepeatableEntry::make('passengers')
                        ->label('')
                        ->schema([
                            TextEntry::make('type')->badge(),
                            TextEntry::make('first_name'),
                            TextEntry::make('last_name'),
                            TextEntry::make('ticket_number')->default('—'),
                        ])
                        ->columns(4),
                ]),
        ]);
    }
}
