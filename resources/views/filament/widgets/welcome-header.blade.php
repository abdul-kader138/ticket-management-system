<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center gap-4">
            <img
                src="{{ filament()->getUserAvatarUrl($this->getUser()) }}"
                alt="{{ $this->getUser()?->name }}"
                class="h-12 w-12 rounded-full object-cover"
            />
            <div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                    {{ $this->getGreeting() }}, {{ $this->getUser()?->name }}
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @foreach ($this->getUser()?->roles->pluck('name') ?? [] as $role)
                        <span class="fi-badge inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400">
                            {{ str($role)->replace('_', ' ')->title() }}
                        </span>
                    @endforeach
                </p>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
