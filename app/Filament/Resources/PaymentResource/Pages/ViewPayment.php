<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\Receipts\ReceiptPdfService;
use Filament\Actions\Action;
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
        return [
            Action::make('downloadReceipt')
                ->label(__('Download receipt'))
                ->icon('heroicon-o-document-arrow-down')
                ->action(fn (Payment $record) => app(ReceiptPdfService::class)->payment($record)),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make(__('Payment'))
                ->columns(3)
                ->schema([
                    TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => __(match ($state) {
                        Payment::STATUS_PENDING => 'Pending',
                        Payment::STATUS_SUCCEEDED => 'Succeeded',
                        Payment::STATUS_FAILED => 'Failed',
                        Payment::STATUS_REFUNDED => 'Refunded',
                        Payment::STATUS_PARTIALLY_REFUNDED => 'Partially Refunded',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    })),
                    TextEntry::make('gateway')->badge(),
                    TextEntry::make('user.name')->label(__('Customer')),
                    TextEntry::make('gateway_reference')->label(__('Gateway Reference'))->default('—'),
                    TextEntry::make('amount_cents')
                        ->label(__('Amount'))
                        ->formatStateUsing(fn (Payment $record) => "{$record->currency} ".number_format($record->amount_cents / 100, 2)),
                    TextEntry::make('created_at')->dateTime('d M Y H:i'),
                ]),

            Section::make(__('Refunds'))
                ->visible(fn (Payment $record) => $record->refunds->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('refunds')
                        ->label('')
                        ->schema([
                            TextEntry::make('status')->badge(),
                            TextEntry::make('amount_cents')
                                ->label(__('Amount'))
                                ->formatStateUsing(fn ($record) => number_format($record->amount_cents / 100, 2)),
                            TextEntry::make('reason')->default('—'),
                            TextEntry::make('created_at')->dateTime('d M Y H:i'),
                        ])
                        ->columns(4),
                ]),
        ]);
    }
}
