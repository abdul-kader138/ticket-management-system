<x-filament-panels::page>
    @php
        $steps = [1 => 'Review', 2 => 'Passengers', 3 => 'Payment', 4 => 'Done'];

        // Staff go to the full Bookings resource; a customer (no
        // view_any_booking permission) would 403 there, so send them to
        // their own "My Bookings" list instead.
        $bookingUrl = fn ($id) => auth()->user()?->can('view_any_booking')
            ? \App\Filament\Resources\BookingResource::getUrl('view', ['record' => $id])
            : \App\Filament\Pages\MyBookings::getUrl();
    @endphp

    {{-- ── Stepper ─────────────────────────────────────────────────────── --}}
    <ol class="flex items-center gap-2 text-sm">
        @foreach($steps as $n => $label)
            <li class="flex items-center gap-2">
                <span @class([
                    'flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold',
                    'bg-primary-600 text-white' => $step === $n,
                    'bg-success-500 text-white' => $step > $n,
                    'bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400' => $step < $n,
                ])>
                    @if($step > $n) &check; @else {{ $n }} @endif
                </span>
                <span @class(['font-medium text-gray-950 dark:text-white' => $step === $n, 'text-gray-400' => $step !== $n])>
                    {{ $label }}
                </span>
                @if(! $loop->last)<span class="mx-1 text-gray-300 dark:text-white/20">&rarr;</span>@endif
            </li>
        @endforeach
    </ol>

    {{-- ── Step 1 · Review the selected offer ──────────────────────────── --}}
    @if($step === 1)
        <div class="space-y-4">
            @include('filament.pages.partials.offer-summary', ['offer' => $offer])

            @php($conditions = data_get($offer, 'conditions', []))
            <div class="flex flex-wrap gap-2 text-xs">
                <span class="rounded bg-gray-100 dark:bg-white/10 px-2 py-1 text-gray-600 dark:text-gray-300">
                    Provider: {{ $provider }}
                </span>
                <span class="rounded bg-gray-100 dark:bg-white/10 px-2 py-1 text-gray-600 dark:text-gray-300">
                    {{ data_get($conditions, 'refund_before_departure.allowed') ? 'Refundable' : 'Non-refundable' }}
                </span>
                <span class="rounded bg-gray-100 dark:bg-white/10 px-2 py-1 text-gray-600 dark:text-gray-300">
                    {{ data_get($conditions, 'change_before_departure.allowed') ? 'Changeable' : 'Changes not allowed' }}
                </span>
                @if($this->describeRequiredPassengers())
                    <span class="rounded bg-primary-100 dark:bg-primary-500/20 px-2 py-1 text-primary-700 dark:text-primary-300">
                        Priced for {{ $this->describeRequiredPassengers() }}
                    </span>
                @endif
                @if(data_get($offer, 'expires_at'))
                    <span class="rounded bg-warning-100 dark:bg-warning-500/20 px-2 py-1 text-warning-700 dark:text-warning-300">
                        Offer expires {{ \Illuminate\Support\Carbon::parse(data_get($offer, 'expires_at'))->format('d M H:i') }}
                    </span>
                @endif
            </div>

            <div class="flex justify-end gap-3">
                <x-filament::button tag="a" href="{{ \App\Filament\Pages\FlightSearch::getUrl() }}" color="gray">
                    Back to search
                </x-filament::button>
                <x-filament::button wire:click="goToPassengers" icon="heroicon-o-arrow-right" icon-position="after">
                    Continue
                </x-filament::button>
            </div>
        </div>
    @endif

    {{-- ── Step 2 · Passengers ─────────────────────────────────────────── --}}
    @if($step === 2)
        <form wire:submit="createHold" class="space-y-6">
            @if($this->describeRequiredPassengers())
                <p class="rounded-lg border border-primary-200 dark:border-primary-500/30 bg-primary-50 dark:bg-primary-500/10 px-3 py-2 text-sm text-primary-800 dark:text-primary-200">
                    This fare is priced for <strong>{{ $this->describeRequiredPassengers() }}</strong> — the passengers below must match.
                </p>
            @endif

            {{ $this->form }}

            <div class="flex justify-between">
                <x-filament::button type="button" color="gray" wire:click="backToReview">Back</x-filament::button>
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="createHold" icon="heroicon-o-lock-closed">
                    Hold this flight
                </x-filament::button>
            </div>
        </form>
    @endif

    {{-- ── Step 3 · Payment ───────────────────────────────────────────── --}}
    @if($step === 3 && $this->booking)
        @php($b = $this->booking)
        @php($secondsLeft = $b->expires_at ? $b->expires_at->getTimestamp() - now()->getTimestamp() : null)
        <div class="mx-auto w-full max-w-xl space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm">
                <span class="text-gray-500 dark:text-gray-400">
                    Booking <span class="font-medium text-gray-950 dark:text-white">#{{ $b->id }}</span>
                    @php($first = $b->segments->first())
                    @php($last = $b->segments->last())
                    @if($first && $last)
                        · {{ $first->origin }} &rarr; {{ $last->destination }}
                    @endif
                </span>
                @if(! is_null($secondsLeft))
                    <div x-data="{ left: {{ $secondsLeft }} }" x-init="const t = setInterval(() => { if (--left <= 0) clearInterval(t) }, 1000)"
                         style="display:inline-flex;align-items:center;gap:0.35rem;border-radius:9999px;padding:0.2rem 0.65rem;font-size:0.75rem;font-weight:500;background:rgb(var(--warning-500) / 0.12);color:rgb(var(--warning-700));">
                        <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                        <span x-show="left > 0">Hold expires in <span x-text="Math.floor(left / 60) + 'm ' + Math.max(0, left % 60) + 's'"></span></span>
                        <span x-show="left <= 0" x-cloak style="color:rgb(var(--danger-600));">Hold expired — search again to rebook</span>
                    </div>
                @endif
            </div>

            @include('filament.pages.partials.payment-panel', [
                'amountLabel' => $b->currency.' '.number_format($b->total_price_cents / 100, 2),
            ])

            <p class="text-center text-xs text-gray-400">
                <a href="{{ $bookingUrl($b->id) }}" class="hover:text-gray-600 hover:underline dark:hover:text-gray-300">View booking details</a>
            </p>
        </div>
    @endif

    {{-- ── Step 4 · Done ──────────────────────────────────────────────── --}}
    @if($step === 4 && $this->booking)
        @php($b = $this->booking)
        <x-filament::section>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-success-500 text-white text-2xl">&check;</span>
                <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Payment received</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Booking #{{ $b->id }} — status <strong>{{ ucfirst(str_replace('_', ' ', $b->status)) }}</strong>
                    @if($b->pnr) · PNR <strong>{{ $b->pnr }}</strong> @endif
                </p>
                <div class="flex gap-3 pt-2">
                    <x-filament::button tag="a" color="gray"
                        href="{{ \App\Filament\Pages\FlightSearch::getUrl() }}">
                        New search
                    </x-filament::button>
                    <x-filament::button tag="a" href="{{ $bookingUrl($b->id) }}">
                        View booking
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
