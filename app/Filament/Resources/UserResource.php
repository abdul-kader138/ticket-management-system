<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\Subscriptions\SubscriptionException;
use App\Services\Subscriptions\SubscriptionService;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Staff account management — creating/editing users and assigning Spatie
 * roles. Account provisioning (including who can be granted super_admin)
 * stays super_admin-only.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function getNavigationLabel(): string
    {
        return __('Users');
    }

    public static function getModelLabel(): string
    {
        return __('User');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Users');
    }

    protected static ?int $navigationSort = 10;

    // A real column — 'name' is a computed accessor (first_name + last_name),
    // and global search below runs `where($attribute, 'like', ...)` directly
    // against the database, which would break on a non-column attribute.
    protected static ?string $recordTitleAttribute = 'first_name';

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'email'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('Account'))
                ->schema([
                    TextInput::make('first_name')
                        ->label(__('First name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('last_name')
                        ->label(__('Last name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label(__('Email'))
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(User::class, 'email', ignoreRecord: true),

                    Select::make('locale')
                        ->label(__('Language'))
                        ->options(['en' => __('English'), 'it' => __('Italiano'), 'bn' => __('বাংলা')])
                        ->placeholder(__('Use system default'))
                        ->native(false)
                        ->helperText(__('Leave blank to follow the system default language.')),

                    TextInput::make('password')
                        ->label(__('Password'))
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->rule(Password::default())
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->dehydrateStateUsing(fn (string $state) => Hash::make($state))
                        ->helperText(__('At least 8 characters, with uppercase, lowercase, and a number. Leave blank to keep the current password.')),

                    TextInput::make('password_confirmation')
                        ->label(__('Confirm Password'))
                        ->password()
                        ->revealable()
                        ->dehydrated(false)
                        ->same('password')
                        ->required(fn (string $operation) => $operation === 'create'),

                    // An account a super admin provisions here does not go
                    // through the customer email-verification flow: left on
                    // (the default), the user can sign in immediately; turned
                    // off, `email_verified_at` stays null and the customer
                    // API refuses the login until they verify (see
                    // routes/api.php's 'verified' group). Only meaningful at
                    // creation time — an existing user's verification state
                    // is managed by verifying, not by this form.
                    Toggle::make('email_verified_at')
                        ->label(__('Email verified'))
                        ->helperText(__('On: the user can sign in right away. Off: they must verify their email first.'))
                        ->default(true)
                        ->visible(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (string $operation) => $operation === 'create')
                        ->dehydrateStateUsing(fn ($state) => $state ? now() : null),
                ])->columns(2),

            Section::make(__('Roles'))
                ->description(__('What this user can access in the admin panel.'))
                ->schema([
                    Select::make('roles')
                        ->label(__('Roles'))
                        ->multiple()
                        ->relationship('roles', 'name')
                        ->options(fn () => Role::pluck('name', 'id'))
                        ->preload()
                        ->helperText(__('What this user can access in the admin panel.'))
                        // Goes through syncRoles() rather than Filament's default
                        // pivot ->sync() so RoleAttached/RoleDetached fire and
                        // the change lands in the audit log (see
                        // App\Listeners\LogPermissionActivity).
                        // $state is an array of role IDs as strings (all form
                        // state round-trips through HTTP as strings), and
                        // syncRoles() treats a numeric string as a role *name*
                        // lookup rather than an ID lookup — resolve to Role
                        // models first so it doesn't misinterpret them.
                        ->saveRelationshipsUsing(fn ($record, $state) => $record->syncRoles(Role::findMany($state))),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // The 'subscription' column below reads $record->subscriptions
            // rather than calling SubscriptionService per row — this eager
            // load is what keeps that a fixed 2 queries for the whole page
            // instead of 2 per row (see BookingResource's identical note
            // on its 'payments' eager load).
            ->modifyQueryUsing(fn ($query) => $query->with(['subscriptions' => fn ($q) => $q
                ->where('status', UserSubscription::STATUS_ACTIVE)
                ->where('starts_at', '<=', now())
                ->where(fn ($q2) => $q2->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->latest('starts_at')
                ->with('subscriptionPlan'),
            ]))
            ->columns([
                TextColumn::make('first_name')
                    ->label(__('First name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('last_name')
                    ->label(__('Last name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('roles.name')->label(__('Roles'))
                    ->badge()
                    ->separator(',')
                    ->color('primary'),

                TextColumn::make('subscription')->label(__('Plan'))
                    ->state(fn (User $record) => $record->subscriptions->first()?->subscriptionPlan?->name ?? '—')
                    ->badge()
                    ->color(fn (string $state) => $state === '—' ? 'gray' : 'success'),

                TextColumn::make('email_verified_at')
                    ->label(__('Verified'))
                    ->dateTime()
                    ->placeholder(__('Not verified'))
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('Joined'))
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->preload(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                // Comps a plan onto a customer without going through
                // checkout — for support gestures, migrating a legacy
                // subscriber, or testing. Goes through SubscriptionService
                // so ends_at/the active-plan cache stay correct, rather
                // than writing a UserSubscription row by hand.
                Action::make('grantSubscription')
                    ->label(__('Grant subscription'))
                    ->icon('heroicon-o-gift')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Activates immediately, no payment required — for comps, migrations, or support gestures.')
                    ->visible(fn (User $record) => auth()->user()->can('update_user')
                        && ! app(SubscriptionService::class)->hasOpenSubscription($record)
                        && SubscriptionPlan::query()->active()->exists())
                    ->form([
                        Select::make('subscription_plan_id')
                            ->label(__('Plan'))
                            ->options(fn () => SubscriptionPlan::query()->active()->orderBy('price_cents')->pluck('name', 'id'))
                            ->required(),
                    ])
                    ->action(function (User $record, array $data) {
                        try {
                            app(SubscriptionService::class)->grantComplimentary(
                                $record,
                                SubscriptionPlan::findOrFail($data['subscription_plan_id']),
                            );
                            Notification::make()->success()->title(__('Subscription granted'))->send();
                        } catch (SubscriptionException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Action::make('cancelSubscription')
                    ->label(__('Cancel subscription'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Ends access immediately. No refund is issued for the unused remainder.')
                    ->visible(fn (User $record) => auth()->user()->can('update_user')
                        && app(SubscriptionService::class)->activeSubscription($record))
                    ->action(function (User $record) {
                        $subscriptions = app(SubscriptionService::class);

                        try {
                            $subscriptions->cancel($subscriptions->activeSubscription($record));
                            Notification::make()->success()->title(__('Subscription cancelled'))->send();
                        } catch (SubscriptionException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
