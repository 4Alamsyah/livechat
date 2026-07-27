<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'sender_type',
        'sender_name',
        'type',
        'body',
        'attachment_path',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['attachment_url'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    protected function attachmentUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attachment_path ? Storage::disk('public')->url($this->attachment_path) : null,
        );
    }
}
