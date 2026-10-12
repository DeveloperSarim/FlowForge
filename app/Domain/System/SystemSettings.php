<?php

namespace App\Domain\System;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Small encrypted key/value store for system configuration (SMTP, notifications, AI provider).
 * Reads are memoised per request; before install (no table) everything returns defaults.
 */
class SystemSettings
{
    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => Crypt::encryptString(json_encode($value)), 'updated_at' => now(), 'created_at' => now()],
        );
        $this->values = null;
    }

    public function forget(string $key): void
    {
        DB::table('settings')->where('key', $key)->delete();
        $this->values = null;
    }

    private function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        try {
            return $this->values = DB::table('settings')->pluck('value', 'key')
                ->map(fn ($value) => rescue(fn () => json_decode(Crypt::decryptString($value), true), null, false))
                ->all();
        } catch (Throwable) {
            return []; // not installed yet / table missing
        }
    }

    /** SMTP settings, or null when email isn't configured. */
    public function mail(): ?array
    {
        $mail = $this->get('mail');

        return is_array($mail) && ! empty($mail['host']) ? $mail : null;
    }

    /** Timezone for schedules and the assistant (storage stays in app.timezone, normally UTC). */
    public function timezone(): string
    {
        $timezone = $this->get('timezone');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : (config('app.timezone') ?: 'UTC');
    }

    public function notifications(): array
    {
        return array_merge([
            'on_failure' => true,
            'on_scheduler_stall' => true,
            'recipients' => [],
            'throttle_minutes' => 15,
        ], (array) $this->get('notifications', []));
    }
}
