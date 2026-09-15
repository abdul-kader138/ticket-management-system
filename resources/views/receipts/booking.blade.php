<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $title }}</title>
<style>
@page { margin: 30px; } body { font-family: DejaVu Sans, sans-serif; color:#1f2937; font-size:11px; }
h1 { font-size:22px; margin:0 0 4px; } h2 { font-size:14px; border-bottom:1px solid #d1d5db; padding-bottom:5px; margin:20px 0 8px; }
.muted { color:#6b7280; } .grid { width:100%; } .grid td { width:50%; vertical-align:top; padding:3px 0; }
table { width:100%; border-collapse:collapse; } th { background:#e5e7eb; text-align:left; } th,td { border-bottom:1px solid #d1d5db; padding:7px 5px; }
.status { font-weight:bold; text-transform:uppercase; } .total { text-align:right; font-size:14px; font-weight:bold; margin-top:10px; }
</style></head><body>
<h1>{{ $title }}</h1>
<div class="muted">Booking #{{ $booking->id }} · Generated {{ now()->format('d M Y H:i') }}</div>
<h2>Booking details</h2>
<table class="grid"><tr><td><strong>Customer</strong><br>{{ $booking->user?->name }}<br>{{ $booking->user?->email }}</td><td><strong>Status</strong><br><span class="status">{{ str_replace('_', ' ', $booking->status) }}</span><br><strong>PNR</strong><br>{{ $booking->pnr ?? 'Not issued' }}</td></tr></table>
<h2>Itinerary</h2>
<table><thead><tr><th>Flight</th><th>From</th><th>To</th><th>Departure</th><th>Arrival</th></tr></thead><tbody>
@foreach($booking->segments as $segment)<tr><td>{{ $segment->carrier_name }} {{ $segment->flight_number }}</td><td>{{ $segment->origin }}</td><td>{{ $segment->destination }}</td><td>{{ $segment->departs_at?->format('d M Y H:i') }}</td><td>{{ $segment->arrives_at?->format('d M Y H:i') ?? '—' }}</td></tr>@endforeach
</tbody></table>
<h2>Passengers</h2><table><thead><tr><th>Name</th><th>Type</th><th>Ticket number</th></tr></thead><tbody>
@foreach($booking->passengers as $passenger)<tr><td>{{ $passenger->first_name }} {{ $passenger->last_name }}</td><td>{{ ucfirst($passenger->type) }}</td><td>{{ $passenger->ticket_number ?? 'Not issued' }}</td></tr>@endforeach
</tbody></table>
@if(! $ticket)<h2>Payment summary</h2><table><thead><tr><th>Payment</th><th>Gateway</th><th>Status</th><th>Amount</th></tr></thead><tbody>@foreach($booking->payments as $payment)<tr><td>#{{ $payment->id }}</td><td>{{ ucfirst($payment->gateway) }}</td><td>{{ ucfirst(str_replace('_', ' ', $payment->status)) }}</td><td>{{ $payment->currency }} {{ number_format($payment->amount_cents / 100, 2) }}</td></tr>@endforeach</tbody></table><div class="total">Total: {{ $booking->currency }} {{ number_format($booking->total_price_cents / 100, 2) }}</div>@endif
</body></html>
