<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
    }

    public function test_super_admin_can_access_user_resource(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->actingAs($admin)->get('/users')->assertOk();
        $this->actingAs($admin)->get('/users/create')->assertOk();
    }

    public function test_operator_cannot_access_user_resource(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operator');

        $this->actingAs($operator)->get('/users')->assertForbidden();
    }

    public function test_panel_user_cannot_access_user_resource(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('panel_user');

        $this->actingAs($staff)->get('/users')->assertForbidden();
    }

    public function test_super_admin_can_create_a_user_with_roles(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $operatorRole = Role::where('name', 'operator')->firstOrFail();

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Jamie',
                'last_name' => 'Fox',
                'email' => 'jamie@example.com',
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
                'roles' => [$operatorRole->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'jamie@example.com')->firstOrFail();
        $this->assertTrue($created->hasRole('operator'));
        $this->assertTrue(Hash::check('Password123', $created->password));
        // "Email verified" defaults on — an admin-provisioned account skips
        // the customer verification step and can sign in immediately.
        $this->assertNotNull($created->email_verified_at);
    }

    public function test_admin_can_require_a_new_user_to_verify_their_email(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Pat',
                'last_name' => 'Kim',
                'email' => 'pat@example.com',
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
                'email_verified_at' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(User::where('email', 'pat@example.com')->firstOrFail()->email_verified_at);
    }

    public function test_password_is_required_on_create_but_optional_on_edit(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm(['first_name' => 'No', 'last_name' => 'Password', 'email' => 'nopass@example.com'])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $existing = User::factory()->create(['email' => 'existing@example.com', 'password' => bcrypt('original-password')]);
        $originalHash = $existing->password;

        Livewire::test(EditUser::class, ['record' => $existing->getRouteKey()])
            ->fillForm(['first_name' => 'Existing', 'last_name' => 'Updated', 'email' => 'existing@example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $existing->refresh();
        $this->assertSame($originalHash, $existing->password);
        $this->assertSame('Existing Updated', $existing->name);
    }

    public function test_password_must_meet_the_strength_policy_on_create(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Weak',
                'last_name' => 'Password',
                'email' => 'weakpw@example.com',
                'password' => 'alllowercase1',
                'password_confirmation' => 'alllowercase1',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertNull(User::where('email', 'weakpw@example.com')->first());
    }

    public function test_email_must_be_unique(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Dup',
                'last_name' => 'Licate',
                'email' => 'taken@example.com',
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }
}
