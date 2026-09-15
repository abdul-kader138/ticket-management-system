<?php

namespace App\Services\Receipts;

use App\Models\Booking;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;

class ReceiptPdfService
{
    public function booking(Booking $booking, bool $ticket = false)
    {
        $booking->loadMissing(['user', 'flightProvider', 'segments', 'passengers', 'payments.refunds']);

        $title = $ticket ? 'Electronic ticket' : 'Booking receipt';
        $filename = ($ticket ? 'ticket-' : 'booking-receipt-').$booking->id.'.pdf';

        return Pdf::loadView('receipts.booking', [
            'booking' => $booking,
            'title' => $title,
            'ticket' => $ticket,
        ])->setPaper('a4')->download($filename);
    }

    public function payment(Payment $payment)
    {
        $payment->loadMissing(['user', 'payable', 'refunds']);

        return Pdf::loadView('receipts.payment', [
            'payment' => $payment,
        ])->setPaper('a4')->download('payment-receipt-'.$payment->id.'.pdf');
    }
}
