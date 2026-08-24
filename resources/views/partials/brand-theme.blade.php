@php
    $palette = \App\Support\BrandColor::palette();
@endphp
<style>
    :root {
        color-scheme: {{ \App\Support\BrandColor::colorScheme() }};
        --brand: {{ \App\Support\BrandColor::base() }};
        --brand-dark: {{ \App\Support\BrandColor::dark() }};
        --bg: {{ $palette['bg'] }};
        --fg: {{ $palette['fg'] }};
        --card: {{ $palette['card'] }};
        --card-border: {{ $palette['border'] }};
        --muted: {{ $palette['muted'] }};
        --hover-bg: {{ $palette['hover'] }};
    }
    @if(\App\Support\BrandColor::panelMode() === 'system')
        @php $dark = \App\Support\BrandColor::darkPalette(); @endphp
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: {{ $dark['bg'] }};
                --fg: {{ $dark['fg'] }};
                --card: {{ $dark['card'] }};
                --card-border: {{ $dark['border'] }};
                --muted: {{ $dark['muted'] }};
                --hover-bg: {{ $dark['hover'] }};
            }
        }
    @endif

    /*
     * The browser's default focus outline (a plain white/black box) ignores
     * our theme entirely and looks broken against a dark page — visible as
     * a harsh white rectangle around whatever's focused. Replace it once,
     * globally, with a themed ring, shown only for keyboard focus so a
     * mouse click doesn't draw a ring around every button clicked.
     */
    input, select, textarea, button, a, [role="combobox"], [role="option"] {
        outline: none;
    }
    input:focus-visible,
    select:focus-visible,
    textarea:focus-visible,
    button:focus-visible,
    a:focus-visible,
    [role="combobox"]:focus-visible,
    [role="option"]:focus-visible {
        outline: 2px solid var(--brand);
        outline-offset: 2px;
        border-radius: 2px;
    }
</style>
