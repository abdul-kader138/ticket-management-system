<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 10px; }
        h1 { color: #111827; font-size: 20px; margin: 0 0 4px; }
        .period { color: #6b7280; margin-bottom: 18px; }
        .cards { width: 100%; margin-bottom: 18px; }
        .card { display: inline-block; width: 23%; margin-right: 1%; padding: 10px; background: #f3f4f6; border-radius: 5px; }
        .card:last-child { margin-right: 0; }
        .label { color: #6b7280; font-size: 9px; }
        .value { color: #111827; font-size: 14px; font-weight: bold; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #e5e7eb; color: #374151; text-align: left; }
        th, td { border-bottom: 1px solid #d1d5db; padding: 6px; }
        .overview td { width: 33%; border: 0; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="period">Period: {{ $from->toDateString() }} to {{ $to->toDateString() }}</div>

    <div class="cards">
        <div class="card"><div class="label">Bookings</div><div class="value">{{ number_format($summary['bookings']) }}</div></div>
        <div class="card"><div class="label">Confirmed</div><div class="value">{{ number_format($summary['confirmed']) }}</div></div>
        <div class="card"><div class="label">Net revenue</div><div class="value">{{ $summary['currency'] }} {{ number_format($summary['net'] / 100, 2) }}</div></div>
        <div class="card"><div class="label">{{ auth()->user()->hasRole('super_admin') || auth()->user()->getAllPermissions()->isNotEmpty() ? 'Customers' : 'Account' }}</div><div class="value">{{ number_format($summary['customers']) }}</div></div>
    </div>

    @if($rows === [])
        <table class="overview">
            <tr><td><strong>Payments processed</strong><br>{{ number_format($summary['payments']) }}</td><td><strong>Gross revenue</strong><br>{{ $summary['currency'] }} {{ number_format($summary['gross'] / 100, 2) }}</td><td><strong>Refunds</strong><br>{{ $summary['currency'] }} {{ number_format($summary['refunds'] / 100, 2) }}</td></tr>
        </table>
    @else
        <table>
            <thead><tr>@foreach(array_keys($rows[0]) as $heading)<th>{{ ucfirst(str_replace('_', ' ', $heading)) }}</th>@endforeach</tr></thead>
            <tbody>@foreach($rows as $row)<tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>@endforeach</tbody>
        </table>
    @endif
</body>
</html>
