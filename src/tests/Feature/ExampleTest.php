<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** O sistema vive no painel /admin; quem não está logado vai para o login. */
    public function test_the_admin_panel_sends_guests_to_the_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
