<?php

use App\Enums\AnnouncementLevel;
use App\Events\AnnouncementCleared;
use App\Events\AnnouncementCreated;
use App\Models\Announcement;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WidgetSite;
use Illuminate\Support\Facades\Event;

test('guests cannot manage announcements', function () {
    $this->get(route('agent.announcements.index'))->assertRedirect('/login');
    $this->post(route('agent.announcements.store'))->assertRedirect('/login');
});

test('an agent can broadcast an announcement to specific sites', function () {
    Event::fake([AnnouncementCreated::class]);

    $agent = User::factory()->create();

    $this->actingAs($agent)
        ->postJson(route('agent.announcements.store'), [
            'title' => 'Scheduled maintenance',
            'message' => 'The server restarts at 22:00.',
            'level' => 'critical',
            'property_ids' => ['acme-inc'],
            'expires_in_minutes' => 60,
        ])
        ->assertCreated();

    $announcement = Announcement::sole();

    expect($announcement->level)->toBe(AnnouncementLevel::Critical)
        ->and($announcement->property_ids)->toBe(['acme-inc'])
        ->and($announcement->created_by)->toBe($agent->id)
        ->and($announcement->expires_at)->not->toBeNull();

    Event::assertDispatched(AnnouncementCreated::class, fn (AnnouncementCreated $event) => $event->announcement->is($announcement));
});

test('an announcement without targets reaches every site', function () {
    $agent = User::factory()->create();

    $this->actingAs($agent)
        ->postJson(route('agent.announcements.store'), [
            'message' => 'We are back online.',
            'property_ids' => [],
        ])
        ->assertCreated();

    expect(Announcement::sole()->property_ids)->toBeNull();
});

test('an announcement requires a message', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('agent.announcements.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');
});

test('an agent can deactivate an announcement', function () {
    Event::fake([AnnouncementCleared::class]);

    $announcement = Announcement::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('agent.announcements.deactivate', $announcement))
        ->assertNoContent();

    expect($announcement->refresh()->is_active)->toBeFalse();

    Event::assertDispatched(AnnouncementCleared::class);
});

test('the agent announcement list only returns active announcements', function () {
    Announcement::factory()->create(['message' => 'Live one']);
    Announcement::factory()->inactive()->create(['message' => 'Cancelled one']);
    Announcement::factory()->expired()->create(['message' => 'Old one']);

    $this->actingAs(User::factory()->create())
        ->getJson(route('agent.announcements.index'))
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonFragment(['message' => 'Live one']);
});

test('a widget reports the site it is embedded on', function () {
    $this->postJson('/api/widget/sites/heartbeat', ['property_id' => 'acme-inc'], ['Origin' => 'https://acme.example'])
        ->assertNoContent();

    $this->assertDatabaseHas('widget_sites', [
        'property_id' => 'acme-inc',
        'origin' => 'https://acme.example',
    ]);
});

test('repeat heartbeats from one site refresh it rather than duplicating it', function () {
    $headers = ['Origin' => 'https://acme.example'];

    $this->postJson('/api/widget/sites/heartbeat', ['property_id' => 'acme-inc'], $headers)->assertNoContent();
    $this->postJson('/api/widget/sites/heartbeat', ['property_id' => 'acme-inc'], $headers)->assertNoContent();

    expect(WidgetSite::count())->toBe(1);
});

test('one property embedded on several domains records each of them', function () {
    $this->postJson('/api/widget/sites/heartbeat', ['property_id' => 'acme-inc'], ['Origin' => 'https://acme.example']);
    $this->postJson('/api/widget/sites/heartbeat', ['property_id' => 'acme-inc'], ['Origin' => 'https://shop.acme.example']);

    $this->actingAs(User::factory()->create())
        ->getJson(route('agent.property-ids'))
        ->assertSuccessful()
        ->assertJsonPath('0.property_id', 'acme-inc')
        ->assertJsonPath('0.origins', ['https://acme.example', 'https://shop.acme.example']);
});

test('a heartbeat requires a property id', function () {
    $this->postJson('/api/widget/sites/heartbeat', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('property_id');
});

test('the agent site list merges reporting widgets with conversation-only sites', function () {
    WidgetSite::factory()->create(['property_id' => 'acme-inc', 'origin' => 'https://acme.example']);
    Conversation::factory()->create(['property_id' => 'globex']);
    Conversation::factory()->create(['property_id' => 'globex']);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('agent.property-ids'))
        ->assertSuccessful()
        ->assertJsonCount(2);

    expect($response->json('0.property_id'))->toBe('acme-inc')
        ->and($response->json('0.origins'))->toBe(['https://acme.example'])
        ->and($response->json('1.property_id'))->toBe('globex')
        ->and($response->json('1.origins'))->toBe([]);
});

test('a widget receives an announcement targeted at its property', function () {
    Announcement::factory()->targeting(['acme-inc'])->create(['message' => 'Maintenance at 22:00']);

    $this->getJson('/api/widget/announcements?property_id=acme-inc')
        ->assertSuccessful()
        ->assertJsonFragment(['message' => 'Maintenance at 22:00']);
});

test('a widget does not receive announcements targeted at other properties', function () {
    Announcement::factory()->targeting(['globex'])->create();

    $this->getJson('/api/widget/announcements?property_id=acme-inc')
        ->assertSuccessful()
        ->assertExactJson([]);
});

test('an untargeted announcement reaches any property', function () {
    Announcement::factory()->create(['message' => 'Everyone sees this']);

    $this->getJson('/api/widget/announcements?property_id=whatever')
        ->assertSuccessful()
        ->assertJsonFragment(['message' => 'Everyone sees this']);
});

test('expired and deactivated announcements are not delivered to widgets', function () {
    Announcement::factory()->expired()->create();
    Announcement::factory()->inactive()->create();

    $this->getJson('/api/widget/announcements?property_id=acme-inc')
        ->assertSuccessful()
        ->assertExactJson([]);
});
