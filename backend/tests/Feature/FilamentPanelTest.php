<?php

namespace Tests\Feature;

use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_can_be_created(): void
    {
        $property = Property::create(['name' => 'Usgar Cusco', 'slug' => 'usgar-cusco']);
        $this->assertDatabaseHas('properties', ['slug' => 'usgar-cusco']);
        $this->assertSame('usgar-cusco', $property->slug);
    }

    public function test_admin_login_page_renders(): void
    {
        $response = $this->get('/admin/login');
        $response->assertStatus(200);
    }
}
