<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\License\DemoMode;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'theme',
        'role',
        'permissions',
    ];

    /** What a member can be given. Admins and the owner have all of it, plus Settings and Team. */
    public const PERMISSIONS = [
        'workflows.view' => 'View workflows',
        'workflows.edit' => 'Create & edit workflows',
        'workflows.delete' => 'Delete workflows',
        'ai' => 'Use the AI builder',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'invite_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'invite_expires_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Edit and delete include view. Using and adding credentials comes with edit (secrets are never shown);
     * deleting them comes with delete.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        $granted = (array) $this->permissions;

        return $permission === 'workflows.view'
            ? array_intersect(['workflows.view', 'workflows.edit', 'workflows.delete'], $granted) !== []
            : in_array($permission, $granted, true);
    }

    /** @return array<string, bool> for the UI: which modules and buttons to show */
    public function abilities(): array
    {
        return ['admin' => $this->isAdmin()] + collect(self::PERMISSIONS)->mapWithKeys(fn ($label, $key) => [$key => $this->hasPermission($key)])->all();
    }

    /** The shared visitors' account of the live demo (see DemoMode). */
    public function isDemo(): bool
    {
        return app(DemoMode::class)->isDemoUser($this);
    }

    public function isInvited(): bool
    {
        return $this->invite_token !== null;
    }
}
