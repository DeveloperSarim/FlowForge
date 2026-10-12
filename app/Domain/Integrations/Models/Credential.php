<?php

namespace App\Domain\Integrations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $data decrypted secret fields (api keys, tokens, passwords)
 */
class Credential extends Model
{
    protected $fillable = ['name', 'integration', 'auth_type', 'data'];

    protected $hidden = ['data'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array'];
    }

    /**
     * String secrets long enough to be worth masking out of execution logs.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $values = [];
        $data = $this->data ?? [];
        array_walk_recursive($data, function ($value, $key) use (&$values) {
            if (is_string($value) && strlen($value) >= 6 && ! in_array($key, ['host', 'username', 'database', 'from_address', 'from_name', 'client_id', 'expires_at', 'token_type', 'scope'], true)) {
                $values[] = $value;
            }
        });

        return $values;
    }
}
