<?php

namespace App\Domain\Assistant\Models;

use App\Domain\Workflows\Models\Workflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConversation extends Model
{
    protected $fillable = ['user_id', 'title', 'provider', 'model', 'workflow_id', 'history', 'display', 'usage'];

    /** Chats are private: a URL with someone else's chat id is a 404. */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::query()->where('user_id', auth()->id())->where($field ?? 'id', $value)->firstOrFail();
    }

    protected function casts(): array
    {
        return ['history' => 'array', 'display' => 'array', 'usage' => 'array'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
