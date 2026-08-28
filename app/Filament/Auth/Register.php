<?php

namespace App\Filament\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\Register as BaseRegister;
use Illuminate\Database\Eloquent\Model;

/**
 * Two changes on top of Filament's stock registration page:
 *
 *  - The name field is split into first_name/last_name (User has no `name`
 *    column — see App\Models\User's computed `name` accessor).
 *  - Assigns the default panel role to every self-registered account.
 *    Without this, a new user would authenticate fine but fail
 *    User::canAccessPanel() (which requires a role) and be bounced right
 *    back out — Filament's stock registration flow has no concept of panel
 *    access gating on its own.
 *
 * See App\Filament\Auth\Login for the same panel's login-side customisation.
 */
class Register extends BaseRegister
{
    protected function handleRegistration(array $data): Model
    {
        $user = parent::handleRegistration($data);

        $user->assignRole('panel_user');

        return $user;
    }

    /**
     * No-op on purpose. Filament's default sends its own
     * Filament\Notifications\Auth\VerifyEmail with a panel URL that sits
     * behind Authenticate middleware — it 302s to /login when the email is
     * opened on another device. parent::register() also fires the
     * `Registered` event, whose listener calls
     * User::sendEmailVerificationNotification() → App\Notifications\Auth\
     * VerifyEmail (staff-aware, auth-free link). Suppressing this avoids a
     * second, worse email.
     */
    protected function sendEmailVerificationNotification(Model $user): void
    {
        // Intentionally empty — see the `Registered` event listener.
    }

    /**
     * @return array<int|string, string|Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getFirstNameFormComponent(),
                        $this->getLastNameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getFirstNameFormComponent(): Component
    {
        return TextInput::make('first_name')
            ->label(__('First name'))
            ->required()
            ->maxLength(255)
            ->autofocus();
    }

    protected function getLastNameFormComponent(): Component
    {
        return TextInput::make('last_name')
            ->label(__('Last name'))
            ->required()
            ->maxLength(255);
    }

    protected function getPasswordFormComponent(): Component
    {
        // Strength itself (min length, mixed case, numbers) is enforced by
        // the Password::default() rule the base component already applies —
        // see AppServiceProvider::boot(). This just surfaces the requirement
        // to the user instead of letting them find out from a rejected form.
        return parent::getPasswordFormComponent()
            ->helperText('At least 8 characters, with uppercase, lowercase, and a number.');
    }
}
