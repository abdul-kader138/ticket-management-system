<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use App\Models\Payment;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    // The infolist reads user.name and the refunds collection (also in a
    // visible() closure) — eager-load both so neither lazy-loads.
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['user', 'refunds']);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Payment')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')->badge(),
                    TextEntry::make('gateway')->badge(),
                    TextEntry::make('user.name')->label('Customer'),
                    TextEntry::make('gateway_reference')->label('Gateway Reference')->default('—'),
                    TextEntry::make('amount_cents')
                        ->label('Amount')
                        ->formatStateUsing(fn (Payment $record) => "{$record->currency} ".number_format($record->amount_cents / 100, 2)),
                    TextEntry::make('created_at')->dateTime('d M Y H:i'),
                ]),

            Section::make('Refunds')
                ->visible(fn (Payment $record) => $record->refunds->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('refunds')
                        ->label('')
                        ->schema([
                            TextEntry::make('status')->badge(),
                            TextEntry::make('amount_cents')
                                ->label('Amount')
                                ->formatStateUsing(fn ($record) => number_format($record->amount_cents / 100, 2)),
                            TextEntry::make('reason')->default('—'),
                            TextEntry::make('created_at')->dateTime('d M Y H:i'),
                        ])
                        ->columns(4),
                ]),
        ]);
    }
}
