<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ \App\Models\Setting::get('app_name', config('app.name', 'Flight Search')) }} — Flight Search</title>

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.brand-theme')
</head>
<body class="bg-[var(--bg)] text-[var(--fg)] antialiased min-h-screen">

    {{-- This view is embedded via an iframe by App\Filament\Pages\FlightSearch, --}}
    {{-- which already provides the app header/sidebar — no header here. --}}
    <main class="max-w-5xl mx-auto px-4 py-8">

        <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
            <div>
                <h1 class="text-lg font-semibold text-[var(--fg)]">Search flights</h1>
                <p class="text-sm text-[var(--muted)] mt-0.5">Compare live fares across airlines and routes.</p>
            </div>

            @if($flightApiEnabled)
                @php
                    // -1 is App\Models\SubscriptionPlan::UNLIMITED — see
                    // App\Services\Flights\SearchQuotaService::remaining().
                    $dayLabel = $quotaRemaining['day'] === -1 ? 'Unlimited today' : $quotaRemaining['day'].' '.Str::plural('search', $quotaRemaining['day']).' left today';
                    $monthLabel = $quotaRemaining['month'] === -1 ? 'unlimited this month' : $quotaRemaining['month'].' left this month';
                    $low = $quotaRemaining['day'] !== -1 && $quotaRemaining['day'] <= 3;
                @endphp
                <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium {{ $low ? 'border-amber-300 bg-amber-50 text-amber-700' : 'border-[var(--card-border)] bg-[var(--card)] text-[var(--muted)]' }}">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 0 0 0-1.5h-3.25V5Z" clip-rule="evenodd" /></svg>
                    {{ $dayLabel }} &middot; {{ $monthLabel }}
                </span>
            @endif
        </div>

        @if(session('status'))
            <div class="mb-4 flex items-start gap-2.5 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm px-4 py-3">
                <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="mb-4 flex items-start gap-2.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3">
                <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" /></svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @unless($flightApiEnabled)
            <div class="mb-4 flex items-start gap-2.5 rounded-lg bg-blue-50 border border-blue-200 text-blue-800 text-sm px-4 py-3">
                <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd" /></svg>
                <span><span class="font-medium">Preview mode.</span> No flight provider is connected yet — searches return sample data. An administrator can add one under <span class="font-medium">Flight Providers</span>.</span>
            </div>
        @endunless

        <div
            x-data="flightSearch()"
            x-init="init()"
            class="relative bg-[var(--card)] text-[var(--fg)] rounded-xl shadow-sm border border-[var(--card-border)] p-6"
        >
            <div class="flex items-center justify-between gap-3 mb-5">
                <div class="inline-flex rounded-lg bg-[var(--hover-bg)] p-1 text-sm font-medium">
                    <template x-for="opt in tripTypes" :key="opt.value">
                        <button
                            type="button"
                            @click="setTripType(opt.value)"
                            class="rounded-md px-3.5 py-1.5 transition-colors"
                            :class="tripType === opt.value ? 'bg-[var(--card)] text-[var(--brand)] shadow-sm' : 'text-[var(--muted)] hover:text-[var(--fg)]'"
                            x-text="opt.label"
                        ></button>
                    </template>
                </div>

                <button
                    type="button"
                    @click="resetForm()"
                    title="Reset search"
                    class="inline-flex items-center gap-1.5 rounded-md border border-[var(--card-border)] px-2.5 py-1.5 text-xs font-medium text-[var(--muted)] hover:bg-[var(--hover-bg)] transition-colors"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M15.312 5.312a5.5 5.5 0 1 0 1.302 5.633.75.75 0 0 1 1.415.49A7 7 0 1 1 16.5 4.11V2.75a.75.75 0 0 1 1.5 0v3a.75.75 0 0 1-.75.75h-3a.75.75 0 0 1 0-1.5h1.06l-.998-1.688Z" clip-rule="evenodd" />
                    </svg>
                    Reset
                </button>
            </div>

            <form method="POST" action="{{ route('flights.search.submit') }}" @submit="submitting = true">
                @csrf
                <input type="hidden" name="trip_type" x-model="tripType">
                <input type="hidden" name="adults" x-model="adults">
                <input type="hidden" name="children" x-model="children">
                <input type="hidden" name="infants" x-model="infants">
                <input type="hidden" name="flexible_dates" :value="flexible ? 1 : 0">
                <!-- cabin_class, source, airline, fare_type are each emitted once, inline, by their custom dropdown below -->

                <div class="space-y-3">
                    <template x-for="(leg, idx) in legs" :key="leg.id">
                        <div class="flex flex-col lg:flex-row gap-3 lg:items-stretch">
                            <div class="flex-1 grid grid-cols-1 sm:grid-cols-[1fr_auto_1fr] gap-0 border border-[var(--card-border)] rounded-md">
                                <div class="px-4 py-3.5 border-b-2 border-[var(--brand)] sm:border-b-0 sm:border-r border-[var(--card-border)] rounded-t-md sm:rounded-l-md sm:rounded-tr-none">
                                    <label :for="'input-' + leg.id + '-from'" class="flex items-center gap-1 text-xs text-[var(--muted)]">
                                        <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 2.75a.75.75 0 0 0-1.5 0v6.19L4.99 6.68a.75.75 0 1 0-.98 1.14l5.5 4.75c.28.24.7.24.98 0l5.5-4.75a.75.75 0 1 0-.98-1.14l-4.26 2.26V2.75Z" /><path d="M3.5 15.5a.75.75 0 0 1 .75-.75h11.5a.75.75 0 0 1 0 1.5H4.25a.75.75 0 0 1-.75-.75Z" /></svg>
                                        From
                                    </label>
                                    <div class="relative" x-on:click.outside="leg.fromOpen = false">
                                        <input
                                            type="text"
                                            :id="'input-' + leg.id + '-from'"
                                            :name="'legs[' + idx + '][from]'"
                                            x-model="leg.from"
                                            @input="onAirportInput(leg, 'from')"
                                            @keydown="airportKeydown(leg, 'from', $event)"
                                            @focus="if (leg.fromSuggestions.length) leg.fromOpen = true"
                                            placeholder="Enter airport or city"
                                            autocomplete="off"
                                            required
                                            role="combobox"
                                            aria-autocomplete="list"
                                            :aria-expanded="leg.fromOpen ? 'true' : 'false'"
                                            :aria-controls="'listbox-' + leg.id + '-from'"
                                            :aria-activedescendant="leg.fromActive >= 0 ? optionId(leg, 'from', leg.fromActive) : ''"
                                            class="w-full border-0 p-0 pr-5 mt-0.5 text-sm focus:ring-0 placeholder-[var(--muted)] bg-transparent"
                                        >
                                        <button
                                            type="button"
                                            x-show="leg.from"
                                            x-cloak
                                            @click="clearAirport(leg, 'from')"
                                            title="Clear"
                                            class="absolute right-0 top-1 text-[var(--muted)] hover:text-[var(--muted)]"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M10 8.94 13.72 5.22a.75.75 0 1 1 1.06 1.06L11.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06L10 11.06l-3.72 3.72a.75.75 0 0 1-1.06-1.06L8.94 10 5.22 6.28a.75.75 0 0 1 1.06-1.06L10 8.94Z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                        <div
                                            x-show="leg.fromOpen"
                                            x-cloak
                                            :id="'listbox-' + leg.id + '-from'"
                                            role="listbox"
                                            class="absolute z-30 top-full left-0 right-0 mt-1 min-w-[16rem] max-h-64 overflow-y-auto bg-[var(--card)] border border-[var(--card-border)] rounded-md shadow-lg text-left"
                                        >
                                            <div x-show="leg.fromLoading" class="px-3 py-3 text-sm text-[var(--muted)]">Searching…</div>
                                            <template x-if="!leg.fromLoading && leg.fromSearched && !leg.fromSuggestions.length">
                                                <div class="px-3 py-3 text-sm text-[var(--muted)]">No airports found.</div>
                                            </template>
                                            <template x-for="(place, i) in leg.fromSuggestions" :key="place.id ?? place.iata_code">
                                                <button
                                                    type="button"
                                                    :id="optionId(leg, 'from', i)"
                                                    role="option"
                                                    :aria-selected="leg.fromActive === i ? 'true' : 'false'"
                                                    @click="selectAirport(leg, 'from', place)"
                                                    @mousemove="leg.fromActive = i"
                                                    class="w-full text-left px-3 py-2 text-sm border-b border-[var(--card-border)] last:border-0"
                                                    :class="leg.fromActive === i ? 'bg-[var(--brand)]/10' : 'hover:bg-[var(--hover-bg)]'"
                                                >
                                                    <span class="font-medium text-[var(--fg)]" x-text="place.name"></span>
                                                    <span class="text-[var(--muted)] ml-1" x-text="place.iata_code ? '(' + place.iata_code + ')' : ''"></span>
                                                    <div class="text-xs text-[var(--muted)]" x-text="place.city_name"></div>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    @click="swap(idx)"
                                    title="Swap departure and arrival"
                                    aria-label="Swap departure and arrival airports"
                                    class="hidden sm:flex items-center justify-center w-10 shrink-0 text-[var(--brand)] hover:text-[var(--brand-dark)]"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M13.5 3a.75.75 0 0 1 .75.75v9.69l2.22-2.22a.75.75 0 1 1 1.06 1.06l-3.5 3.5a.75.75 0 0 1-1.06 0l-3.5-3.5a.75.75 0 1 1 1.06-1.06l2.22 2.22V3.75A.75.75 0 0 1 13.5 3ZM6.5 17a.75.75 0 0 1-.75-.75V6.56L3.53 8.78a.75.75 0 0 1-1.06-1.06l3.5-3.5a.75.75 0 0 1 1.06 0l3.5 3.5a.75.75 0 1 1-1.06 1.06L7.25 6.56v9.69A.75.75 0 0 1 6.5 17Z" />
                                    </svg>
                                </button>

                                <div class="px-4 py-3.5 rounded-b-md sm:rounded-r-md sm:rounded-bl-none">
                                    <label :for="'input-' + leg.id + '-to'" class="flex items-center gap-1 text-xs text-[var(--muted)]">
                                        <svg class="w-3 h-3 rotate-180" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 2.75a.75.75 0 0 0-1.5 0v6.19L4.99 6.68a.75.75 0 1 0-.98 1.14l5.5 4.75c.28.24.7.24.98 0l5.5-4.75a.75.75 0 1 0-.98-1.14l-4.26 2.26V2.75Z" /><path d="M3.5 15.5a.75.75 0 0 1 .75-.75h11.5a.75.75 0 0 1 0 1.5H4.25a.75.75 0 0 1-.75-.75Z" /></svg>
                                        To
                                    </label>
                                    <div class="relative" x-on:click.outside="leg.toOpen = false">
                                        <input
                                            type="text"
                                            :id="'input-' + leg.id + '-to'"
                                            :name="'legs[' + idx + '][to]'"
                                            x-model="leg.to"
                                            @input="onAirportInput(leg, 'to')"
                                            @keydown="airportKeydown(leg, 'to', $event)"
                                            @focus="if (leg.toSuggestions.length) leg.toOpen = true"
                                            placeholder="Enter airport or city"
                                            autocomplete="off"
                                            required
                                            role="combobox"
                                            aria-autocomplete="list"
                                            :aria-expanded="leg.toOpen ? 'true' : 'false'"
                                            :aria-controls="'listbox-' + leg.id + '-to'"
                                            :aria-activedescendant="leg.toActive >= 0 ? optionId(leg, 'to', leg.toActive) : ''"
                                            class="w-full border-0 p-0 pr-5 mt-0.5 text-sm focus:ring-0 placeholder-[var(--muted)] bg-transparent"
                                        >
                                        <button
                                            type="button"
                                            x-show="leg.to"
                                            x-cloak
                                            @click="clearAirport(leg, 'to')"
                                            title="Clear"
                                            class="absolute right-0 top-1 text-[var(--muted)] hover:text-[var(--muted)]"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M10 8.94 13.72 5.22a.75.75 0 1 1 1.06 1.06L11.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06L10 11.06l-3.72 3.72a.75.75 0 0 1-1.06-1.06L8.94 10 5.22 6.28a.75.75 0 0 1 1.06-1.06L10 8.94Z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                        <div
                                            x-show="leg.toOpen"
                                            x-cloak
                                            :id="'listbox-' + leg.id + '-to'"
                                            role="listbox"
                                            class="absolute z-30 top-full left-0 right-0 mt-1 min-w-[16rem] max-h-64 overflow-y-auto bg-[var(--card)] border border-[var(--card-border)] rounded-md shadow-lg text-left"
                                        >
                                            <div x-show="leg.toLoading" class="px-3 py-3 text-sm text-[var(--muted)]">Searching…</div>
                                            <template x-if="!leg.toLoading && leg.toSearched && !leg.toSuggestions.length">
                                                <div class="px-3 py-3 text-sm text-[var(--muted)]">No airports found.</div>
                                            </template>
                                            <template x-for="(place, i) in leg.toSuggestions" :key="place.id ?? place.iata_code">
                                                <button
                                                    type="button"
                                                    :id="optionId(leg, 'to', i)"
                                                    role="option"
                                                    :aria-selected="leg.toActive === i ? 'true' : 'false'"
                                                    @click="selectAirport(leg, 'to', place)"
                                                    @mousemove="leg.toActive = i"
                                                    class="w-full text-left px-3 py-2 text-sm border-b border-[var(--card-border)] last:border-0"
                                                    :class="leg.toActive === i ? 'bg-[var(--brand)]/10' : 'hover:bg-[var(--hover-bg)]'"
                                                >
                                                    <span class="font-medium text-[var(--fg)]" x-text="place.name"></span>
                                                    <span class="text-[var(--muted)] ml-1" x-text="place.iata_code ? '(' + place.iata_code + ')' : ''"></span>
                                                    <div class="text-xs text-[var(--muted)]" x-text="place.city_name"></div>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="lg:w-48 px-4 py-2 border border-[var(--card-border)] border-b-2 border-b-[var(--brand)] rounded-md flex items-center justify-between gap-2">
                                <div class="w-full">
                                    <label class="block text-xs text-[var(--muted)]">Flight Date</label>
                                    <input
                                        type="date"
                                        :name="'legs[' + idx + '][date]'"
                                        x-model="leg.date"
                                        :min="today"
                                        required
                                        class="w-full border-0 p-0 mt-0.5 text-sm focus:ring-0"
                                    >
                                </div>

                                <button
                                    type="button"
                                    x-show="tripType === 'multicity' && idx === legs.length - 1 && legs.length < 6"
                                    @click="addLeg()"
                                    title="Add another flight"
                                    class="w-7 h-7 shrink-0 flex items-center justify-center rounded-full bg-[var(--brand)] text-white hover:bg-[var(--brand-dark)]"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M10 4a.75.75 0 0 1 .75.75v4.5h4.5a.75.75 0 0 1 0 1.5h-4.5v4.5a.75.75 0 0 1-1.5 0v-4.5h-4.5a.75.75 0 0 1 0-1.5h4.5v-4.5A.75.75 0 0 1 10 4Z" />
                                    </svg>
                                </button>

                                <button
                                    type="button"
                                    x-show="tripType === 'multicity' && legs.length > 2 && idx >= 2"
                                    @click="removeLeg(idx)"
                                    title="Remove flight"
                                    class="w-7 h-7 shrink-0 flex items-center justify-center rounded-full bg-[var(--card-border)] text-[var(--muted)] hover:bg-[var(--hover-bg)]"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M5 10a.75.75 0 0 1 .75-.75h8.5a.75.75 0 0 1 0 1.5h-8.5A.75.75 0 0 1 5 10Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">

                    <div class="relative border border-[var(--card-border)] border-b-2 border-b-[var(--brand)] rounded-md px-3 py-2" x-on:click.outside="travelerOpen = false">
                        <label class="block text-xs text-[var(--muted)]">Traveler</label>
                        <button type="button" @click="travelerOpen = !travelerOpen" class="w-full text-left text-sm mt-0.5" x-text="travelerLabel()"></button>

                        <div
                            x-show="travelerOpen"
                            x-cloak
                            class="absolute z-10 top-full left-0 mt-2 w-64 bg-[var(--card)] border border-[var(--card-border)] rounded-md shadow-lg p-4 space-y-3"
                        >
                            <template x-for="p in [
                                { key: 'adults', label: 'Adults', hint: '12+ years', min: 1 },
                                { key: 'children', label: 'Children', hint: '2-11 years', min: 0 },
                                { key: 'infants', label: 'Infants', hint: 'Under 2 years', min: 0 },
                            ]" :key="p.key">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-sm text-[var(--fg)]" x-text="p.label"></div>
                                        <div class="text-xs text-[var(--muted)]" x-text="p.hint"></div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="decrement(p.key, p.min)" class="w-6 h-6 rounded-full border border-[var(--card-border)] text-[var(--muted)] hover:bg-[var(--hover-bg)]">&minus;</button>
                                        <span class="w-4 text-center text-sm" x-text="$data[p.key]"></span>
                                        <button type="button" @click="increment(p.key)" class="w-6 h-6 rounded-full border border-[var(--card-border)] text-[var(--muted)] hover:bg-[var(--hover-bg)]">+</button>
                                    </div>
                                </div>
                            </template>
                            <button type="button" @click="travelerOpen = false" class="w-full mt-2 text-sm bg-[var(--brand)] text-white rounded-md py-1.5 hover:bg-[var(--brand-dark)]">Done</button>
                        </div>
                    </div>

                    <template x-for="field in [
                        { key: 'cabinClass', label: 'Flight Class', options: cabinClassOptions, name: 'cabin_class' },
                        { key: 'source', label: 'Sources', options: sourceOptions, name: 'source' },
                    ]" :key="field.key">
                        <div class="relative border border-[var(--card-border)] border-b-2 border-b-[var(--brand)] rounded-md px-3 py-2" x-on:click.outside="dropdownOpen[field.key] = false">
                            <label :for="'dropdown-btn-' + field.key" class="block text-xs text-[var(--muted)]" x-text="field.label"></label>
                            <input type="hidden" :name="field.name" :value="$data[field.key]">
                            <button
                                type="button"
                                :id="'dropdown-btn-' + field.key"
                                @click="dropdownOpen[field.key] = !dropdownOpen[field.key]"
                                :aria-expanded="dropdownOpen[field.key] ? 'true' : 'false'"
                                aria-haspopup="listbox"
                                class="w-full flex items-center justify-between gap-2 text-sm mt-0.5 text-[var(--fg)]"
                            >
                                <span x-text="field.options.find(o => o.value === $data[field.key])?.label"></span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-[var(--muted)] shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            <div
                                x-show="dropdownOpen[field.key]"
                                x-cloak
                                role="listbox"
                                class="absolute z-30 top-full left-0 mt-1 w-full min-w-[10rem] max-h-64 overflow-y-auto bg-[var(--card)] border border-[var(--card-border)] rounded-md shadow-lg"
                            >
                                <template x-for="opt in filteredOptions(field)" :key="opt.value">
                                    <button
                                        type="button"
                                        role="option"
                                        :aria-selected="$data[field.key] === opt.value ? 'true' : 'false'"
                                        @click="$data[field.key] = opt.value; dropdownOpen[field.key] = false; dropdownFilter[field.key] = ''"
                                        class="w-full text-left px-3 py-2 text-sm"
                                        :class="$data[field.key] === opt.value ? 'bg-[var(--brand)]/10 text-[var(--brand)]' : 'text-[var(--fg)] hover:bg-[var(--hover-bg)]'"
                                        x-text="opt.label"
                                    ></button>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div class="border border-[var(--card-border)] rounded-md px-3 py-2 flex items-center gap-2">
                        <input id="flexible_dates_checkbox" type="checkbox" x-model="flexible" class="rounded border-[var(--card-border)] text-[var(--brand)] focus:ring-[var(--brand)]">
                        <label for="flexible_dates_checkbox" class="text-sm text-[var(--muted)]">Flexible &plusmn; 3 Days</label>
                    </div>

                    <template x-for="field in [
                        { key: 'airline', label: 'Airlines', options: airlineOptions, name: 'airline' },
                        { key: 'fareType', label: 'Type', options: fareTypeOptions, name: 'fare_type' },
                    ]" :key="field.key">
                        <div class="relative border border-[var(--card-border)] border-b-2 border-b-[var(--brand)] rounded-md px-3 py-2" x-on:click.outside="dropdownOpen[field.key] = false">
                            <label :for="'dropdown-btn-' + field.key" class="block text-xs text-[var(--muted)]" x-text="field.label"></label>
                            <input type="hidden" :name="field.name" :value="$data[field.key]">
                            <button
                                type="button"
                                :id="'dropdown-btn-' + field.key"
                                @click="dropdownOpen[field.key] = !dropdownOpen[field.key]"
                                :aria-expanded="dropdownOpen[field.key] ? 'true' : 'false'"
                                aria-haspopup="listbox"
                                class="w-full flex items-center justify-between gap-2 text-sm mt-0.5 text-[var(--fg)]"
                            >
                                <span x-text="field.options.find(o => o.value === $data[field.key])?.label"></span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-[var(--muted)] shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            <div
                                x-show="dropdownOpen[field.key]"
                                x-cloak
                                role="listbox"
                                class="absolute z-30 top-full left-0 mt-1 w-full min-w-[10rem] max-h-64 overflow-y-auto bg-[var(--card)] border border-[var(--card-border)] rounded-md shadow-lg"
                            >
                                <div x-show="field.key === 'airline'" x-cloak class="sticky top-0 bg-[var(--card)] p-2 border-b border-[var(--card-border)]">
                                    <input
                                        type="text"
                                        x-model="dropdownFilter[field.key]"
                                        placeholder="Search airline..."
                                        class="w-full text-sm px-2 py-1 rounded border border-[var(--card-border)] bg-transparent placeholder-[var(--muted)]"
                                    >
                                </div>
                                <template x-for="opt in filteredOptions(field)" :key="opt.value">
                                    <button
                                        type="button"
                                        role="option"
                                        :aria-selected="$data[field.key] === opt.value ? 'true' : 'false'"
                                        @click="$data[field.key] = opt.value; dropdownOpen[field.key] = false; dropdownFilter[field.key] = ''"
                                        class="w-full text-left px-3 py-2 text-sm"
                                        :class="$data[field.key] === opt.value ? 'bg-[var(--brand)]/10 text-[var(--brand)]' : 'text-[var(--fg)] hover:bg-[var(--hover-bg)]'"
                                        x-text="opt.label"
                                    ></button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="mt-6 flex justify-center">
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="inline-flex items-center justify-center gap-2 min-w-[13rem] bg-[var(--brand)] hover:bg-[var(--brand-dark)] disabled:opacity-70 disabled:cursor-wait text-white font-semibold rounded-md px-10 py-3 text-sm shadow-sm transition-colors"
                    >
                        <svg x-show="submitting" x-cloak class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span x-text="submitting ? 'Searching flights…' : 'Search flights'"></span>
                    </button>
                </div>
            </form>

            {{-- Full-page POST: this overlay covers the form until the results --}}
            {{-- page navigates in, so a slow provider call isn't a dead click. --}}
            <div
                x-show="submitting"
                x-cloak
                class="absolute inset-0 rounded-xl bg-[var(--card)]/70 backdrop-blur-[1px] flex flex-col items-center justify-center gap-3 text-sm text-[var(--muted)]"
            >
                <svg class="w-6 h-6 animate-spin text-[var(--brand)]" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Searching live fares — this can take a few seconds.
            </div>
        </div>
    </main>

    <script>
        function flightSearch() {
            const today = new Date().toISOString().slice(0, 10);

            const makeLeg = () => ({
                id: Math.random().toString(36).slice(2),
                from: '', to: '', date: today,
                fromSuggestions: [], toSuggestions: [],
                fromOpen: false, toOpen: false,
                fromLoading: false, toLoading: false,
                fromSearched: false, toSearched: false,
                fromActive: -1, toActive: -1,
                fromTimer: null, toTimer: null,
            });

            return {
                today,
                tripType: 'oneway',
                tripTypes: [
                    { value: 'oneway', label: 'One Way' },
                    { value: 'roundtrip', label: 'Round Trip' },
                    { value: 'multicity', label: 'Multi City' },
                ],
                legs: [makeLeg()],
                adults: 1,
                children: 0,
                infants: 0,
                cabinClass: 'economy',
                cabinClassOptions: [
                    { value: 'economy', label: 'Economy' },
                    { value: 'premium_economy', label: 'Premium Economy' },
                    { value: 'business', label: 'Business' },
                    { value: 'first', label: 'First' },
                ],
                source: 'all',
                sourceOptions: [
                    { value: 'all', label: 'All' },
                ],
                airline: '',
                airlineOptions: [{ value: '', label: 'Select' }, ...@json($airlines)],
                fareType: 'all',
                fareTypeOptions: [
                    { value: 'all', label: 'All Fares' },
                    { value: 'refundable', label: 'Refundable' },
                    { value: 'non_refundable', label: 'Non-Refundable' },
                ],
                dropdownOpen: { cabinClass: false, source: false, airline: false, fareType: false },
                dropdownFilter: { airline: '' },
                flexible: false,
                travelerOpen: false,
                submitting: false,

                init() {},

                filteredOptions(field) {
                    const query = (this.dropdownFilter[field.key] || '').trim().toLowerCase();

                    if (! query) {
                        return field.options;
                    }

                    return field.options.filter(opt => opt.label.toLowerCase().includes(query));
                },

                setTripType(value) {
                    this.tripType = value;

                    if (value === 'oneway') {
                        this.legs = [this.legs[0] ?? makeLeg()];
                    } else if (value === 'roundtrip') {
                        this.legs = [this.legs[0] ?? makeLeg(), this.legs[1] ?? makeLeg()];
                    } else if (value === 'multicity') {
                        while (this.legs.length < 2) {
                            this.legs.push(makeLeg());
                        }
                    }
                },

                addLeg() {
                    if (this.legs.length < 6) {
                        this.legs.push(makeLeg());
                    }
                },

                removeLeg(idx) {
                    this.legs.splice(idx, 1);
                },

                swap(idx) {
                    const leg = this.legs[idx];
                    [leg.from, leg.to] = [leg.to, leg.from];
                },

                onAirportInput(leg, field) {
                    clearTimeout(leg[field + 'Timer']);
                    const query = leg[field];
                    leg[field + 'Active'] = -1;

                    if (query.length < 2) {
                        leg[field + 'Suggestions'] = [];
                        leg[field + 'Open'] = false;
                        leg[field + 'Loading'] = false;
                        leg[field + 'Searched'] = false;
                        return;
                    }

                    leg[field + 'Loading'] = true;
                    leg[field + 'Open'] = true;

                    leg[field + 'Timer'] = setTimeout(async () => {
                        try {
                            const res = await fetch('{{ route('flights.airports') }}?query=' + encodeURIComponent(query), {
                                headers: { 'Accept': 'application/json' },
                            });
                            const json = await res.json();
                            leg[field + 'Suggestions'] = json.data ?? [];
                        } catch (e) {
                            leg[field + 'Suggestions'] = [];
                        } finally {
                            leg[field + 'Loading'] = false;
                            leg[field + 'Searched'] = true;
                            leg[field + 'Open'] = true;
                        }
                    }, 300);
                },

                airportKeydown(leg, field, event) {
                    const list = leg[field + 'Suggestions'];

                    if (event.key === 'Escape') {
                        leg[field + 'Open'] = false;
                        return;
                    }

                    if (!leg[field + 'Open'] || !list.length) {
                        return;
                    }

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        leg[field + 'Active'] = Math.min(leg[field + 'Active'] + 1, list.length - 1);
                        this.scrollActiveIntoView(leg, field);
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        leg[field + 'Active'] = Math.max(leg[field + 'Active'] - 1, 0);
                        this.scrollActiveIntoView(leg, field);
                    } else if (event.key === 'Enter' && leg[field + 'Active'] >= 0) {
                        event.preventDefault();
                        this.selectAirport(leg, field, list[leg[field + 'Active']]);
                    }
                },

                scrollActiveIntoView(leg, field) {
                    this.$nextTick(() => {
                        document.getElementById(this.optionId(leg, field, leg[field + 'Active']))
                            ?.scrollIntoView({ block: 'nearest' });
                    });
                },

                optionId(leg, field, i) {
                    return `opt-${leg.id}-${field}-${i}`;
                },

                selectAirport(leg, field, place) {
                    leg[field] = place.iata_code ? `${place.name} (${place.iata_code})` : place.name;
                    leg[field + 'Open'] = false;
                    leg[field + 'Active'] = -1;
                },

                clearAirport(leg, field) {
                    leg[field] = '';
                    leg[field + 'Suggestions'] = [];
                    leg[field + 'Open'] = false;
                    leg[field + 'Active'] = -1;
                    leg[field + 'Searched'] = false;
                    document.getElementById('input-' + leg.id + '-' + field)?.focus();
                },

                increment(key) {
                    this[key]++;
                },

                decrement(key, min) {
                    if (this[key] > min) {
                        this[key]--;
                    }
                },

                travelerLabel() {
                    const parts = [`${this.adults} Adult${this.adults > 1 ? 's' : ''}`];
                    if (this.children > 0) parts.push(`${this.children} Child${this.children > 1 ? 'ren' : ''}`);
                    if (this.infants > 0) parts.push(`${this.infants} Infant${this.infants > 1 ? 's' : ''}`);
                    return parts.join(', ');
                },

                resetForm() {
                    this.tripType = 'oneway';
                    this.legs = [makeLeg()];
                    this.adults = 1;
                    this.children = 0;
                    this.infants = 0;
                    this.cabinClass = 'economy';
                    this.source = 'all';
                    this.airline = '';
                    this.fareType = 'all';
                    this.dropdownOpen = { cabinClass: false, source: false, airline: false, fareType: false };
                    this.dropdownFilter = { airline: '' };
                    this.flexible = false;
                },
            };
        }
    </script>
</body>
</html>
