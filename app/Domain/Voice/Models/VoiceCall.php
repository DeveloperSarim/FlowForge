<?php

namespace App\Domain\Voice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One call (phone or browser). transcript = [{role: agent|user, text, at}] */
class VoiceCall extends Model
{
    public const DONE = ['completed', 'no-answer', 'failed'];

    protected $fillable = ['remote_id', 'direction', 'status', 'from_number', 'to_number', 'started_at', 'ended_at', 'duration_seconds', 'cost',
        'summary', 'outcome', 'transcript', 'data', 'recording_url', 'ended_reason', 'context'];

    protected $hidden = ['context'];

    protected function casts(): array
    {
        return ['transcript' => 'array', 'data' => 'array', 'context' => 'array', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'cost' => 'float'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(VoiceAgent::class, 'voice_agent_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(VoiceBooking::class);
    }

    public function isDone(): bool
    {
        return in_array($this->status, self::DONE, true);
    }

    public function transcriptText(): string
    {
        return collect($this->transcript ?? [])->map(fn ($t) => ($t['role'] === 'agent' ? 'Agent' : 'Caller').': '.$t['text'])->implode("\n");
    }
}
