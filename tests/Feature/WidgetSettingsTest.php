<?php

use App\Models\Conversation;
use App\Models\User;
use App\Models\WidgetSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

test('guests cannot access widget settings routes', function () {
    $this->get(route('agent.widget-settings.index'))->assertRedirect('/login');
    $this->get(route('agent.widget-settings.show', ['property_id' => 'acme-inc']))->assertRedirect('/login');
    $this->post(route('agent.widget-settings.store'))->assertRedirect('/login');
});

test('an agent fetching a never-configured site gets sensible defaults', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('agent.widget-settings.show', ['property_id' => 'brand-new-site']))
        ->assertSuccessful()
        ->assertJsonFragment([
            'property_id' => 'brand-new-site',
            'primary_color' => '#2563eb',
            'position' => 'bottom-right',
            'require_name' => false,
            'business_hours_enabled' => false,
        ]);
});

test('an agent can update widget settings for a site', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('agent.widget-settings.store'), [
            'property_id' => 'acme-inc',
            'primary_color' => '#16a34a',
            'position' => 'bottom-left',
            'brand_name' => 'Acme Support',
            'welcome_message' => 'Hi there, how can we help?',
            'collect_email' => true,
            'require_email' => true,
            'timezone' => 'Asia/Jakarta',
        ])
        ->assertSuccessful()
        ->assertJsonFragment([
            'property_id' => 'acme-inc',
            'primary_color' => '#16a34a',
            'position' => 'bottom-left',
            'brand_name' => 'Acme Support',
            'collect_email' => true,
            'require_email' => true,
        ]);

    $this->assertDatabaseHas('widget_settings', [
        'property_id' => 'acme-inc',
        'primary_color' => '#16a34a',
        'position' => 'bottom-left',
    ]);
});

test('an agent can upload and remove a widget logo', function () {
    Storage::fake('public');
    $agent = User::factory()->create();

    $response = $this->actingAs($agent)
        ->post(route('agent.widget-settings.store'), [
            'property_id' => 'acme-inc',
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertSuccessful();

    $settings = WidgetSetting::query()->where('property_id', 'acme-inc')->sole();
    Storage::disk('public')->assertExists($settings->logo_path);
    expect($response->json('logo_url'))->not->toBeNull();

    $this->actingAs($agent)
        ->post(route('agent.widget-settings.store'), [
            'property_id' => 'acme-inc',
            'remove_logo' => true,
        ])
        ->assertSuccessful()
        ->assertJsonFragment(['logo_url' => null]);

    Storage::disk('public')->assertMissing($settings->logo_path);
});

test('an invalid color is rejected', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('agent.widget-settings.store'), [
            'property_id' => 'acme-inc',
            'primary_color' => 'not-a-color',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('primary_color');
});

test('the widget public settings endpoint only exposes public-safe fields', function () {
    WidgetSetting::factory()->create([
        'property_id' => 'acme-inc',
        'timezone' => 'Asia/Jakarta',
        'business_hours_enabled' => true,
    ]);

    $response = $this->getJson('/api/widget/settings?property_id=acme-inc')->assertSuccessful();

    $response->assertJsonStructure([
        'primary_color', 'position', 'brand_name', 'logo_url', 'welcome_message',
        'require_name', 'collect_email', 'require_email', 'collect_topic', 'offline_message', 'is_online',
    ]);
    expect($response->json())->not->toHaveKeys(['timezone', 'business_hours', 'id', 'property_id']);
});

test('is_online reflects the configured business hours', function () {
    WidgetSetting::factory()->withBusinessHours([
        'mon' => ['enabled' => true, 'start' => '09:00', 'end' => '17:00'],
    ])->create(['property_id' => 'acme-inc', 'timezone' => 'Asia/Jakarta']);

    Carbon::setTestNow(Carbon::parse('2026-08-03 10:00:00', 'Asia/Jakarta')); // Monday
    $this->getJson('/api/widget/settings?property_id=acme-inc')
        ->assertSuccessful()
        ->assertJsonFragment(['is_online' => true]);

    Carbon::setTestNow(Carbon::parse('2026-08-03 20:00:00', 'Asia/Jakarta')); // Monday, after hours
    $this->getJson('/api/widget/settings?property_id=acme-inc')
        ->assertSuccessful()
        ->assertJsonFragment(['is_online' => false]);

    Carbon::setTestNow();
});

test('a site with business hours disabled is always online', function () {
    WidgetSetting::factory()->create(['property_id' => 'acme-inc', 'business_hours_enabled' => false]);

    $this->getJson('/api/widget/settings?property_id=acme-inc')
        ->assertSuccessful()
        ->assertJsonFragment(['is_online' => true]);
});

test('starting a conversation stores the visitor email and topic', function () {
    $this->postJson('/api/widget/conversations', [
        'property_id' => 'acme-inc',
        'visitor_id' => 'visitor-123',
        'visitor_name' => 'Jane Doe',
        'visitor_email' => 'jane@example.com',
        'topic' => 'Billing question',
    ])->assertCreated();

    $this->assertDatabaseHas('conversations', [
        'property_id' => 'acme-inc',
        'visitor_email' => 'jane@example.com',
        'topic' => 'Billing question',
    ]);
});

test('starting a conversation requires an email when the site demands it', function () {
    WidgetSetting::factory()->create(['property_id' => 'acme-inc', 'require_email' => true]);

    $this->postJson('/api/widget/conversations', [
        'property_id' => 'acme-inc',
        'visitor_id' => 'visitor-123',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('visitor_email');
});

test('the widget settings index page lists known sites', function () {
    Conversation::factory()->create(['property_id' => 'globex']);

    $this->actingAs(User::factory()->create())
        ->get(route('agent.widget-settings.index'))
        ->assertSuccessful();
});
