<?php

namespace App\Domain\Voice\Models;

use App\Domain\Integrations\Models\Credential;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A voice agent. The phone side (audio, speech, telephony) runs on the provider (Vapi or ElevenLabs);
 * "browser" agents need no provider and talk through the browser's speech engine with the AI builder's model.
 *
 * config = {first_message, instructions, language, voice, llm, booking: {enabled, ask_email, hours}, collect: [{key, description}], template}
 */
class VoiceAgent extends Model
{
    public const PROVIDERS = ['browser', 'vapi', 'elevenlabs'];

    /** The credential app each provider uses (manifests in resources/integrations). */
    public const CREDENTIAL_APP = ['vapi' => 'vapi', 'elevenlabs' => 'elevenlabs'];

    protected $fillable = ['name', 'provider', 'credential_id', 'config', 'phone_number_id', 'phone_label'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['config' => 'array', 'synced_at' => 'datetime', 'polled_at' => 'datetime', 'events_secret_sent' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (VoiceAgent $agent) => $agent->token ??= Str::random(40));
    }

    public function calls(): HasMany
    {
        return $this->hasMany(VoiceCall::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(VoiceBooking::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function bookingEnabled(): bool
    {
        return (bool) $this->setting('booking.enabled', false);
    }

    public function isPhone(): bool
    {
        return $this->provider !== 'browser';
    }

    /** Where the provider sends tool calls (bookings) and call reports. */
    public function eventsUrl(): string
    {
        return url('/api/voice/'.$this->token);
    }
}
