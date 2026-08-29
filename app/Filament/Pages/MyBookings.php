<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\Bookings\BookingException;
use App\Services\Bookings\CancellationService;
use App\Services\Payments\PaymentService;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customer-facing counterpart to the staff BookingResource: a
 * self-registered account (role `panel_user`, no permissions) can't see
 * the Operations resources, so this page is where they check their own
 * trips and what they've paid — scoped hard to auth()->id(), never the
 * `view_any_booking` permission.
 */
class MyBookings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'My Bookings';

    protected static ?string $title = 'My Bookings';

    protected static ?string $slug = 'my-bookings';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.my-bookings';

    /**
     * Staff have the full BookingResource under Operations — this
     * self-scoped view would only be noise for them.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->check() && ! auth()->user()->can('view_any_booking');
    }

    public function table(Table $table): Table
    {
        return $table
            // `segments` drives the list columns; `passengers`/`payments`
            // are read by the View modal's infolist. All three are batch
            // (WHERE booking_id IN …) loads, not per-row — and a customer
            // has few bookings — so eager-load them up front (lazy loading
            // is blocked outside production, see AppServiceProvider).
            ->query(fn (): Builder => Booking::query()
                ->where('user_id', auth()->id())
                ->with(['segments', 'passengers', 'payments']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('Ref')->prefix('#')->sortable(),

                TextColumn::make('route')
                    ->label('Trip')
                    ->getStateUsing(function (Booking $record): string {
                        $from = $record->segments->first()?->origin;
                        $to = $record->segments->last()?->destination;

                        return $from && $to ? "{$from} → {$to}" : '—';
                    }),

                TextColumn::make('departs')
                    ->label('Departs')
                    ->getStateUsing(fn (Booking $record) => $record->segments->first()?->departs_at?->format('d/m/Y H:i') ?? '—'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state) => match ($state) {
                        Booking::STATUS_CONFIRMED => 'success',
                        Booking::STATUS_HELD, Booking::STATUS_PENDING_PAYMENT, Booking::STATUS_CHANGED => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('pnr')->label('PNR')->placeholder('—'),

                TextColumn::make('total_price_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (Booking $record) => "{$record->currency} ".number_format($record->total_price_cents / 100, 2)),

                TextColumn::make('created_at')->label('Booked')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Booking::STATUS_HELD => 'Held',
                    Booking::STATUS_PENDING_PAYMENT => 'Pending payment',
                    Booking::STATUS_CONFIRMED => 'Confirmed',
                    Booking::STATUS_CHANGED => 'Changed',
                    Booking::STATUS_CANCELLED => 'Cancelled',
                    Booking::STATUS_REFUNDED => 'Refunded',
                    Booking::STATUS_EXPIRED => 'Expired',
                ]),
            ])
            ->actions([
                ViewAction::make()
                    ->modalHeading(fn (Booking $record) => "Booking #{$record->id}")
                    ->infolist([
                        Section::make('Itinerary')
                            ->schema([
                                RepeatableEntry::make('segments')
                                    ->label('')
                                    ->schema([
                                        TextEntry::make('carrier_name')->label('Airline'),
                                        TextEntry::make('flight_number')->label('Flight'),
                                        TextEntry::make('origin')->label('From'),
                                        TextEntry::make('destination')->label('To'),
                                        TextEntry::make('departs_at')->label('Departs')->dateTime('d/m/Y H:i'),
                                        TextEntry::make('arrives_at')->label('Arrives')->dateTime('d/m/Y H:i'),
                                    ])
                                    ->columns(3),
                            ]),

                        Section::make('Passengers')
                            ->schema([
                                RepeatableEntry::make('passengers')
                                    ->label('')
                                    ->schema([
                                        TextEntry::make('type')->badge(),
                                        TextEntry::make('first_name')->label('First name'),
                                        TextEntry::make('last_name')->label('Last name'),
                                        TextEntry::make('ticket_number')->label('Ticket')->placeholder('—'),
                                    ])
                                    ->columns(4),
                            ]),

                        Section::make('Payments')
                            ->schema([
                                RepeatableEntry::make('payments')
                                    ->label('')
                                    ->schema([
                                        TextEntry::make('gateway')->badge(),
                                        TextEntry::make('status')
                                            ->badge()
                                            ->formatStateUsing(fn (string $state) => ucfirst(str_replace('_', ' ', $state)))
                                            ->color(fn (string $state) => str_contains($state, 'refund') ? 'warning' : ($state === 'succeeded' ? 'success' : 'gray')),
                                        TextEntry::make('amount_cents')
                                            ->label('Amount')
                                            ->formatStateUsing(fn ($state, $record) => "{$record->currency} ".number_format($state / 100, 2)),
                                        TextEntry::make('created_at')->label('Date')->dateTime('d/m/Y H:i'),
                                    ])
                                    ->columns(4),
                            ])
                            ->visible(fn (Booking $record) => $record->payments->isNotEmpty()),
                    ]),

                Action::make('pay')
                    ->label('Pay now')
                    ->icon('heroicon-o-credit-card')
                    ->color('primary')
                    ->visible(fn (Booking $record) => $record->status === Booking::STATUS_HELD && ! $record->hasExpired())
                    ->url(fn (Booking $record) => BookFlight::getUrl(['booking' => $record->id])),

                // Self-service equivalent of the staff "Check payment"
                // action: for a booking left in pending_payment because a
                // gateway webhook was slow or dropped, poll the gateway
                // directly instead of waiting for the nightly reconcile.
                Action::make('checkPayment')
                    ->label('Check payment')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (Booking $record) => $record->status === Booking::STATUS_PENDING_PAYMENT)
                    ->action(function (Booking $record) {
                        $payment = $record->payments()->where('status', Payment::STATUS_PENDING)->latest()->first();

                        if (! $payment) {
                            Notification::make()->warning()->title('No payment in progress to check.')->send();

                            return;
                        }

                        try {
                            app(PaymentService::class)->reconcile($payment);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not check the payment right now.')->send();

                            return;
                        }

                        $status = $record->fresh()->status;

                        Notification::make()
                            ->status($status === Booking::STATUS_CONFIRMED ? 'success' : 'info')
                            ->title(match ($status) {
                                Booking::STATUS_CONFIRMED => 'Payment confirmed — your booking is ticketed.',
                                Booking::STATUS_HELD => 'That payment didn’t go through. You can try paying again.',
                                default => 'Still processing — check back in a few minutes.',
                            })
                            ->send();
                    }),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Cancelling a confirmed booking may be subject to the fare\'s refund rules.')
                    ->visible(fn (Booking $record) => ! in_array($record->status, [
                        Booking::STATUS_CANCELLED, Booking::STATUS_REFUNDED,
                        Booking::STATUS_EXPIRED, Booking::STATUS_PENDING_PAYMENT,
                    ], true))
                    ->form([
                        Textarea::make('reason')->label('Reason (optional)')->maxLength(500),
                    ])
                    ->action(function (Booking $record, array $data) {
                        try {
                            app(CancellationService::class)->cancel($record, 'user', auth()->id(), (string) ($data['reason'] ?? ''));
                            Notification::make()->success()->title('Booking cancelled')->send();
                        } catch (BookingException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ])
            ->emptyStateHeading('No bookings yet')
            ->emptyStateDescription('Search for a flight and your held and confirmed trips will show up here.')
            ->emptyStateIcon('heroicon-o-ticket');
    }
}
