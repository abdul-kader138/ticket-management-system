<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Booking;
use App\Models\Payment;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read-only ops visibility into payments — see docs/ROADMAP.md, Phase 5.
 * Refunds are initiated through PaymentService (customer self-service or a
 * future admin action, see Phase 6), never by editing a row here.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    public static function getNavigationLabel(): string
    {
        return __('Payments');
    }

    public static function getModelLabel(): string
    {
        return __('Payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Payments');
    }

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('Operations');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('payable'))
            ->columns([
                TextColumn::make('id')->label(__('Payment #'))->searchable()->sortable(),

                TextColumn::make('gateway_reference')
                    ->label(__('Gateway ref.'))
                    ->searchable()
                    ->default('—')
                    ->toggleable(),

                TextColumn::make('user.name')
                    ->label(__('Customer'))
                    ->searchable(['first_name', 'last_name', 'email']),

                TextColumn::make('payable')
                    ->label(__('For'))
                    ->state(fn (Payment $record) => $record->payable instanceof Booking
                        ? __('Booking').' '.($record->payable->pnr ?: '#'.$record->payable->id)
                        : __('Subscription')),

                TextColumn::make('gateway')->label(__('Gateway'))->badge(),

                TextColumn::make('status')->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __(match ($state) {
                        Payment::STATUS_PENDING => 'Pending',
                        Payment::STATUS_SUCCEEDED => 'Succeeded',
                        Payment::STATUS_FAILED => 'Failed',
                        Payment::STATUS_REFUNDED => 'Refunded',
                        Payment::STATUS_PARTIALLY_REFUNDED => 'Partially Refunded',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    }))
                    ->color(fn (string $state) => match ($state) {
                        Payment::STATUS_SUCCEEDED => 'success',
                        Payment::STATUS_PENDING => 'warning',
                        Payment::STATUS_FAILED => 'danger',
                        Payment::STATUS_REFUNDED, Payment::STATUS_PARTIALLY_REFUNDED => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('amount_cents')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (Payment $record) => "{$record->currency} ".number_format($record->amount_cents / 100, 2)),

                TextColumn::make('created_at')->label(__('Created'))->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('gateway')->options(['stripe' => 'Stripe', 'paypal' => 'PayPal']),
                SelectFilter::make('status')->options([
                    Payment::STATUS_PENDING => __('Pending'),
                    Payment::STATUS_SUCCEEDED => __('Succeeded'),
                    Payment::STATUS_FAILED => __('Failed'),
                    Payment::STATUS_REFUNDED => __('Refunded'),
                    Payment::STATUS_PARTIALLY_REFUNDED => __('Partially Refunded'),
                ]),

                Filter::make('created_at')
                    ->label(__('Created between'))
                    ->form([
                        DatePicker::make('created_from')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->format('Y-m-d')
                            ->placeholder('dd/mm/yyyy'),
                        DatePicker::make('created_until')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->format('Y-m-d')
                            ->placeholder('dd/mm/yyyy'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['created_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['created_from'] ?? null) {
                            $indicators[] = 'Created from '.Carbon::parse($data['created_from'])->toFormattedDateString();
                        }

                        if ($data['created_until'] ?? null) {
                            $indicators[] = 'Created until '.Carbon::parse($data['created_until'])->toFormattedDateString();
                        }

                        return $indicators;
                    }),
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
