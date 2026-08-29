<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HandlesPaymentStep;
use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Bookings\BookingChangeService;
use App\Services\Bookings\BookingException;
use App\Services\Flights\DTO\SearchCriteria;
use App\Services\Flights\DuffelApiException;
use App\Services\Flights\FlightProviderManager;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

/**
 * Post-purchase servicing for a confirmed booking — the "change" half of
 * Phase 6. Reached from the BookingResource "Change" action with
 * ?booking=ID. Two-step by design, matching BookingChangeService: quote
 * the available change offers first, then apply the one the operator picks
 * and collect any fare difference through the shared payment step.
 *
 * Note: DuffelClient::confirmChangeOffer() is gated behind
 * DUFFEL_ORDER_CHANGE_CONFIRMATION_VERIFIED (see config/flights.php); until
 * that's set, applying a change surfaces that guard as an error here.
 */
class ChangeBooking extends Page implements HasForms
{
    use HandlesPaymentStep;
    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'change-booking';

    protected static ?string $title = 'Change Booking';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static string $view = 'filament.pages.change-booking';

    /** 1 = new itinerary, 2 = pick change offer, 3 = pay difference, 4 = done. */
    public int $step = 1;

    public ?int $bookingId = null;

    /** @var array<int, array<string, mixed>> Raw change offers from the provider. */
    public array $changeOffers = [];

    public ?string $selectedChangeOfferId = null;

    public ?int $differenceCents = null;

    /** New-itinerary form — statePath('data'). */
    public ?array $data = [];

    public function mount(): void
    {
        $this->bookingId = (int) request()->query('booking');

        $booking = Booking::with('segments')->find($this->bookingId);

        abort_unless($booking !== null, 404);
        abort_unless(
            auth()->user()->can('view', $booking) || auth()->id() === $booking->user_id,
            403,
        );

        if (! in_array($booking->status, [Booking::STATUS_CONFIRMED, Booking::STATUS_CHANGED], true)) {
            Notification::make()->danger()->title('Only a confirmed booking can be changed.')->send();
            $this->redirect(BookingResource::getUrl('view', ['record' => $booking->id]));

            return;
        }

        $first = $booking->segments->first();
        $last = $booking->segments->last();

        $this->form->fill([
            'adults' => max(1, $booking->passengers()->where('type', 'adult')->count()),
            'legs' => [[
                'from' => $first?->origin ?? '',
                'to' => $last?->destination ?? '',
                'date' => $first?->departs_at?->toDateString(),
            ]],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                TextInput::make('adults')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(9)
                    ->required()
                    ->default(1),

                Repeater::make('legs')
                    ->label('New itinerary')
                    ->schema([
                        Select::make('from')
                            ->label('From')
                            ->required()
                            ->searchable()
                            ->searchPrompt('Type a city or airport')
                            ->getSearchResultsUsing(fn (string $search) => $this->airportOptions($search))
                            ->getOptionLabelUsing(fn ($value) => $value),
                        Select::make('to')
                            ->label('To')
                            ->required()
                            ->searchable()
                            ->searchPrompt('Type a city or airport')
                            ->getSearchResultsUsing(fn (string $search) => $this->airportOptions($search))
                            ->getOptionLabelUsing(fn ($value) => $value),
                        DatePicker::make('date')
                            ->label('Departure date')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->format('Y-m-d')
                            ->placeholder('dd/mm/yyyy')
                            ->minDate(now()->startOfDay()),
                    ])
                    ->columns(3)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Add leg'),
            ]);
    }

    /**
     * Airport lookup for the itinerary picker — same provider places API
     * the main search form's autocomplete uses (routes/web.php
     * flights.airports), keyed by IATA code so SearchCriteria reads it
     * straight through.
     *
     * @return array<string, string>
     */
    protected function airportOptions(string $search): array
    {
        if (mb_strlen(trim($search)) < 2) {
            return [];
        }

        return collect(app(FlightProviderManager::class)->suggestPlaces(trim($search)))
            ->filter(fn ($place) => filled($place['iata_code'] ?? null))
            ->mapWithKeys(function ($place) {
                $city = $place['city_name'] ?? $place['name'] ?? $place['iata_code'];
                $name = $place['name'] ?? null;
                $label = "{$city} ({$place['iata_code']})".($name && $name !== $city ? " — {$name}" : '');

                return [$place['iata_code'] => $label];
            })
            ->all();
    }

    /**
     * #[Computed] so repeated $this->booking reads collapse to a single
     * query per request. `segments` is eager-loaded for the summary panel.
     */
    #[Computed]
    public function booking(): ?Booking
    {
        return $this->bookingId ? Booking::with('segments')->find($this->bookingId) : null;
    }

    public function searchChangeOffers(): void
    {
        $state = $this->form->getState();

        $slices = [];

        foreach ($state['legs'] as $index => $leg) {
            $origin = SearchCriteria::extractIataCode((string) $leg['from']);
            $destination = SearchCriteria::extractIataCode((string) $leg['to']);

            if (! $origin || ! $destination) {
                Notification::make()->danger()
                    ->title('Leg '.($index + 1).': use a 3-letter airport code for both fields.')
                    ->send();

                return;
            }

            $slices[] = ['origin' => $origin, 'destination' => $destination, 'departure_date' => $leg['date']];
        }

        $criteria = new SearchCriteria(slices: $slices, adults: (int) $state['adults']);

        try {
            $offers = app(BookingChangeService::class)->searchOffers($this->booking, $criteria)->toArray();
        } catch (BookingException|DuffelApiException $e) {
            Notification::make()->danger()->title('Could not fetch change offers')->body($e->getMessage())->send();

            return;
        }

        if ($offers === []) {
            Notification::make()->warning()->title('No change offers for that itinerary.')->send();

            return;
        }

        $this->changeOffers = $offers;
        $this->step = 2;
    }

    public function backToItinerary(): void
    {
        $this->step = 1;
        $this->changeOffers = [];
        $this->selectedChangeOfferId = null;
    }

    public function applyChange(string $changeOfferId): void
    {
        $booking = $this->booking;

        if (! $booking) {
            return;
        }

        $gateway = array_key_first($this->availableGateways) ?? 'stripe';

        try {
            $result = app(BookingChangeService::class)->applyChange($booking, $changeOfferId, auth()->user(), $gateway);
        } catch (BookingException|DuffelApiException $e) {
            Notification::make()->danger()->title('Could not apply this change')->body($e->getMessage())->send();

            return;
        }

        $this->selectedChangeOfferId = $changeOfferId;

        /** @var Payment|null $payment */
        $payment = $result['payment'];

        if (! $payment) {
            $this->step = 4;
            Notification::make()->success()->title('Booking changed')->body('No fare difference to collect.')->send();

            return;
        }

        $this->differenceCents = $payment->amount_cents;
        $this->rememberGatewayIntent($payment->gateway, $payment, $result['client_data']);
        $this->step = 3;

        Notification::make()->success()
            ->title('Change applied — fare difference due')
            ->body('Collect '.$booking->currency.' '.number_format($payment->amount_cents / 100, 2).' to finish.')
            ->send();
    }

    protected function currentPayment(): ?Payment
    {
        return $this->paymentId ? Payment::find($this->paymentId) : null;
    }

    protected function onPaymentSettled(Payment $payment): void
    {
        $this->step = 4;

        Notification::make()->success()
            ->title('Fare difference paid')
            ->body("Booking #{$this->bookingId} change is complete.")
            ->send();
    }

    public function getBreadcrumbs(): array
    {
        return [
            BookingResource::getUrl() => 'Bookings',
            '#' => 'Change',
        ];
    }
}
