<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Pages\BookFlight;
use App\Filament\Pages\ChangeBooking;
use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Bookings\BookingException;
use App\Services\Bookings\CancellationService;
use App\Services\Payments\PaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    // Eager-load everything the infolist renders — otherwise each
    // RepeatableEntry / nested TextEntry lazy-loads its relation on access.
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['user', 'flightProvider', 'segments', 'passengers', 'payments']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('takePayment')
                ->label('Take payment')
                ->icon('heroicon-o-credit-card')
                ->visible(fn (Booking $record) => $record->status === Booking::STATUS_HELD && ! $record->hasExpired())
                ->url(fn (Booking $record) => BookFlight::getUrl(['booking' => $record->id])),

            Action::make('checkPayment')
                ->label('Check payment')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (Booking $record) => $record->status === Booking::STATUS_PENDING_PAYMENT)
                ->action(function (Booking $record) {
                    $payment = $record->payments()->where('status', Payment::STATUS_PENDING)->latest()->first();

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

            Action::make('cancel')
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
        ];
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

            Section::make('Payments')
                ->schema([
                    RepeatableEntry::make('payments')
                        ->label('')
                        ->schema([
                            TextEntry::make('gateway')->badge(),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('amount_cents')
                                ->label('Amount')
                                ->formatStateUsing(fn ($state, $record) => "{$record->currency} ".number_format($state / 100, 2)),
                            TextEntry::make('created_at')->label('When')->dateTime('d M Y H:i'),
                        ])
                        ->columns(4),
                ])
                ->visible(fn (Booking $record) => $record->payments()->exists()),
        ]);
    }
}
