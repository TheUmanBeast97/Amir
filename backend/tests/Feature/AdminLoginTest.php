<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** L'amministratore si crea da terminale con `amir:admin`: la password non sta mai in .env né nel codice. */
class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    private const ASK = 'Password (almeno 10 caratteri)';

    private const AGAIN = 'Ripeti la password';

    public function test_the_command_creates_an_admin_who_can_log_in_even_with_capital_letters(): void
    {
        $this->artisan('amir:admin', ['email' => 'Capo@Example.com', '--name' => 'Il Capo'])
            ->expectsQuestion(self::ASK, 'password-di-prova-1')
            ->expectsQuestion(self::AGAIN, 'password-di-prova-1')
            ->assertSuccessful();

        $user = User::where('email', 'capo@example.com')->firstOrFail();
        $this->assertSame('Il Capo', $user->name);
        // in database c'è solo l'hash, mai la password
        $this->assertNotSame('password-di-prova-1', $user->password);
        $this->assertTrue(Hash::check('password-di-prova-1', $user->password));

        $this->postJson('/api/v1/auth/login', ['email' => 'CAPO@example.com', 'password' => 'password-di-prova-1'])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'capo@example.com')
            ->assertJsonStructure(['data' => ['token']]);

        $this->postJson('/api/v1/auth/login', ['email' => 'capo@example.com', 'password' => 'sbagliata-di-sicuro'])
            ->assertStatus(422);
    }

    public function test_a_short_or_unconfirmed_password_is_refused_and_nobody_is_created(): void
    {
        $this->artisan('amir:admin', ['email' => 'capo@example.com'])
            ->expectsQuestion(self::ASK, 'corta')
            ->assertFailed();

        $this->artisan('amir:admin', ['email' => 'capo@example.com'])
            ->expectsQuestion(self::ASK, 'password-di-prova-1')
            ->expectsQuestion(self::AGAIN, 'password-DIVERSA-2')
            ->assertFailed();

        $this->artisan('amir:admin', ['email' => 'non-una-email'])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_running_it_again_changes_the_password_and_logs_out_old_sessions(): void
    {
        $user = User::factory()->create(['email' => 'capo@example.com', 'password' => 'vecchia-password-1']);
        $user->createToken('web');

        $this->artisan('amir:admin', ['email' => 'capo@example.com'])
            ->expectsQuestion(self::ASK, 'nuova-password-22')
            ->expectsQuestion(self::AGAIN, 'nuova-password-22')
            ->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check('nuova-password-22', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/api/v1/auth/login', ['email' => 'capo@example.com', 'password' => 'vecchia-password-1'])->assertStatus(422);
    }

    public function test_a_server_can_start_with_a_ready_made_hash_and_never_overwrites_an_existing_admin(): void
    {
        $hash = Hash::make('password-di-prova-1');

        $this->artisan('amir:admin', ['email' => 'Capo@Example.com', '--hash' => $hash, '--if-missing' => true])->assertSuccessful();

        $this->postJson('/api/v1/auth/login', ['email' => 'capo@example.com', 'password' => 'password-di-prova-1'])->assertOk();
        $this->assertSame(1, User::count());

        // un secondo avvio con un'altra impronta non cambia la password di chi c'è già
        $this->artisan('amir:admin', ['email' => 'capo@example.com', '--hash' => Hash::make('altra-password-22'), '--if-missing' => true])->assertSuccessful();

        $this->postJson('/api/v1/auth/login', ['email' => 'capo@example.com', 'password' => 'password-di-prova-1'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'capo@example.com', 'password' => 'altra-password-22'])->assertStatus(422);
    }

    public function test_something_that_is_not_a_hash_is_refused(): void
    {
        $this->artisan('amir:admin', ['email' => 'capo@example.com', '--hash' => 'password-in-chiaro-1'])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_the_hash_command_prints_something_that_works_as_a_password(): void
    {
        $this->artisan('amir:hash')
            ->expectsQuestion('Password (almeno 10 caratteri)', 'password-di-prova-1')
            ->expectsQuestion('Ripeti la password', 'password-di-prova-1')
            ->expectsOutputToContain('$2y$')
            ->assertSuccessful();

        $this->artisan('amir:hash')->expectsQuestion('Password (almeno 10 caratteri)', 'corta')->assertFailed();
    }

    public function test_guessing_one_account_is_stopped_even_when_every_try_seems_to_come_from_a_different_address(): void
    {
        User::factory()->create(['email' => 'capo@example.com', 'password' => 'password-di-prova-1']);

        // chi falsifica X-Forwarded-For cambia indirizzo a ogni tentativo: il limite per account lo ferma lo stesso
        foreach (range(1, 6) as $i) {
            $this->withHeaders(['X-Forwarded-For' => "203.0.113.{$i}"])
                ->postJson('/api/v1/auth/login', ['email' => 'capo@example.com', 'password' => "sbagliata-{$i}-xx"])
                ->assertStatus(422);
        }

        $this->withHeaders(['X-Forwarded-For' => '203.0.113.99'])
            ->postJson('/api/v1/auth/login', ['email' => 'Capo@Example.com', 'password' => 'password-di-prova-1'])
            ->assertStatus(429);

        // un altro account non ne risente
        User::factory()->create(['email' => 'altro@example.com', 'password' => 'password-di-prova-1']);
        $this->postJson('/api/v1/auth/login', ['email' => 'altro@example.com', 'password' => 'password-di-prova-1'])->assertOk();
    }

    public function test_the_seeder_no_longer_creates_an_admin_from_the_environment(): void
    {
        // anche se in .env restasse una vecchia ADMIN_PASSWORD, il seeder non la usa
        putenv('ADMIN_PASSWORD=non-deve-servire-1');
        $_ENV['ADMIN_PASSWORD'] = 'non-deve-servire-1';

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
    }
}
