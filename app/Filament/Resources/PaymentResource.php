<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only ops visibility into payments — see docs/ROADMAP.md, Phase 5.
 * Refunds are initiated through PaymentService (customer self-service or a
 * future admin action, see Phase 6), never by editing a row here.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 71;

    public static function getNavigationGroup(): ?string
    {
        return 'Bookings';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Payment #')->sortable(),

                TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable(['first_name', 'last_name', 'email']),

                TextColumn::make('gateway')->badge(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        Payment::STATUS_SUCCEEDED => 'success',
                        Payment::STATUS_PENDING => 'warning',
                        Payment::STATUS_FAILED => 'danger',
                        Payment::STATUS_REFUNDED, Payment::STATUS_PARTIALLY_REFUNDED => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (Payment $record) => "{$record->currency} ".number_format($record->amount_cents / 100, 2)),

                TextColumn::make('created_at')->label('Created')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('gateway')->options(['stripe' => 'Stripe', 'paypal' => 'PayPal']),
                SelectFilter::make('status')->options([
                    Payment::STATUS_PENDING => 'Pending',
                    Payment::STATUS_SUCCEEDED => 'Succeeded',
                    Payment::STATUS_FAILED => 'Failed',
                    Payment::STATUS_REFUNDED => 'Refunded',
                    Payment::STATUS_PARTIALLY_REFUNDED => 'Partially Refunded',
                ]),
            ])
            ->actions([ViewAction::make()])
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
            'index' => Pages\ListPayments::route('/'),
            'view' => Pages\ViewPayment::route('/{record}'),
        ];
    }
}
