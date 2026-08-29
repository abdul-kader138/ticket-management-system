<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\UserSubscription;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
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
 * Customer-facing "Payments" menu — every charge and refund on the
 * account, scoped to auth()->id(). Staff use the Operations PaymentResource
 * instead, so this hides itself for anyone holding `view_any_payment`.
 */
class MyPayments extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $title = 'Payments';

    protected static ?string $slug = 'my-payments';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.my-payments';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->check() && ! auth()->user()->can('view_any_payment');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Payment::query()
                ->where('user_id', auth()->id())
                ->with(['payable', 'refunds']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('Ref')->prefix('#')->sortable(),

                TextColumn::make('payable')
                    ->label('For')
                    ->getStateUsing(fn (Payment $record) => match (true) {
                        $record->payable instanceof Booking => 'Booking #'.$record->payable->id,
                        $record->payable_type === UserSubscription::class => 'Subscription',
                        default => '—',
                    }),

                TextColumn::make('gateway')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),

                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (Payment $record) => "{$record->currency} ".number_format($record->amount_cents / 100, 2)),

                TextColumn::make('refunded')
                    ->label('Refunded')
                    ->getStateUsing(function (Payment $record) {
                        $cents = $record->totalRefundedCents();

                        return $cents > 0 ? "{$record->currency} ".number_format($cents / 100, 2) : '—';
                    }),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state) => match ($state) {
                        Payment::STATUS_SUCCEEDED => 'success',
                        Payment::STATUS_PENDING => 'warning',
                        Payment::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')->label('Date')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('gateway')->options(['stripe' => 'Stripe', 'paypal' => 'PayPal']),
                SelectFilter::make('status')->options([
                    Payment::STATUS_PENDING => 'Pending',
                    Payment::STATUS_SUCCEEDED => 'Succeeded',
                    Payment::STATUS_FAILED => 'Failed',
                    Payment::STATUS_REFUNDED => 'Refunded',
                    Payment::STATUS_PARTIALLY_REFUNDED => 'Partially refunded',
                ]),
            ])
            ->actions([
                ViewAction::make()
                    ->modalHeading(fn (Payment $record) => "Payment #{$record->id}")
                    ->infolist([
                        Section::make()
                            ->columns(3)
                            ->schema([
                                TextEntry::make('gateway')->formatStateUsing(fn (string $s) => ucfirst($s)),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $s) => ucfirst(str_replace('_', ' ', $s))),
                                TextEntry::make('amount_cents')
                                    ->label('Amount')
                                    ->formatStateUsing(fn (Payment $r) => "{$r->currency} ".number_format($r->amount_cents / 100, 2)),
                                TextEntry::make('gateway_reference')->label('Gateway reference')->placeholder('—')->copyable(),
                                TextEntry::make('created_at')->label('Paid on')->dateTime('d/m/Y H:i'),
                                TextEntry::make('payable')
                                    ->label('For')
                                    ->state(fn (Payment $r) => $r->payable instanceof Booking ? "Booking #{$r->payable->id}" : 'Subscription'),
                            ]),

                        Section::make('Refunds')
                            ->schema([
                                RepeatableEntry::make('refunds')
                                    ->label('')
                                    ->schema([
                                        TextEntry::make('amount_cents')
                                            ->label('Amount')
                                            ->formatStateUsing(fn ($state, $record) => "{$record->currency} ".number_format($state / 100, 2)),
                                        TextEntry::make('reason')->placeholder('—'),
                                        TextEntry::make('status')->badge(),
                                        TextEntry::make('created_at')->label('Date')->dateTime('d/m/Y H:i'),
                                    ])
                                    ->columns(4),
                            ])
                            ->visible(fn (Payment $record) => $record->refunds->isNotEmpty()),
                    ]),

                Action::make('viewBooking')
                    ->label('View booking')
                    ->icon('heroicon-o-ticket')
                    ->color('gray')
                    ->visible(fn (Payment $record) => $record->payable instanceof Booking)
                    ->url(fn () => MyBookings::getUrl()),
            ])
            ->emptyStateHeading('No payments yet')
            ->emptyStateDescription('Charges and refunds for your bookings will appear here.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }
}
