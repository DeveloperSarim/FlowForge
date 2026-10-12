<?php

namespace App\Domain\Assistant\Models;

use App\Domain\Workflows\Models\Workflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConversation extends Model
{
    protected $fillable = ['title', 'provider', 'model', 'workflow_id', 'history', 'display', 'usage'];

    protected function casts(): array
    {
        return ['history' => 'array', 'display' => 'array', 'usage' => 'array'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
