<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HandlesPaymentStep;
use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\Payment;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingException;
use App\Services\Bookings\BookingService;
use App\Services\Flights\DuffelApiException;
use App\Services\Flights\FlightProviderManager;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * The other half of the flight-search screen: takes an offer the user
 * picked in resources/views/flights/results.blade.php ("Select" button,
 * which links here with ?provider=&offer=) and walks it through the
 * lifecycle the API already implements — hold, then pay — reusing
 * BookingService and PaymentService rather than re-implementing any of it.
 *
 * Not in the sidebar (shouldRegisterNavigation = false); it's only ever
 * reached from a search result. Post-purchase servicing (change / cancel)
 * lives on ChangeBooking and the BookingResource actions.
 */
class BookFlight extends Page implements HasForms
{
    use HandlesPaymentStep;
    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'book-flight';

    protected static ?string $title = 'Book Flight';

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static string $view = 'filament.pages.book-flight';

    /** 1 = review offer, 2 = passengers, 3 = payment, 4 = done. */
    public int $step = 1;

    public ?string $provider = null;

    public ?string $offerId = null;

    /** Duffel's raw offer object, re-fetched live in mount(). */
    public array $offer = [];

    public ?int $bookingId = null;

    /** Passenger picker state — statePath('data'). */
    public ?array $data = [];

    public function mount(): void
    {
        // Resume: an already-held booking that still needs payment (linked
        // here from the BookingResource "Take payment" action).
        if ($resumeId = request()->integer('booking')) {
            $booking = Booking::find($resumeId);

            abort_unless($booking !== null, 404);
            abort_unless(
                auth()->user()->can('view', $booking) || auth()->id() === $booking->user_id,
                403,
            );

            if (! $booking->isHeld()) {
                Notification::make()->warning()->title('That booking is not awaiting payment.')->send();
                $this->redirect(BookingResource::getUrl('view', ['record' => $booking->id]));

                return;
            }

            if ($booking->hasExpired()) {
                Notification::make()->danger()->title('This booking hold has expired.')->send();
                $this->redirect(BookingResource::getUrl('view', ['record' => $booking->id]));

                return;
            }

            $this->bookingId = $booking->id;
            $this->provider = $booking->flightProvider->code;
            $this->offerId = $booking->provider_offer_id;
            $this->step = 3;

            return;
        }

        $this->provider = (string) (request()->query('provider') ?: 'duffel');
        $this->offerId = request()->query('offer');

        abort_unless(filled($this->offerId), 404);

        $providerModel = FlightProvider::query()->enabled()->where('code', $this->provider)->first();

        if (! $providerModel) {
            Notification::make()->danger()->title('That flight provider is not available.')->send();
            $this->redirect(FlightSearch::getUrl());

            return;
        }

        try {
            $this->offer = app(FlightProviderManager::class)->driver($providerModel)->getOffer($this->offerId)->raw;
        } catch (DuffelApiException $e) {
            Notification::make()->danger()->title('Could not load that offer')->body($e->getMessage())->send();
            $this->redirect(FlightSearch::getUrl());

            return;
        }

        $this->form->fill([
            'customer_id' => auth()->id(),
            'passengers' => [['traveler_profile_id' => null, 'type' => 'adult']],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Select::make('customer_id')
                    ->label('Customer account')
                    ->helperText('The booking is created under this account; travelers below must belong to it.')
                    ->options(fn () => User::query()
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} — {$u->email}"]))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('passengers', [['traveler_profile_id' => null, 'type' => 'adult']])),

                Repeater::make('passengers')
                    ->label('Passengers')
                    ->schema([
                        Select::make('traveler_profile_id')
                            ->label('Traveler')
                            ->options(fn (Get $get) => $this->travelerOptions())
                            ->searchable()
                            ->required()
                            ->createOptionForm([
                                Select::make('title')->options(['mr' => 'Mr', 'mrs' => 'Mrs', 'ms' => 'Ms', 'mx' => 'Mx']),
                                Select::make('gender')->options(['m' => 'Male', 'f' => 'Female', 'x' => 'Unspecified']),
                                TextInput::make('first_name')->required()->maxLength(255),
                                TextInput::make('last_name')->required()->maxLength(255),
                                DatePicker::make('date_of_birth')
                                    ->label('Date of birth')
                                    ->required()
                                    ->native(false)
                                    ->displayFormat('d/m/Y')
                                    ->format('Y-m-d')
                                    ->placeholder('dd/mm/yyyy')
                                    ->minDate(now()->subYears(120)->startOfDay())
                                    ->maxDate(today()->subDay())
                                    ->rules(['before:today']),
                                TextInput::make('nationality')->length(2)->placeholder('GB'),
                                TextInput::make('email')->email()->maxLength(255),
                                TextInput::make('phone')->tel()->maxLength(30),
                                TextInput::make('passport_number')
                                    ->label('Passport number')
                                    ->required()
                                    ->maxLength(50),
                                DatePicker::make('passport_expiry')
                                    ->label('Passport expiry')
                                    ->required()
                                    ->native(false)
                                    ->displayFormat('d/m/Y')
                                    ->format('Y-m-d')
                                    ->placeholder('dd/mm/yyyy')
                                    ->minDate(today()->addDay())
                                    ->maxDate(today()->addYears(20))
                                    ->rules(['after:today']),
                            ])
                            ->createOptionUsing(fn (array $data) => TravelerProfile::create([
                                ...$data,
                                'user_id' => $this->data['customer_id'] ?? auth()->id(),
                            ])->getKey()),

                        Select::make('type')
                            ->options(['adult' => 'Adult', 'child' => 'Child', 'infant' => 'Infant'])
                            ->default('adult')
                            ->required(),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Add passenger'),
            ]);
    }

    /** @return array<int, string> */
    protected function travelerOptions(): array
    {
        return TravelerProfile::query()
            ->where('user_id', $this->data['customer_id'] ?? auth()->id())
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (TravelerProfile $p) => [
                $p->id => $p->fullName().' · '.$p->date_of_birth?->format('d M Y'),
            ])
            ->all();
    }

    /**
     * The passenger mix this offer was actually priced for, as
     * [type => count] (e.g. ['adult' => 2, 'child' => 1]). Booking a
     * different count/mix makes the provider reject the order at
     * confirmation time — after payment — so it's checked before the hold.
     *
     * @return array<string, int>
     */
    public function getRequiredPassengersProperty(): array
    {
        return collect(data_get($this->offer, 'passengers', []))
            ->map(fn ($p) => match (data_get($p, 'type')) {
                'child' => 'child',
                'infant_without_seat', 'infant' => 'infant',
                default => 'adult',
            })
            ->countBy()
            ->all();
    }

    public function describeRequiredPassengers(): string
    {
        $required = $this->requiredPassengers;

        if ($required === []) {
            return '';
        }

        return collect($required)
            ->map(fn (int $count, string $type) => $count.' '.Str::plural($type, $count))
            ->join(', ', ' and ');
    }

    public function goToPassengers(): void
    {
        $this->step = 2;
    }

    public function backToReview(): void
    {
        $this->step = 1;
    }

    public function createHold(): void
    {
        $state = $this->form->getState();

        $customer = User::find($state['customer_id']);

        if (! $customer) {
            Notification::make()->danger()->title('Pick a customer account first.')->send();

            return;
        }

        $passengers = collect($state['passengers'] ?? [])
            ->filter(fn ($p) => filled($p['traveler_profile_id'] ?? null))
            ->map(fn ($p) => [
                'traveler_profile_id' => (int) $p['traveler_profile_id'],
                'type' => $p['type'] ?? 'adult',
            ])
            ->values()
            ->all();

        if ($passengers === []) {
            Notification::make()->danger()->title('Add at least one passenger.')->send();

            return;
        }

        $required = $this->requiredPassengers;

        if ($required !== []) {
            $selected = collect($passengers)->countBy('type')->all();

            if ($selected != $required) {
                Notification::make()->danger()
                    ->title('Passengers don’t match this fare')
                    ->body('This offer is priced for '.$this->describeRequiredPassengers().'. Adjust the passengers to match, or start a new search.')
                    ->send();

                return;
            }
        }

        try {
            $booking = app(BookingService::class)->createHold(
                $customer,
                $this->provider,
                $this->offerId,
                $passengers,
            );
        } catch (BookingException|DuffelApiException $e) {
            Notification::make()->danger()->title('Could not hold this flight')->body($e->getMessage())->send();

            return;
        }

        $this->bookingId = $booking->id;
        $this->step = 3;

        Notification::make()
            ->success()
            ->title("Flight held — booking #{$booking->id}")
            ->body('Hold expires '.($booking->expires_at?->format('d M H:i') ?? 'soon').'. Take payment to confirm it.')
            ->send();
    }

    public function getBookingProperty(): ?Booking
    {
        return $this->bookingId ? Booking::find($this->bookingId) : null;
    }

    public function startPayment(string $gateway): void
    {
        $booking = $this->booking;

        if (! $booking) {
            return;
        }

        try {
            $result = app(PaymentService::class)->initiate($booking, $gateway);
        } catch (PaymentException $e) {
            Notification::make()->danger()->title('Payment could not start')->body($e->getMessage())->send();

            return;
        }

        $this->rememberGatewayIntent($gateway, $result['payment'], $result['client_data']);
    }

    protected function currentPayment(): ?Payment
    {
        return $this->paymentId ? Payment::find($this->paymentId) : null;
    }

    protected function onPaymentSettled(Payment $payment): void
    {
        $this->step = 4;

        $booking = $this->booking;

        Notification::make()
            ->success()
            ->title('Payment received')
            ->body($booking && $booking->status === Booking::STATUS_CONFIRMED
                ? "Booking #{$booking->id} is confirmed."
                : "Booking #{$booking?->id} is being ticketed with the provider.")
            ->send();
    }

    public function getBreadcrumbs(): array
    {
        return [
            FlightSearch::getUrl() => 'Search Flights',
            '#' => 'Book',
        ];
    }
}
