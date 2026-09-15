<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Reports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = null;

    public static function getNavigationLabel(): string
    {
        return __('Reports');
    }

    protected static ?string $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.reports';

    public string $reportType = 'overview';

    public string $dateFrom;

    public string $dateTo;

    public function mount(): void
    {
        $this->dateFrom = now()->startOfMonth()->format('d/m/Y');
        $this->dateTo = now()->format('d/m/Y');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
            Action::make('exportPdf')
                ->label('Export PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn () => $this->exportPdf()),
        ];
    }

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    public function period(): array
    {
        try {
            $from = $this->parseReportDate($this->dateFrom)->startOfDay();
            $to = $this->parseReportDate($this->dateTo)->endOfDay();
        } catch (\Throwable) {
            $from = now()->startOfMonth();
            $to = now()->endOfDay();
        }

        return $from->greaterThan($to)
            ? ['from' => $to->copy()->startOfDay(), 'to' => $from->copy()->endOfDay()]
            : ['from' => $from, 'to' => $to];
    }

    private function parseReportDate(string $date): Carbon
    {
        return Carbon::createFromFormat('!d/m/Y', trim($date));
    }

    /**
     * @return array<string, int|string>
     */
    public function summary(): array
    {
        ['from' => $from, 'to' => $to] = $this->period();

        $bookings = $this->bookingsQuery($from, $to);
        $payments = $this->paymentsQuery($from, $to);
        $capturedStatuses = [Payment::STATUS_SUCCEEDED, Payment::STATUS_REFUNDED, Payment::STATUS_PARTIALLY_REFUNDED];
        $gross = (int) (clone $payments)->whereIn('status', $capturedStatuses)->sum('amount_cents');
        $refunds = (int) $this->refundsQuery($from, $to)->sum('amount_cents');
        $currencies = (clone $payments)->whereIn('status', $capturedStatuses)->distinct()->pluck('currency');

        return [
            'bookings' => (clone $bookings)->count(),
            'confirmed' => (clone $bookings)->whereIn('status', [Booking::STATUS_CONFIRMED, Booking::STATUS_CHANGED])->count(),
            'cancelled' => (clone $bookings)->where('status', Booking::STATUS_CANCELLED)->count(),
            'payments' => (clone $payments)->count(),
            'gross' => $gross,
            'refunds' => $refunds,
            'net' => $gross - $refunds,
            'customers' => $this->isStaff() ? User::whereBetween('created_at', [$from, $to])->count() : 1,
            'currency' => $currencies->count() === 1 ? $currencies->first() : ($currencies->isEmpty() ? 'USD' : 'Mixed'),
        ];
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    public function rows(): array
    {
        ['from' => $from, 'to' => $to] = $this->period();

        return match ($this->reportType) {
            'bookings' => $this->bookingsQuery($from, $to)->with('flightProvider')
                ->select(['id', 'status', 'currency', 'total_price_cents', 'flight_provider_id', 'created_at'])
                ->latest()->limit(500)->get()->map(fn (Booking $booking) => [
                    'reference' => '#'.$booking->id,
                    'status' => str_replace('_', ' ', ucfirst($booking->status)),
                    'provider' => $booking->flightProvider?->name ?? '—',
                    'amount' => $booking->currency.' '.number_format($booking->total_price_cents / 100, 2),
                    'date' => $booking->created_at->format('Y-m-d H:i'),
                ])->all(),
            'payments' => $this->paymentsQuery($from, $to)->with('user')
                ->select(['id', 'user_id', 'gateway', 'status', 'currency', 'amount_cents', 'gateway_reference', 'created_at'])
                ->latest()->limit(500)->get()->map(fn (Payment $payment) => [
                    'reference' => '#'.$payment->id,
                    'customer' => $payment->user?->email ?? '—',
                    'gateway' => ucfirst($payment->gateway),
                    'status' => ucfirst(str_replace('_', ' ', $payment->status)),
                    'amount' => $payment->currency.' '.number_format($payment->amount_cents / 100, 2),
                    'date' => $payment->created_at->format('Y-m-d H:i'),
                ])->all(),
            'fulfilment' => $this->bookingsQuery($from, $to)->with('flightProvider')
                ->select(['id', 'status', 'provider_order_id', 'pnr', 'flight_provider_id', 'created_at'])
                ->latest()->limit(500)->get()->map(fn (Booking $booking) => [
                    'reference' => '#'.$booking->id,
                    'provider' => $booking->flightProvider?->name ?? '—',
                    'status' => str_replace('_', ' ', ucfirst($booking->status)),
                    'provider_order' => $booking->provider_order_id ?? 'Pending',
                    'pnr' => $booking->pnr ?? '—',
                    'date' => $booking->created_at->format('Y-m-d H:i'),
                ])->all(),
            default => [],
        };
    }

    public function reportTitle(): string
    {
        if (! $this->isStaff()) {
            return match ($this->reportType) {
                'bookings' => 'My booking report',
                'payments' => 'My payment report',
                'fulfilment' => 'My ticket fulfilment report',
                default => 'My account overview',
            };
        }

        return match ($this->reportType) {
            'bookings' => 'Booking report',
            'payments' => 'Payment report',
            'fulfilment' => 'Ticket fulfilment report',
            default => 'Business overview',
        };
    }

    private function exportCsv(): StreamedResponse
    {
        $rows = $this->rows();
        $headers = $rows === [] ? ['Report', 'From', 'To'] : array_keys($rows[0]);
        ['from' => $from, 'to' => $to] = $this->period();
        $filename = 'ticket-report-'.$this->reportType.'-'.$from->toDateString().'-'.$to->toDateString().'.csv';

        Notification::make()->success()->title('Report generated')->send();

        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($header) => $row[$header] ?? '', $headers));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function exportPdf()
    {
        ['from' => $from, 'to' => $to] = $this->period();
        $filename = 'ticket-report-'.$this->reportType.'-'.$from->toDateString().'-'.$to->toDateString().'.pdf';

        return Pdf::loadView('filament.pages.reports-pdf', [
            'title' => $this->reportTitle(),
            'summary' => $this->summary(),
            'rows' => $this->rows(),
            'from' => $from,
            'to' => $to,
        ])->setPaper('a4', 'landscape')->download($filename);
    }

    private function isStaff(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super_admin') || $user->getAllPermissions()->isNotEmpty());
    }

    private function bookingsQuery(Carbon $from, Carbon $to)
    {
        return Booking::query()
            ->whereBetween('created_at', [$from, $to])
            ->when(! $this->isStaff(), fn ($query) => $query->where('user_id', auth()->id()));
    }

    private function paymentsQuery(Carbon $from, Carbon $to)
    {
        return Payment::query()
            ->whereBetween('created_at', [$from, $to])
            ->when(! $this->isStaff(), fn ($query) => $query->where('user_id', auth()->id()));
    }

    private function refundsQuery(Carbon $from, Carbon $to)
    {
        return Refund::query()
            ->where('status', Refund::STATUS_SUCCEEDED)
            ->whereBetween('created_at', [$from, $to])
            ->when(! $this->isStaff(), fn ($query) => $query->whereHas('payment', fn ($payment) => $payment->where('user_id', auth()->id())));
    }
}
