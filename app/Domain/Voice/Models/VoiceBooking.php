<?php

namespace App\Domain\Voice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceBooking extends Model
{
    protected $fillable = ['voice_call_id', 'name', 'phone', 'email', 'service', 'starts_at', 'requested_time', 'notes', 'status'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(VoiceAgent::class, 'voice_agent_id');
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class, 'voice_call_id');
    }
}
