<x-filament-panels::page>
    <style>
        /* Native select menus do not consistently inherit Filament's dark theme. */
        .reports-report-select {
            background-color: #ffffff;
            color: #111827;
            color-scheme: light;
        }

        .reports-report-select option {
            background-color: #ffffff;
            color: #111827;
        }

        .dark .reports-report-select {
            background-color: #18181b;
            color: #f4f4f5;
            color-scheme: dark;
        }

        .dark .reports-report-select option {
            background-color: #18181b;
            color: #f4f4f5;
        }

        .reports-date-input {
            background-color: #ffffff;
            color: #111827;
            color-scheme: light;
        }

        .dark .reports-date-input {
            background-color: #18181b;
            color: #f4f4f5;
            color-scheme: dark;
        }
    </style>

    <div class="space-y-6">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="fi-fo-field-wrp-label block text-sm font-medium">{{ __('Report') }}</label>
                <select wire:model.live="reportType" class="fi-select-input reports-report-select mt-1 block rounded-lg border-gray-300">
                    <option value="overview">{{ __('Business overview') }}</option>
                    <option value="bookings">{{ __('Bookings') }}</option>
                    <option value="payments">{{ __('Payments') }}</option>
                    <option value="fulfilment">{{ __('Ticket fulfilment') }}</option>
                </select>
            </div>
            <div>
                <label class="fi-fo-field-wrp-label block text-sm font-medium">{{ __('From') }}</label>
                <input type="text" inputmode="numeric" maxlength="10" placeholder="dd/mm/yyyy" wire:model.live.debounce.300ms="dateFrom" class="fi-input reports-date-input mt-1 rounded-lg border-gray-300">
            </div>
            <div>
                <label class="fi-fo-field-wrp-label block text-sm font-medium">{{ __('To') }}</label>
                <input type="text" inputmode="numeric" maxlength="10" placeholder="dd/mm/yyyy" wire:model.live.debounce.300ms="dateTo" class="fi-input reports-date-input mt-1 rounded-lg border-gray-300">
            </div>
        </div>

        @php($summary = $this->summary())
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach([
                [__('Bookings'), number_format($summary['bookings']), 'heroicon-o-ticket'],
                [__('Confirmed'), number_format($summary['confirmed']), 'heroicon-o-check-circle'],
                [__('Net revenue'), $summary['currency'].' '.number_format($summary['net'] / 100, 2), 'heroicon-o-banknotes'],
                    [auth()->user()->hasRole('super_admin') || auth()->user()->getAllPermissions()->isNotEmpty() ? __('Customers') : __('Account'), number_format($summary['customers']), 'heroicon-o-users'],
            ] as [$label, $value, $icon])
                <x-filament::section>
                    <div class="flex items-center gap-3">
                        <x-filament::icon :icon="$icon" class="h-6 w-6 text-primary-600" />
                        <div><div class="text-sm text-gray-500">{{ $label }}</div><div class="text-xl font-bold">{{ $value }}</div></div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section :heading="$this->reportTitle()" :description="__('Showing data from :from through :to', ['from' => $this->period()['from']->toDateString(), 'to' => $this->period()['to']->toDateString()])">
            @if($this->reportType === 'overview')
                <div class="grid gap-4 md:grid-cols-3">
                    <div><span class="text-sm text-gray-500">{{ __('Payments processed') }}</span><p class="text-lg font-semibold">{{ number_format($summary['payments']) }}</p></div>
                    <div><span class="text-sm text-gray-500">{{ __('Gross revenue') }}</span><p class="text-lg font-semibold">{{ $summary['currency'] }} {{ number_format($summary['gross'] / 100, 2) }}</p></div>
                    <div><span class="text-sm text-gray-500">{{ __('Refunds') }}</span><p class="text-lg font-semibold">{{ $summary['currency'] }} {{ number_format($summary['refunds'] / 100, 2) }}</p></div>
                </div>
            @else
                @php($rows = $this->rows())
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead><tr class="border-b text-gray-500">
                            @foreach(array_keys($rows[0] ?? ['No records' => '']) as $heading)<th class="px-3 py-2 font-medium">{{ __(ucfirst(str_replace('_', ' ', $heading))) }}</th>@endforeach
                        </tr></thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr class="border-b last:border-0"><td colspan="{{ count($row) }}" class="hidden"></td>
                                    @foreach($row as $value)<td class="px-3 py-2">{{ $value }}</td>@endforeach
                                </tr>
                            @empty
                                <tr><td class="px-3 py-6 text-center text-gray-500" colspan="6">{{ __('No records found for this period.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
