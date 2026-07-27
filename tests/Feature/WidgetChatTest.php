<?php

use App\Events\ConversationClosed;
use App\Events\ConversationStarted;
use App\Events\MessageSent;
use App\Models\Conversation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

test('a visitor can start a conversation', function () {
    Event::fake([ConversationStarted::class]);

    $response = $this->postJson('/api/widget/conversations', [
        'property_id' => 'acme-inc',
        'visitor_id' => 'visitor-123',
        'visitor_name' => 'Jane Doe',
    ]);

    $response->assertCreated()->assertJsonStructure(['uuid', 'visitor_name', 'channel']);

    $this->assertDatabaseHas('conversations', [
        'property_id' => 'acme-inc',
        'visitor_id' => 'visitor-123',
        'visitor_name' => 'Jane Doe',
        'status' => 'open',
    ]);

    Event::assertDispatched(ConversationStarted::class);
});

test('starting a conversation requires a visitor id', function () {
    $this->postJson('/api/widget/conversations', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('visitor_id');
});

test('a visitor can send and list messages on their conversation', function () {
    Event::fake([MessageSent::class]);

    $conversation = Conversation::factory()->create();

    $this->postJson("/api/widget/conversations/{$conversation->uuid}/messages", [
        'body' => 'Hello, I need help!',
    ])->assertCreated();

    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'sender_type' => 'visitor',
        'body' => 'Hello, I need help!',
    ]);

    Event::assertDispatched(MessageSent::class);

    $this->getJson("/api/widget/conversations/{$conversation->uuid}/messages")
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonFragment(['body' => 'Hello, I need help!']);
});

test('the widget config endpoint exposes reverb and ice server settings', function () {
    $this->getJson('/api/widget/config')
        ->assertSuccessful()
        ->assertJsonStructure(['reverb' => ['key', 'host', 'port', 'scheme'], 'ice_servers']);
});

test('a visitor can send an image message', function () {
    Storage::fake('public');
    Event::fake([MessageSent::class]);

    $conversation = Conversation::factory()->create();

    $this->postJson("/api/widget/conversations/{$conversation->uuid}/messages", [
        'image' => UploadedFile::fake()->image('photo.jpg'),
    ])->assertCreated()->assertJsonFragment(['type' => 'image']);

    $message = $conversation->messages()->first();

    expect($message->type)->toBe('image');
    Storage::disk('public')->assertExists($message->attachment_path);
});

test('sending a message requires either a body or an image', function () {
    $conversation = Conversation::factory()->create();

    $this->postJson("/api/widget/conversations/{$conversation->uuid}/messages", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

test('a visitor can end their own conversation', function () {
    Event::fake([ConversationClosed::class]);

    $conversation = Conversation::factory()->create();

    $this->postJson("/api/widget/conversations/{$conversation->uuid}/close")
        ->assertNoContent();

    $this->assertDatabaseHas('conversations', [
        'id' => $conversation->id,
        'status' => 'closed',
    ]);

    Event::assertDispatched(ConversationClosed::class, fn (ConversationClosed $event) => $event->closedBy === 'visitor');
});
