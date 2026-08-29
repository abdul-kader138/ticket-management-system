<?php

namespace App\Filament\Resources;

use App\Filament\Pages\BookFlight;
use App\Filament\Pages\ChangeBooking;
use App\Filament\Resources\BookingResource\Pages;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Bookings\BookingException;
use App\Services\Bookings\CancellationService;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Ops visibility into customer bookings, plus the two manual overrides a
 * support agent actually needs — force-cancel and a manual refund — both
 * behind the same 'update_booking' Shield permission and both requiring a
 * reason, which lands in booking_events/the Refund row respectively as the
 * audit trail (see docs/ROADMAP.md, Phase 6).
 */
class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'Operations';
    }

    public static function table(Table $table): Table
    {
        return $table
            // Without this, the manualRefund action's visible() closure
            // below runs a fresh `payments()->where(...)->exists()` query
            // per row — a real N+1 confirmed via query-log on this exact
            // page (27 queries for 10 rows before this fix). Eager-loading
            // once here lets that closure check the already-loaded
            // collection in memory instead.
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'flightProvider', 'payments']))
            ->columns([
                TextColumn::make('id')
                    ->label('Booking #')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable(['first_name', 'last_name', 'email']),

                TextColumn::make('flightProvider.name')
                    ->label('Provider'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        Booking::STATUS_CONFIRMED => 'success',
                        Booking::STATUS_HELD, Booking::STATUS_PENDING_PAYMENT => 'warning',
                        Booking::STATUS_CANCELLED, Booking::STATUS_EXPIRED, Booking::STATUS_REFUNDED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('total_price_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (Booking $record) => "{$record->currency} ".number_format($record->total_price_cents / 100, 2)),

                TextColumn::make('created_at')
                    ->label('Booked')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        Booking::STATUS_HELD => 'Held',
                        Booking::STATUS_PENDING_PAYMENT => 'Pending Payment',
                        Booking::STATUS_CONFIRMED => 'Confirmed',
                        Booking::STATUS_CHANGED => 'Changed',
                        Booking::STATUS_CANCELLED => 'Cancelled',
                        Booking::STATUS_REFUNDED => 'Refunded',
                        Booking::STATUS_EXPIRED => 'Expired',
                    ]),
            ])
            ->actions([
                ViewAction::make(),

                Action::make('takePayment')
                    ->label('Take payment')
                    ->icon('heroicon-o-credit-card')
                    ->color('primary')
                    ->visible(fn (Booking $record) => $record->status === Booking::STATUS_HELD && ! $record->hasExpired())
                    ->url(fn (Booking $record) => BookFlight::getUrl(['booking' => $record->id])),

                // Recovery path for a booking stranded in 'pending_payment'
                // because its gateway webhook never arrived — polls the
                // gateway directly (same call as the nightly reconcile
                // sweep) instead of waiting for it.
                Action::make('checkPayment')
                    ->label('Check payment')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (Booking $record) => $record->status === Booking::STATUS_PENDING_PAYMENT)
                    ->action(function (Booking $record) {
                        $payment = $record->payments()
                            ->where('status', Payment::STATUS_PENDING)
                            ->latest()
                            ->first();

                        if (! $payment) {
                            Notification::make()->warning()->title('No pending payment to check.')->send();

                            return;
                        }

                        try {
                            app(PaymentService::class)->reconcile($payment);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not check payment')->body($e->getMessage())->send();

                            return;
                        }

                        $status = $record->fresh()->status;

                        Notification::make()
                            ->status($status === Booking::STATUS_CONFIRMED ? 'success' : 'info')
                            ->title('Booking is now: '.ucfirst(str_replace('_', ' ', $status)))
                            ->send();
                    }),

                Action::make('change')
                    ->label('Change')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (Booking $record) => auth()->user()->can('update_booking')
                        && in_array($record->status, [Booking::STATUS_CONFIRMED, Booking::STATUS_CHANGED], true))
                    ->url(fn (Booking $record) => ChangeBooking::getUrl(['booking' => $record->id])),

                Action::make('forceCancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Booking $record) => auth()->user()->can('update_booking') && ! in_array($record->status, [
                        Booking::STATUS_CANCELLED, Booking::STATUS_REFUNDED, Booking::STATUS_EXPIRED,
                    ], true))
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->helperText('Recorded on the booking\'s audit trail.'),
                    ])
                    ->action(function (Booking $record, array $data) {
                        try {
                            app(CancellationService::class)->cancel($record, 'admin', auth()->id(), $data['reason']);
                            Notification::make()->success()->title('Booking cancelled')->send();
                        } catch (BookingException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Action::make('manualRefund')
                    ->label('Refund')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Booking $record) => auth()->user()->can('update_booking')
                        && $record->payments->contains(fn (Payment $payment) => $payment->status === Payment::STATUS_SUCCEEDED))
                    ->form([
                        TextInput::make('amount')
                            ->label('Amount to refund')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->helperText('In the booking\'s currency, e.g. 49.99.'),
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required(),
                    ])
                    ->action(function (Booking $record, array $data) {
                        $payment = $record->payments
                            ->where('status', Payment::STATUS_SUCCEEDED)
                            ->sortByDesc('created_at')
                            ->first();

                        try {
                            app(PaymentService::class)->refund($payment, (int) round($data['amount'] * 100), $data['reason']);
                            Notification::make()->success()->title('Refund issued')->send();
                        } catch (PaymentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'view' => Pages\ViewBooking::route('/{record}'),
        ];
    }
}
