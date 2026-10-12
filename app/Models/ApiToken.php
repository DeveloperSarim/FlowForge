<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A personal access token: lets an agent act as its user over MCP, with that user's permissions. */
class ApiToken extends Model
{
    protected $fillable = ['user_id', 'name', 'token_hash', 'last_used_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{0: self, 1: string} the stored token and its plain value (shown once) */
    public static function issue(User $user, string $name): array
    {
        $plain = 'ff_'.Str::random(40);

        return [static::query()->create(['user_id' => $user->id, 'name' => $name, 'token_hash' => hash('sha256', $plain)]), $plain];
    }

    public static function findPlain(string $plain): ?self
    {
        return $plain === '' ? null : static::query()->with('user')->where('token_hash', hash('sha256', $plain))->first();
    }
}
