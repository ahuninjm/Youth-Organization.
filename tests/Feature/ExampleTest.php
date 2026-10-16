<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_homepage_route_uses_the_existing_page_view(): void
    {
        $this->assertSame('/', route('home', absolute: false));
        $this->assertFileExists(resource_path('views/pages/index.php'));
    }

    public function test_homepage_shows_login_form_even_with_an_existing_user_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['user'] = [
            'id' => 1,
            'name' => 'Test User',
            'username' => 'test_user',
            'role' => 'admin',
        ];

        $this->get('/')
            ->assertOk()
            ->assertSee('name="username"', false)
            ->assertSee('>Login</button>', false)
            ->assertDontSee('Welcome, Test User.');

        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function test_clean_and_legacy_login_routes_are_named(): void
    {
        $this->assertSame('/login', route('pages.login', absolute: false));
        $this->assertSame('/login.php', route('legacy.login', absolute: false));
        $this->assertTrue(view()->exists('pages.login'));
        $this->assertFileExists(resource_path('views/pages/login.php'));
    }

    public function test_login_route_shows_login_form_even_with_an_existing_user_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['user'] = [
            'id' => 1,
            'name' => 'Test User',
            'username' => 'test_user',
            'role' => 'admin',
        ];

        $this->get('/login')
            ->assertOk()
            ->assertSee('name="username"', false)
            ->assertSee('>Login</button>', false)
            ->assertDontSee('Welcome, Test User.');

        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function test_application_assets_are_publicly_available(): void
    {
        $this->assertFileExists(public_path('assets/css/app.css'));
        $this->assertFileExists(public_path('img/P1.jpg'));
    }
}
