<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
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

    protected static ?int $navigationSort = 90;

    // A real column — 'name' is a computed accessor (first_name + last_name),
    // and global search below runs `where($attribute, 'like', ...)` directly
    // against the database, which would break on a non-column attribute.
    protected static ?string $recordTitleAttribute = 'first_name';

    public static function getNavigationGroup(): ?string
    {
        return 'Administration';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'email'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Account')
                ->schema([
                    TextInput::make('first_name')
                        ->label('First name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('last_name')
                        ->label('Last name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(User::class, 'email', ignoreRecord: true),

                    TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->rule(Password::default())
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->dehydrateStateUsing(fn (string $state) => Hash::make($state))
                        ->helperText('At least 8 characters, with uppercase, lowercase, and a number. Leave blank to keep the current password.'),

                    TextInput::make('password_confirmation')
                        ->label('Confirm Password')
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
                        ->label('Email verified')
                        ->helperText('On: the user can sign in right away. Off: they must verify their email first.')
                        ->default(true)
                        ->visible(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (string $operation) => $operation === 'create')
                        ->dehydrateStateUsing(fn ($state) => $state ? now() : null),
                ])->columns(2),

            Section::make('Roles')
                ->description('What this user can access in the admin panel.')
                ->schema([
                    Select::make('roles')
                        ->label('Roles')
                        ->multiple()
                        ->relationship('roles', 'name')
                        ->options(fn () => Role::pluck('name', 'id'))
                        ->preload()
                        ->helperText('What this user can access in the admin panel.')
                        // Goes through syncRoles() rather than Filament's default
                        // pivot ->sync() so RoleAttached/RoleDetached fire and
                        // the change lands in the audit log (see
                        // App\Listeners\LogPermissionActivity).
                        ->saveRelationshipsUsing(fn ($record, $state) => $record->syncRoles($state)),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('first_name')
                    ->label('First name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('last_name')
                    ->label('Last name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->separator(',')
                    ->color('primary'),

                TextColumn::make('email_verified_at')
                    ->label('Verified')
                    ->dateTime()
                    ->placeholder('Not verified')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Joined')
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
