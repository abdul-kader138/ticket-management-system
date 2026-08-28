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

    {{-- Embedded via an iframe by App\Filament\Pages\FlightSearch, which --}}
    {{-- already provides the app header/sidebar — no header here. --}}
    @php
        $fmtDuration = function (?string $iso) {
            if (! $iso) {
                return null;
            }
            try {
                $i = new \DateInterval($iso);
            } catch (\Exception) {
                return null;
            }
            $hours = ($i->d * 24) + $i->h;

            return trim(($hours ? $hours.'h ' : '').($i->i ? $i->i.'m' : '')) ?: '0m';
        };
        $fmtTime = fn (?string $dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->format('H:i') : '—';
        $fmtDay = fn (?string $dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->format('D, j M') : '';

        // One lightweight view-model row per offer so the markup and the
        // Alpine sort stay simple. sliceMinutes() turns Duffel's ISO-8601
        // slice duration into an integer for "fastest" sorting.
        $sliceMinutes = function ($slice) {
            try {
                $i = new \DateInterval((string) data_get($slice, 'duration', 'PT0M'));

                return ($i->d * 24 * 60) + ($i->h * 60) + $i->i;
            } catch (\Exception) {
                return 0;
            }
        };

        $rows = collect($offers)->map(function (array $offer) use ($sliceMinutes) {
            $slices = data_get($offer, 'slices', []);
            $firstDep = data_get($slices, '0.segments.0.departing_at');

            return [
                'offer' => $offer,
                'amount' => (float) data_get($offer, 'total_amount', 0),
                'currency' => data_get($offer, 'total_currency', ''),
                'depart_ts' => $firstDep ? \Illuminate\Support\Carbon::parse($firstDep)->timestamp : PHP_INT_MAX,
                'duration_min' => collect($slices)->sum($sliceMinutes),
                'stops' => collect($slices)->sum(fn ($s) => max(0, count(data_get($s, 'segments', [])) - 1)),
            ];
        })->values();

        $cheapest = $rows->min('amount');
        $fastest = $rows->where('duration_min', '>', 0)->min('duration_min');

        $travellers = (int) ($search['adults'] ?? 1) + (int) ($search['children'] ?? 0) + (int) ($search['infants'] ?? 0);
    @endphp

    <main class="max-w-5xl mx-auto px-4 py-8"
          x-data="{
              sort: 'price',
              meta: {{ Illuminate\Support\Js::from($rows->map(fn ($r) => ['amount' => $r['amount'], 'depart' => $r['depart_ts'], 'duration' => $r['duration_min'] ?: 1000000])) }},
              get positions() {
                  const idx = this.meta.map((_, i) => i).sort((a, b) => {
                      const x = this.meta[a], y = this.meta[b];
                      if (this.sort === 'price') return x.amount - y.amount || x.depart - y.depart;
                      if (this.sort === 'duration') return x.duration - y.duration || x.amount - y.amount;
                      return x.depart - y.depart || x.amount - y.amount;
                  });
                  const p = {};
                  idx.forEach((originalIndex, rank) => { p[originalIndex] = rank; });
                  return p;
              },
          }">

        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <div>
                <h2 class="text-lg font-semibold text-[var(--fg)]">
                    {{ count($offers) }} {{ Str::plural('flight', count($offers)) }} found
                </h2>
                @if(! empty($search['legs']))
                    <p class="text-sm text-[var(--muted)] mt-0.5">
                        {{ collect($search['legs'])->map(fn ($l) => \App\Services\Flights\DTO\SearchCriteria::extractIataCode($l['from']).' → '.\App\Services\Flights\DTO\SearchCriteria::extractIataCode($l['to']))->join('  ·  ') }}
                        &nbsp;•&nbsp; {{ ucfirst(str_replace('_', ' ', $search['cabin_class'] ?? 'economy')) }}
                        &nbsp;•&nbsp; {{ $travellers }} {{ Str::plural('traveler', $travellers) }}
                    </p>
                @endif
            </div>

            <a href="{{ route('flights.embed') }}"
               class="inline-flex items-center gap-1.5 rounded-md border border-[var(--card-border)] bg-[var(--card)] px-3.5 py-2 text-sm font-medium text-[var(--fg)] hover:bg-[var(--hover-bg)] transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.612l4.158 3.96a.75.75 0 1 1-1.04 1.08l-5.5-5.24a.75.75 0 0 1 0-1.1l5.5-5.24a.75.75 0 1 1 1.04 1.08L5.612 9.25H16.25A.75.75 0 0 1 17 10Z" clip-rule="evenodd" /></svg>
                New search
            </a>
        </div>

        @if(count($offers) === 0)
            <div class="bg-[var(--card)] rounded-xl border border-[var(--card-border)] p-10 text-center">
                <svg class="w-10 h-10 mx-auto text-[var(--muted)] mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                <p class="text-sm font-medium text-[var(--fg)]">No offers for this search</p>
                <p class="text-sm text-[var(--muted)] mt-1">Try nearby airports, different dates, or a broader cabin class.</p>
            </div>
        @else
            <div class="flex items-center gap-2 mb-4 text-sm">
                <span class="text-[var(--muted)]">Sort by</span>
                <div class="inline-flex rounded-md border border-[var(--card-border)] overflow-hidden">
                    @foreach(['price' => 'Cheapest', 'duration' => 'Fastest', 'departure' => 'Earliest'] as $value => $label)
                        <button type="button"
                                @click="sort = '{{ $value }}'"
                                class="px-3 py-1.5 transition-colors"
                                :class="sort === '{{ $value }}' ? 'bg-[var(--brand)] text-white' : 'text-[var(--muted)] hover:bg-[var(--hover-bg)]'">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-col gap-3">
                @foreach($rows as $index => $row)
                    @php
                        $offer = $row['offer'];
                        $owner = data_get($offer, 'owner.name', data_get($offer, 'owner.iata_code', 'Airline'));
                        $logo = data_get($offer, 'owner.logo_symbol_url');
                        $refundable = data_get($offer, 'conditions.refund_before_departure.allowed');
                    @endphp
                    <div class="bg-[var(--card)] rounded-xl border border-[var(--card-border)] p-5 hover:border-[var(--brand)] transition-colors"
                         :style="'order:' + (positions[{{ $index }}] ?? {{ $index }})">
                        <div class="flex flex-col lg:flex-row lg:items-center gap-5">

                            <div class="flex items-center gap-3 lg:w-44 shrink-0">
                                @if($logo)
                                    <img src="{{ $logo }}" alt="{{ $owner }}" class="w-8 h-8 rounded object-contain bg-white p-0.5" loading="lazy">
                                @else
                                    <span class="w-8 h-8 rounded bg-[var(--hover-bg)] grid place-items-center text-[var(--muted)]">
                                        <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path d="M3.105 2.289a.75.75 0 0 0-.826.95l1.414 4.926A1.5 1.5 0 0 0 5.135 10H12.5a.5.5 0 0 1 0 1H5.135a1.5 1.5 0 0 0-1.442 1.086l-1.414 4.926a.75.75 0 0 0 .826.95 28.897 28.897 0 0 0 15.293-7.155.75.75 0 0 0 0-1.114A28.897 28.897 0 0 0 3.105 2.289Z" /></svg>
                                    </span>
                                @endif
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-[var(--fg)] truncate">{{ $owner }}</div>
                                    <div class="text-xs text-[var(--muted)]">{{ data_get($offer, 'provider_code', 'duffel') }}</div>
                                </div>
                            </div>

                            <div class="flex-1 space-y-3">
                                @foreach(data_get($offer, 'slices', []) as $slice)
                                    @php
                                        $segments = data_get($slice, 'segments', []);
                                        $first = $segments[0] ?? null;
                                        $last = end($segments) ?: null;
                                        $stops = max(0, count($segments) - 1);
                                    @endphp
                                    <div>
                                        <div class="flex items-center gap-3 sm:gap-5">
                                            <div class="text-center shrink-0">
                                                <div class="text-base font-semibold text-[var(--fg)] tabular-nums">{{ $fmtTime(data_get($first, 'departing_at')) }}</div>
                                                <div class="text-xs text-[var(--muted)]">{{ data_get($slice, 'origin.iata_code', data_get($first, 'origin.iata_code', '—')) }}</div>
                                            </div>

                                            <div class="flex-1">
                                                <div class="text-[11px] text-center text-[var(--muted)] mb-1">{{ $fmtDuration(data_get($slice, 'duration')) ?? '' }}</div>
                                                <div class="relative h-px bg-[var(--card-border)]">
                                                    <span class="absolute -top-[3px] left-0 w-1.5 h-1.5 rounded-full bg-[var(--brand)]"></span>
                                                    @if($stops > 0)
                                                        <span class="absolute -top-[3px] left-1/2 -translate-x-1/2 w-1.5 h-1.5 rounded-full bg-[var(--muted)]"></span>
                                                    @endif
                                                    <span class="absolute -top-[3px] right-0 w-1.5 h-1.5 rounded-full bg-[var(--brand)]"></span>
                                                </div>
                                                <div class="text-[11px] text-center mt-1 {{ $stops === 0 ? 'text-emerald-600' : 'text-[var(--muted)]' }}">
                                                    {{ $stops === 0 ? 'Direct' : $stops.' '.Str::plural('stop', $stops) }}
                                                </div>
                                            </div>

                                            <div class="text-center shrink-0">
                                                <div class="text-base font-semibold text-[var(--fg)] tabular-nums">{{ $fmtTime(data_get($last, 'arriving_at')) }}</div>
                                                <div class="text-xs text-[var(--muted)]">{{ data_get($slice, 'destination.iata_code', data_get($last, 'destination.iata_code', '—')) }}</div>
                                            </div>
                                        </div>
                                        <div class="text-[11px] text-[var(--muted)] mt-1">
                                            {{ $fmtDay(data_get($first, 'departing_at')) }}
                                            @if($first && data_get($first, 'marketing_carrier_flight_number'))
                                                · {{ data_get($first, 'marketing_carrier.iata_code', '') }}{{ data_get($first, 'marketing_carrier_flight_number') }}
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="lg:w-40 shrink-0 lg:text-right border-t lg:border-t-0 lg:border-l border-[var(--card-border)] pt-3 lg:pt-0 lg:pl-5">
                                <div class="text-xl font-bold text-[var(--fg)] tabular-nums">
                                    {{ $row['currency'] }} {{ number_format($row['amount'], 2) }}
                                </div>
                                <div class="text-[11px] text-[var(--muted)] mb-2">total{{ $travellers > 1 ? ' · '.$row['currency'].' '.number_format($row['amount'] / max(1, $travellers), 2).' pp' : '' }}</div>

                                <div class="flex lg:justify-end flex-wrap gap-1 mb-3">
                                    @if($row['amount'] === $cheapest)
                                        <span class="text-[10px] font-semibold uppercase tracking-wide rounded bg-emerald-100 text-emerald-700 px-1.5 py-0.5">Cheapest</span>
                                    @endif
                                    @if($fastest && $row['duration_min'] === $fastest)
                                        <span class="text-[10px] font-semibold uppercase tracking-wide rounded bg-sky-100 text-sky-700 px-1.5 py-0.5">Fastest</span>
                                    @endif
                                    @if($refundable)
                                        <span class="text-[10px] font-semibold uppercase tracking-wide rounded bg-[var(--hover-bg)] text-[var(--muted)] px-1.5 py-0.5">Refundable</span>
                                    @endif
                                </div>

                                <button type="button" disabled
                                        title="Booking checkout isn't part of this admin test screen — see POST /api/v1/bookings"
                                        class="w-full lg:w-auto bg-[var(--card-border)] text-[var(--muted)] cursor-not-allowed rounded-md px-5 py-2 text-sm font-semibold">
                                    Select
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <details class="mt-8 bg-[var(--card)] rounded-xl border border-[var(--card-border)] p-4">
            <summary class="text-sm text-[var(--muted)] cursor-pointer select-none">Raw provider response (debug)</summary>
            <pre class="mt-3 text-xs text-[var(--muted)] overflow-x-auto whitespace-pre-wrap">{{ json_encode($offers, JSON_PRETTY_PRINT) }}</pre>
        </details>
    </main>

</body>
</html>
