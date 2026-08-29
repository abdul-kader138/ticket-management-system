{{-- Renders a raw provider offer (Duffel shape). Expects $offer (array). --}}
@php
    $fmtTime = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->format('H:i') : '—';
    $fmtDay = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->format('D, j M') : '';
    $fmtDur = function ($iso) {
        if (! $iso) return null;
        try { $i = new \DateInterval((string) $iso); } catch (\Exception) { return null; }
        $h = ($i->d * 24) + $i->h;
        return trim(($h ? $h.'h ' : '').($i->i ? $i->i.'m' : '')) ?: '0m';
    };
    $currency = data_get($offer, 'total_currency', '');
    $amount = (float) data_get($offer, 'total_amount', 0);
    $owner = data_get($offer, 'owner.name', data_get($offer, 'owner.iata_code', 'Airline'));
@endphp

<div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 p-4 space-y-4">
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            @if(data_get($offer, 'owner.logo_symbol_url'))
                <img src="{{ data_get($offer, 'owner.logo_symbol_url') }}" alt="" class="w-7 h-7 rounded object-contain bg-white p-0.5">
            @endif
            <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $owner }}</span>
        </div>
        <div class="text-right">
            <div class="text-lg font-bold text-gray-950 dark:text-white tabular-nums">{{ $currency }} {{ number_format($amount, 2) }}</div>
            <div class="text-xs text-gray-400">total</div>
        </div>
    </div>

    @foreach(data_get($offer, 'slices', []) as $slice)
        @php
            $segments = data_get($slice, 'segments', []);
            $first = $segments[0] ?? null;
            $last = end($segments) ?: null;
            $stops = max(0, count($segments) - 1);
        @endphp
        <div class="flex items-center gap-4 border-t border-gray-100 dark:border-white/5 pt-3 first:border-0 first:pt-0">
            <div class="text-center shrink-0 w-16">
                <div class="text-base font-semibold text-gray-950 dark:text-white tabular-nums">{{ $fmtTime(data_get($first, 'departing_at')) }}</div>
                <div class="text-xs text-gray-400">{{ data_get($slice, 'origin.iata_code', data_get($first, 'origin.iata_code', '—')) }}</div>
            </div>
            <div class="flex-1">
                <div class="text-[11px] text-center text-gray-400">{{ $fmtDur(data_get($slice, 'duration')) ?? $fmtDay(data_get($first, 'departing_at')) }}</div>
                <div class="relative h-px bg-gray-200 dark:bg-white/10 my-1">
                    <span class="absolute -top-[3px] left-0 w-1.5 h-1.5 rounded-full bg-primary-500"></span>
                    <span class="absolute -top-[3px] right-0 w-1.5 h-1.5 rounded-full bg-primary-500"></span>
                </div>
                <div class="text-[11px] text-center {{ $stops === 0 ? 'text-success-600' : 'text-gray-400' }}">
                    {{ $stops === 0 ? 'Direct' : $stops.' '.Str::plural('stop', $stops) }}
                </div>
            </div>
            <div class="text-center shrink-0 w-16">
                <div class="text-base font-semibold text-gray-950 dark:text-white tabular-nums">{{ $fmtTime(data_get($last, 'arriving_at')) }}</div>
                <div class="text-xs text-gray-400">{{ data_get($slice, 'destination.iata_code', data_get($last, 'destination.iata_code', '—')) }}</div>
            </div>
        </div>
    @endforeach
</div>
