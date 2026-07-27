<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

test('guests cannot access the agent dashboard', function () {
    $this->get('/agent/dashboard')->assertRedirect('/login');
});

test('an authenticated agent can view the dashboard with conversations', function () {
    $user = User::factory()->create();
    Conversation::factory()->count(2)->create();

    $this->actingAs($user)
        ->get('/agent/dashboard')
        ->assertSuccessful();
});

test('an agent can view a conversation and reply, claiming it', function () {
    $agent = User::factory()->create();
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create(['sender_type' => 'visitor', 'body' => 'Hi there']);

    $this->actingAs($agent)
        ->getJson(route('agent.conversations.messages', $conversation->uuid))
        ->assertSuccessful()
        ->assertJsonCount(1);

    $this->actingAs($agent)
        ->postJson(route('agent.conversations.messages.store', $conversation->uuid), [
            'body' => 'Hi! How can I help?',
        ])
        ->assertCreated();

    $this->assertDatabaseHas('messages', [
        'conversation_id' => $conversation->id,
        'sender_type' => 'agent',
        'body' => 'Hi! How can I help?',
    ]);

    $this->assertDatabaseHas('conversations', [
        'id' => $conversation->id,
        'agent_id' => $agent->id,
    ]);
});

test('an agent can close a conversation', function () {
    $agent = User::factory()->create();
    $conversation = Conversation::factory()->create();

    $this->actingAs($agent)
        ->postJson(route('agent.conversations.close', $conversation->uuid))
        ->assertNoContent();

    $this->assertDatabaseHas('conversations', [
        'id' => $conversation->id,
        'status' => 'closed',
    ]);
});
