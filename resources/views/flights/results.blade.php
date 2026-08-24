<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Flight Results — {{ \App\Models\Setting::get('app_name', config('app.name', 'Flight Search')) }}</title>

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.brand-theme')
</head>
<body class="bg-[var(--bg)] text-[var(--fg)] antialiased min-h-screen">

    <header class="bg-[var(--card)] border-b border-[var(--card-border)]">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold text-[var(--fg)]">
                    {{ \App\Models\Setting::get('app_name', config('app.name', 'Flight Search')) }}
                </h1>
            </div>
            <div class="flex items-center gap-4 text-sm">
                @if(auth()->user()?->canAccessPanel(\Filament\Facades\Filament::getDefaultPanel()))
                    <a href="{{ \Filament\Facades\Filament::getUrl() }}" class="text-[var(--muted)] hover:text-[var(--fg)]">Admin Dashboard</a>
                @endif
                <a href="{{ route('flights.search') }}" class="text-[var(--brand)] hover:underline">&larr; New search</a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-8">

        <h2 class="text-base font-semibold text-[var(--fg)] mb-4">
            {{ count($offers) }} {{ Str::plural('flight', count($offers)) }} found
        </h2>

        @if(count($offers) === 0)
            <div class="bg-[var(--card)] rounded-xl border border-[var(--card-border)] p-8 text-center text-[var(--muted)]">
                No offers were returned for this search. Try different airports or dates.
            </div>
        @endif

        <div class="space-y-3">
            @foreach($offers as $offer)
                @php
                    $owner = data_get($offer, 'owner.name', data_get($offer, 'owner.iata_code', 'Airline'));
                    $amount = data_get($offer, 'total_amount');
                    $currency = data_get($offer, 'total_currency');
                @endphp

                <div class="bg-[var(--card)] rounded-xl border border-[var(--card-border)] p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="flex-1">
                        <div class="text-sm font-medium text-[var(--fg)] mb-2">{{ $owner }}</div>

                        @foreach(data_get($offer, 'slices', []) as $slice)
                            @php
                                $segments = data_get($slice, 'segments', []);
                                $first = $segments[0] ?? null;
                                $last = end($segments) ?: null;
                            @endphp
                            <div class="text-sm text-[var(--muted)] flex items-center gap-2 py-0.5">
                                <span class="font-medium text-[var(--fg)]">
                                    {{ data_get($slice, 'origin.iata_code', data_get($first, 'origin.iata_code', '—')) }}
                                </span>
                                <span>&rarr;</span>
                                <span class="font-medium text-[var(--fg)]">
                                    {{ data_get($slice, 'destination.iata_code', data_get($last, 'destination.iata_code', '—')) }}
                                </span>
                                <span class="text-[var(--muted)]">
                                    {{ count($segments) > 1 ? (count($segments) - 1).' stop(s)' : 'Direct' }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <div class="text-lg font-semibold text-[var(--fg)]">
                                {{ $currency }} {{ $amount }}
                            </div>
                        </div>
                        <button
                            type="button"
                            disabled
                            title="Booking is not built yet"
                            class="bg-[var(--card-border)] text-[var(--muted)] cursor-not-allowed rounded-md px-5 py-2 text-sm font-semibold"
                        >
                            Select
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        <details class="mt-8 bg-[var(--card)] rounded-xl border border-[var(--card-border)] p-4">
            <summary class="text-sm text-[var(--muted)] cursor-pointer">Raw provider response (debug)</summary>
            <pre class="mt-3 text-xs text-[var(--muted)] overflow-x-auto whitespace-pre-wrap">{{ json_encode($raw, JSON_PRETTY_PRINT) }}</pre>
        </details>
    </main>

</body>
</html>
