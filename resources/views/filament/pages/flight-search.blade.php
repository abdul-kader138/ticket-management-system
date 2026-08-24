<x-filament-panels::page>
    <iframe
        src="{{ route('flights.embed') }}"
        title="Search Flights"
        class="w-full rounded-xl border border-gray-200 dark:border-white/10"
        style="height: calc(100vh - 12rem); min-height: 640px;"
    ></iframe>
</x-filament-panels::page>
