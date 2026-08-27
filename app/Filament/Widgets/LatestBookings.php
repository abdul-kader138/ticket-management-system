<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BookingResource;
use App\Filament\Widgets\Concerns\AuthorizesWithShield;
use App\Models\Booking;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * The ten most recent bookings, newest first — a live feed of what's
 * coming through checkout, with a jump straight to the full record in
 * BookingResource.
 */
class LatestBookings extends BaseWidget
{
    use AuthorizesWithShield;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Latest bookings';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Booking::query()
                    ->with(['user:id,first_name,last_name', 'segments:id,booking_id,sequence,origin,destination'])
                    ->latest()
                    ->limit(10)
            )
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('id')
                    ->label('Ref')
                    ->prefix('#'),

                TextColumn::make('user.name')
                    ->label('Customer')
                    ->default('—'),

                TextColumn::make('route')
                    ->label('Route')
                    ->getStateUsing(function (Booking $record): string {
                        $segments = $record->segments;

                        if ($segments->isEmpty()) {
                            return '—';
                        }

                        return $segments->first()->origin.' → '.$segments->last()->destination;
                    }),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Booking::STATUS_CONFIRMED, Booking::STATUS_CHANGED => 'success',
                        Booking::STATUS_PENDING_PAYMENT => 'warning',
                        Booking::STATUS_HELD => 'gray',
                        Booking::STATUS_CANCELLED, Booking::STATUS_REFUNDED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => ucwords(str_replace('_', ' ', $state))),

                TextColumn::make('total_price_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (Booking $record) => $record->currency.' '.number_format($record->total_price_cents / 100, 2))
                    ->alignEnd(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->tooltip(fn (Booking $record) => $record->created_at?->toDayDateTimeString()),
            ])
            ->actions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record]))
                    ->visible(fn () => BookingResource::canViewAny()),
            ]);
    }
}
