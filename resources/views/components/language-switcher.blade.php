<x-filament::dropdown placement="bottom-end">
    <x-slot name="trigger">
        <button type="button" class="flex items-center gap-1.5 rounded-lg p-2 text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/10" aria-label="{{ __('Language') }}" title="{{ __('Language') }}">
            <x-filament::icon icon="heroicon-o-language" class="h-5 w-5" />
            <span class="hidden text-xs font-medium sm:inline">{{ strtoupper(auth()->user()?->locale ?? app()->getLocale()) }}</span>
        </button>
    </x-slot>

    <x-filament::dropdown.list>
        @foreach(['en' => 'English', 'it' => 'Italiano', 'bn' => 'বাংলা'] as $code => $name)
            <form method="POST" action="{{ route('locale.update') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ $code }}">
                <button type="submit" class="flex w-full items-center justify-between gap-5 px-3 py-2 text-start text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                    <span>{{ __($name) }}</span>
                    @if(($userLocale = auth()->user()?->locale ?? app()->getLocale()) === $code)
                        <x-filament::icon icon="heroicon-m-check" class="h-4 w-4 text-primary-600" />
                    @endif
                </button>
            </form>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
