<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Store these somewhere safe. Each code can be used once, in place of a six-digit authentication code, if you lose access to your authenticator app.
    </p>

    <div class="grid grid-cols-2 gap-2 rounded-lg bg-gray-50 p-4 font-mono text-sm dark:bg-gray-800">
        @forelse ($codes as $code)
            <span>{{ $code }}</span>
        @empty
            <span class="col-span-2 text-gray-400">No recovery codes available.</span>
        @endforelse
    </div>
</div>
