{{--
    Shared checkout step for BookFlight and ChangeBooking.

    Expects on the Livewire component:
      - $this->availableGateways   array<string,string>
      - $this->paymentGateway      ?string
      - $this->paymentClientData   array   (client_secret/publishable_key | order_id/approval_url)
      - wire methods: startPayment(gateway), capturePaypal(), refreshPaymentStatus()

    Optional:
      - $amountLabel    "USD 149.00"
      - $amountCaption  line under the total (default "Total to pay")

    Styling is a scoped <style> block rather than Tailwind utilities: this
    panel renders inside the Filament admin panel, which ships a fixed
    precompiled stylesheet (no JIT over these blades), so arbitrary
    utilities would silently no-op. Colours use Filament's own CSS custom
    properties (--primary-*, --danger-* …) so it follows the panel theme
    and dark mode.
--}}
@php
    $amountLabel = $amountLabel ?? null;
    $amountCaption = $amountCaption ?? 'Total to pay';

    $methodMeta = [
        'stripe' => ['label' => 'Credit or debit card', 'hint' => 'Visa, Mastercard, Amex — entered securely on Stripe', 'icon' => 'heroicon-o-credit-card'],
        'paypal' => ['label' => 'PayPal', 'hint' => 'Pay with your PayPal balance or a linked card', 'icon' => 'heroicon-o-banknotes'],
    ];
@endphp

@if(array_key_exists('stripe', $this->availableGateways))
    @assets
        <script src="https://js.stripe.com/v3/"></script>
    @endassets
@endif

@once
<style>
    .pcx { max-width: 34rem; margin-inline: auto; }
    .pcx-card {
        border: 1px solid rgb(var(--gray-950) / 0.08);
        border-radius: 1rem;
        background: #fff;
        overflow: hidden;
        box-shadow: 0 1px 2px rgb(var(--gray-950) / 0.04);
    }
    .dark .pcx-card { border-color: rgb(255 255 255 / 0.1); background: rgb(var(--gray-900)); }

    .pcx-total {
        display: flex; align-items: baseline; justify-content: space-between; gap: 1rem;
        padding: 1.1rem 1.4rem;
        border-bottom: 1px solid rgb(var(--gray-950) / 0.06);
    }
    .dark .pcx-total { border-bottom-color: rgb(255 255 255 / 0.08); }
    .pcx-total__label { font-size: 0.875rem; font-weight: 500; color: rgb(var(--gray-500)); }
    .pcx-total__amt { font-size: 1.5rem; font-weight: 700; font-variant-numeric: tabular-nums; color: rgb(var(--gray-950)); }
    .dark .pcx-total__amt { color: #fff; }

    .pcx-body { padding: 1.35rem 1.4rem; }
    .pcx-heading { font-size: 0.875rem; font-weight: 600; color: rgb(var(--gray-700)); margin-bottom: 0.7rem; }
    .dark .pcx-heading { color: rgb(var(--gray-300)); }

    .pcx-tile {
        display: flex; align-items: center; gap: 0.9rem; width: 100%; text-align: start;
        padding: 0.85rem 1rem; border-radius: 0.75rem;
        border: 1px solid rgb(var(--gray-950) / 0.1);
        background: rgb(var(--gray-50));
        transition: border-color .15s, background-color .15s;
        cursor: pointer;
    }
    .pcx-tile + .pcx-tile { margin-top: 0.6rem; }
    .dark .pcx-tile { border-color: rgb(255 255 255 / 0.1); background: rgb(255 255 255 / 0.04); }
    .pcx-tile:hover { border-color: rgb(var(--primary-500)); background: rgb(var(--primary-500) / 0.06); }
    .pcx-tile:disabled { opacity: .6; cursor: wait; }
    .pcx-tile__ic {
        display: flex; align-items: center; justify-content: center;
        width: 2.5rem; height: 2.5rem; border-radius: 0.6rem; flex-shrink: 0;
        background: rgb(var(--gray-950) / 0.06); color: rgb(var(--gray-600));
    }
    .dark .pcx-tile__ic { background: rgb(255 255 255 / 0.08); color: rgb(var(--gray-300)); }
    .pcx-tile:hover .pcx-tile__ic { background: rgb(var(--primary-500) / 0.15); color: rgb(var(--primary-600)); }
    .pcx-tile__t { display: block; font-size: 0.875rem; font-weight: 600; color: rgb(var(--gray-950)); }
    .dark .pcx-tile__t { color: #fff; }
    .pcx-tile__h { display: block; font-size: 0.75rem; color: rgb(var(--gray-500)); margin-top: 0.1rem; }
    .pcx-tile__chev { margin-inline-start: auto; color: rgb(var(--gray-400)); flex-shrink: 0; }

    .pcx-frame { border: 1px solid rgb(var(--gray-950) / 0.1); border-radius: 0.75rem; padding: 1rem; }
    .dark .pcx-frame { border-color: rgb(255 255 255 / 0.1); }

    .pcx-pay {
        display: flex; align-items: center; justify-content: center; gap: 0.5rem; width: 100%;
        margin-top: 1rem; padding: 0.8rem 1rem; border-radius: 0.75rem;
        background: rgb(var(--primary-600)); color: #fff;
        font-size: 0.875rem; font-weight: 600; cursor: pointer;
        transition: background-color .15s;
    }
    .pcx-pay:hover { background: rgb(var(--primary-500)); }
    .pcx-pay:disabled { opacity: .55; cursor: not-allowed; }

    .pcx-paypal {
        display: flex; align-items: center; justify-content: center; gap: 0.5rem; width: 100%;
        margin-top: 1rem; padding: 0.8rem 1rem; border-radius: 0.75rem;
        background: #ffc439; color: #001c64; font-size: 0.875rem; font-weight: 700;
        transition: filter .15s;
    }
    .pcx-paypal:hover { filter: brightness(0.96); }
    .pcx-paypal-confirm {
        display: flex; align-items: center; justify-content: center; gap: 0.5rem; width: 100%;
        margin-top: 0.55rem; padding: 0.8rem 1rem; border-radius: 0.75rem;
        border: 1px solid rgb(var(--primary-600)); color: rgb(var(--primary-700));
        font-size: 0.875rem; font-weight: 600; cursor: pointer; background: transparent;
    }
    .pcx-paypal-confirm:hover { background: rgb(var(--primary-500) / 0.08); }
    .dark .pcx-paypal-confirm { color: rgb(var(--primary-400)); }

    .pcx-ic-tile {
        display: flex; align-items: center; justify-content: center;
        width: 2.5rem; height: 2.5rem; border-radius: 0.6rem; flex-shrink: 0;
        background: #001c64; color: #fff;
    }

    .pcx-foot {
        display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;
        margin-top: 1rem; padding-top: 0.75rem;
        border-top: 1px solid rgb(var(--gray-950) / 0.06);
        font-size: 0.75rem; color: rgb(var(--gray-400));
    }
    .dark .pcx-foot { border-top-color: rgb(255 255 255 / 0.08); }
    .pcx-link { font-size: 0.75rem; font-weight: 500; color: rgb(var(--primary-600)); cursor: pointer; background: none; border: 0; }
    .pcx-link:hover { text-decoration: underline; }
    .dark .pcx-link { color: rgb(var(--primary-400)); }

    .pcx-msg { margin-top: 0.75rem; font-size: 0.875rem; }
    .pcx-msg--error { color: rgb(var(--danger-600)); }
    .pcx-msg--success { color: rgb(var(--success-600)); }
    .pcx-msg--processing { color: rgb(var(--gray-500)); }
    .dark .pcx-msg--error { color: rgb(var(--danger-400)); }
    .dark .pcx-msg--success { color: rgb(var(--success-400)); }

    .pcx-note { margin-top: 0.5rem; font-size: 0.75rem; color: rgb(var(--gray-400)); }
    .pcx-code { border-radius: 0.25rem; background: rgb(var(--gray-950) / 0.06); padding: 0.05rem 0.3rem; }
    .dark .pcx-code { background: rgb(255 255 255 / 0.1); }

    .pcx-warn {
        border-radius: 0.75rem; padding: 1rem; font-size: 0.875rem;
        background: rgb(var(--danger-500) / 0.1); color: rgb(var(--danger-700));
    }
    .dark .pcx-warn { color: rgb(var(--danger-400)); }

    .pcx-spin { width: 1rem; height: 1rem; animation: pcx-spin 0.7s linear infinite; }
    @keyframes pcx-spin { to { transform: rotate(360deg); } }
</style>
@endonce

<div class="pcx">
    <div class="pcx-card">

        @if($amountLabel)
            <div class="pcx-total">
                <span class="pcx-total__label">{{ $amountCaption }}</span>
                <span class="pcx-total__amt">{{ $amountLabel }}</span>
            </div>
        @endif

        <div class="pcx-body">

            {{-- ── No gateway configured ─────────────────────────────── --}}
            @if(empty($this->availableGateways))
                <div class="pcx-warn">
                    No payment method is available yet. An administrator needs to add Stripe or PayPal
                    credentials under <strong>System&nbsp;Settings&nbsp;→&nbsp;Payments</strong>.
                </div>

            {{-- ── Choose a method ───────────────────────────────────── --}}
            @elseif(! $this->paymentGateway)
                <p class="pcx-heading">Choose a payment method</p>

                @foreach($this->availableGateways as $code => $label)
                    @php($meta = $methodMeta[$code] ?? ['label' => $label, 'hint' => '', 'icon' => 'heroicon-o-credit-card'])
                    <button type="button" class="pcx-tile"
                            wire:click="startPayment('{{ $code }}')"
                            wire:loading.attr="disabled" wire:target="startPayment">
                        <span class="pcx-tile__ic">
                            <x-filament::icon :icon="$meta['icon']" class="h-5 w-5" />
                        </span>
                        <span>
                            <span class="pcx-tile__t">{{ $meta['label'] }}</span>
                            @if($meta['hint'])<span class="pcx-tile__h">{{ $meta['hint'] }}</span>@endif
                        </span>
                        <x-filament::icon icon="heroicon-o-chevron-right" class="h-5 w-5 pcx-tile__chev" />
                    </button>
                @endforeach

                <p class="pcx-note" style="display:flex;align-items:center;gap:0.35rem;margin-top:0.9rem;">
                    <x-filament::icon icon="heroicon-o-lock-closed" class="h-4 w-4" />
                    Processed by Stripe and PayPal. Card details never reach our servers.
                </p>
                <p class="pcx-note" wire:loading wire:target="startPayment">Setting up secure checkout…</p>

            {{-- ── Stripe Payment Element ────────────────────────────── --}}
            @elseif($this->paymentGateway === 'stripe' && ! empty($this->paymentClientData['client_secret']))
                <div
                    wire:ignore
                    x-data="{
                        stripe: null, elements: null, ready: false, busy: false, state: 'idle', message: '',
                        mount() {
                            if (typeof Stripe === 'undefined') {
                                this.state = 'error';
                                this.message = 'Could not load the secure card form. Check your connection and reload.';
                                return;
                            }
                            this.stripe = Stripe(@js($this->paymentClientData['publishable_key']));
                            this.elements = this.stripe.elements({
                                clientSecret: @js($this->paymentClientData['client_secret']),
                                appearance: { theme: document.documentElement.classList.contains('dark') ? 'night' : 'stripe', variables: { borderRadius: '8px' } },
                            });
                            const el = this.elements.create('payment', { layout: 'tabs' });
                            el.on('ready', () => this.ready = true);
                            el.mount('#stripe-payment-element');
                        },
                        async pay() {
                            if (! this.stripe || this.busy) return;
                            this.busy = true; this.state = 'processing'; this.message = 'Confirming your payment…';
                            const { error, paymentIntent } = await this.stripe.confirmPayment({ elements: this.elements, redirect: 'if_required' });
                            if (error) {
                                this.state = 'error'; this.message = error.message || 'Your payment could not be completed.'; this.busy = false; return;
                            }
                            this.state = 'success';
                            this.message = 'Payment ' + (paymentIntent?.status ?? 'submitted') + ' — finalising your booking…';
                            this.$wire.call('refreshPaymentStatus');
                        },
                    }"
                    x-init="mount()"
                >
                    <p class="pcx-heading">Card details</p>
                    <div class="pcx-frame">
                        <div id="stripe-payment-element"></div>
                        <p class="pcx-note" x-show="! ready" style="text-align:center;padding:1rem 0;">Loading secure card form…</p>
                    </div>

                    <p class="pcx-msg" x-show="message"
                       :class="{ 'pcx-msg--error': state === 'error', 'pcx-msg--success': state === 'success', 'pcx-msg--processing': state === 'processing' }"
                       x-text="message"></p>

                    <button type="button" class="pcx-pay" x-on:click="pay()" x-bind:disabled="busy || ! ready">
                        <x-filament::icon icon="heroicon-o-lock-closed" class="h-4 w-4" x-show="! busy" />
                        <svg class="pcx-spin" x-show="busy" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity="0.25" />
                            <path fill="currentColor" opacity="0.75" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z" />
                        </svg>
                        <span x-text="busy ? 'Processing…' : @js($amountLabel ? 'Pay '.$amountLabel : 'Pay now')"></span>
                    </button>
                </div>

                <div class="pcx-foot">
                    <span>Confirmation is automatic. Payment taken but not showing?</span>
                    <button type="button" class="pcx-link" wire:click="refreshPaymentStatus"
                            wire:loading.attr="disabled" wire:target="refreshPaymentStatus">Refresh status</button>
                </div>

                @if(! app()->isProduction())
                    <p class="pcx-note">
                        Test card <span class="pcx-code">4242&nbsp;4242&nbsp;4242&nbsp;4242</span>, any future expiry, any CVC.
                    </p>
                @endif

            {{-- ── PayPal ────────────────────────────────────────────── --}}
            @elseif($this->paymentGateway === 'paypal' && ! empty($this->paymentClientData['approval_url']))
                <div class="pcx-frame">
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <span class="pcx-ic-tile"><x-filament::icon icon="heroicon-o-banknotes" class="h-5 w-5" /></span>
                        <span>
                            <span class="pcx-tile__t">Pay with PayPal</span>
                            <span class="pcx-tile__h">Approve on PayPal, then confirm back here.</span>
                        </span>
                    </div>

                    <a class="pcx-paypal" href="{{ $this->paymentClientData['approval_url'] }}" target="_blank" rel="noopener">
                        <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-4 w-4" />
                        Continue to PayPal
                    </a>

                    <button type="button" class="pcx-paypal-confirm" wire:click="capturePaypal"
                            wire:loading.attr="disabled" wire:target="capturePaypal">
                        <span wire:loading.remove wire:target="capturePaypal">I’ve approved — confirm payment</span>
                        <span wire:loading wire:target="capturePaypal">Confirming…</span>
                    </button>
                </div>

            {{-- ── Intent started, no client data (fake gateway / edge) ── --}}
            @else
                <div class="pcx-frame">
                    <p style="font-size:0.875rem;color:rgb(var(--gray-600));">
                        Payment started with <strong>{{ ucfirst((string) $this->paymentGateway) }}</strong>. Waiting for confirmation.
                    </p>
                    <button type="button" class="pcx-link" style="margin-top:0.6rem;" wire:click="refreshPaymentStatus"
                            wire:loading.attr="disabled" wire:target="refreshPaymentStatus">Refresh status</button>
                </div>
            @endif
        </div>
    </div>
</div>
