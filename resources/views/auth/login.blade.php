<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In — {{ \App\Models\Setting::get('app_name', config('app.name', 'Flight Search')) }}</title>

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.brand-theme')
</head>
<body class="bg-[var(--bg)] text-[var(--fg)] antialiased min-h-screen flex items-center justify-center px-4">

    <div class="w-full max-w-sm">
        <div class="text-center mb-6">
            <h1 class="text-lg font-semibold text-[var(--fg)]">
                {{ \App\Models\Setting::get('app_name', config('app.name', 'Flight Search')) }}
            </h1>
        </div>

        <div class="bg-[var(--card)] rounded-xl shadow-sm border border-[var(--card-border)] p-6">
            <h2 class="text-base font-semibold text-[var(--fg)] mb-1">Sign in</h2>
            <p class="text-sm text-[var(--muted)] mb-5">Sign in to search and book flights.</p>

            @if ($errors->any())
                <div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="email" class="block text-xs font-medium text-[var(--muted)] mb-1">Email</label>
                    <input
                        id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                        class="w-full rounded-md border border-[var(--card-border)] px-3 py-2 text-sm focus:ring-2 focus:ring-[var(--brand)] focus:border-[var(--brand)]"
                    >
                </div>

                <div>
                    <label for="password" class="block text-xs font-medium text-[var(--muted)] mb-1">Password</label>
                    <input
                        id="password" type="password" name="password" required
                        class="w-full rounded-md border border-[var(--card-border)] px-3 py-2 text-sm focus:ring-2 focus:ring-[var(--brand)] focus:border-[var(--brand)]"
                    >
                </div>

                <div class="flex items-center">
                    <input id="remember" type="checkbox" name="remember" class="rounded border-[var(--card-border)] text-[var(--brand)] focus:ring-[var(--brand)]">
                    <label for="remember" class="ml-2 text-sm text-[var(--muted)]">Remember me</label>
                </div>

                <button
                    type="submit"
                    class="w-full bg-[var(--brand)] hover:bg-[var(--brand-dark)] text-white font-semibold rounded-md px-4 py-2.5 text-sm transition-colors"
                >
                    Sign In
                </button>
            </form>

            <p class="mt-5 text-sm text-[var(--muted)] text-center">
                Don't have an account?
                <a href="{{ route('register') }}" class="text-[var(--brand)] font-medium hover:underline">Create one</a>
            </p>
        </div>

        <p class="mt-4 text-center text-sm">
            <a href="{{ url('/') }}" class="text-[var(--muted)] hover:text-[var(--fg)]">&larr; Back to home</a>
        </p>
    </div>

</body>
</html>
