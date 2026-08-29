<x-filament-panels::page>
    @php
        $steps = [1 => 'New itinerary', 2 => 'Choose offer', 3 => 'Fare difference', 4 => 'Done'];
        $b = $this->booking;

        $bookingUrl = auth()->user()?->can('view_any_booking')
            ? \App\Filament\Resources\BookingResource::getUrl('view', ['record' => $this->bookingId])
            : \App\Filament\Pages\MyBookings::getUrl();
    @endphp

    <ol class="flex flex-wrap items-center gap-2 text-sm">
        @foreach($steps as $n => $label)
            <li class="flex items-center gap-2">
                <span @class([
                    'flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold',
                    'bg-primary-600 text-white' => $step === $n,
                    'bg-success-500 text-white' => $step > $n,
                    'bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400' => $step < $n,
                ])>@if($step > $n) &check; @else {{ $n }} @endif</span>
                <span @class(['font-medium text-gray-950 dark:text-white' => $step === $n, 'text-gray-400' => $step !== $n])>{{ $label }}</span>
                @if(! $loop->last)<span class="mx-1 text-gray-300 dark:text-white/20">&rarr;</span>@endif
            </li>
        @endforeach
    </ol>

    @if($b)
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Booking #{{ $b->id }}</span>
                    @if($b->pnr)<span class="text-gray-500 dark:text-gray-400"> · PNR {{ $b->pnr }}</span>@endif
                </div>
                <div class="text-gray-500 dark:text-gray-400">
                    Current total:
                    <span class="font-semibold text-gray-950 dark:text-white">{{ $b->currency }} {{ number_format($b->total_price_cents / 100, 2) }}</span>
                </div>
            </div>
            <div class="mt-3 flex flex-col gap-1 text-sm text-gray-600 dark:text-gray-300">
                @foreach($b->segments as $seg)
                    <div>
                        {{ $seg->origin }} &rarr; {{ $seg->destination }}
                        · {{ $seg->carrier_iata }}{{ $seg->flight_number }}
                        · {{ $seg->departs_at?->format('d M H:i') }}
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- ── Step 1 · New itinerary ─────────────────────────────────────── --}}
    @if($step === 1)
        <form wire:submit="searchChangeOffers" class="space-y-6">
            {{ $this->form }}
            <div class="flex justify-between">
                <x-filament::button tag="a" color="gray"
                    href="{{ $bookingUrl }}">
                    Cancel
                </x-filament::button>
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="searchChangeOffers"
                    icon="heroicon-o-magnifying-glass">
                    Find change offers
                </x-filament::button>
            </div>
        </form>
    @endif

    {{-- ── Step 2 · Choose a change offer ─────────────────────────────── --}}
    @if($step === 2)
        <div class="space-y-4">
            @foreach($this->changeOffers as $offer)
                @php
                    $offerId = data_get($offer, 'provider_offer_id', data_get($offer, 'id'));
                    $newTotal = (float) data_get($offer, 'new_total_amount', data_get($offer, 'total_amount', 0));
                    $currency = data_get($offer, 'new_total_currency', data_get($offer, 'total_currency', $b?->currency));
                    $changeAmount = data_get($offer, 'change_total_amount');
                    $diff = $b ? $newTotal - ($b->total_price_cents / 100) : null;
                @endphp
                <x-filament::section>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="space-y-1 text-sm">
                            <div class="font-medium text-gray-950 dark:text-white">New total: {{ $currency }} {{ number_format($newTotal, 2) }}</div>
                            @if(! is_null($diff))
                                <div @class([
                                    'text-danger-600 dark:text-danger-400' => $diff > 0,
                                    'text-success-600 dark:text-success-400' => $diff <= 0,
                                ])>
                                    {{ $diff > 0 ? 'Extra to pay' : 'Cheaper by' }}:
                                    {{ $currency }} {{ number_format(abs($diff), 2) }}
                                    @if($diff < 0) <span class="text-gray-400">(not auto-refunded)</span> @endif
                                </div>
                            @endif
                            @if($changeAmount)
                                <div class="text-gray-400">Airline change fee component: {{ $currency }} {{ number_format((float) $changeAmount, 2) }}</div>
                            @endif
                            @foreach(data_get($offer, 'slices.add', data_get($offer, 'slices', [])) as $slice)
                                <div class="text-gray-500 dark:text-gray-400">
                                    {{ data_get($slice, 'origin.iata_code', data_get($slice, 'origin')) }}
                                    &rarr;
                                    {{ data_get($slice, 'destination.iata_code', data_get($slice, 'destination')) }}
                                    @php($seg0 = data_get($slice, 'segments.0'))
                                    @if($seg0)
                                        · {{ \Illuminate\Support\Carbon::parse(data_get($seg0, 'departing_at'))->format('d M H:i') }}
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <x-filament::button
                            wire:click="applyChange('{{ $offerId }}')"
                            wire:loading.attr="disabled"
                            wire:target="applyChange('{{ $offerId }}')"
                            icon="heroicon-o-check"
                        >
                            Apply this change
                        </x-filament::button>
                    </div>
                </x-filament::section>
            @endforeach

            <x-filament::button color="gray" wire:click="backToItinerary">Back</x-filament::button>
        </div>
    @endif

    {{-- ── Step 3 · Pay the fare difference ──────────────────────────── --}}
    @if($step === 3)
        @include('filament.pages.partials.payment-panel', [
            'amountLabel' => ($b?->currency ?? '').' '.number_format(($this->differenceCents ?? 0) / 100, 2),
            'amountCaption' => 'Fare difference',
        ])
    @endif

    {{-- ── Step 4 · Done ─────────────────────────────────────────────── --}}
    @if($step === 4)
        <x-filament::section>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-success-500 text-white text-2xl">&check;</span>
                <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Booking changed</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Booking #{{ $this->bookingId }} has been updated.</p>
                <x-filament::button tag="a"
                    href="{{ $bookingUrl }}">
                    View booking
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
