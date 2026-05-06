<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
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
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function senderMailboxes(): HasMany
    {
        return $this->hasMany(SenderMailbox::class);
    }

    public function seedMailboxes(): HasMany
    {
        return $this->hasMany(SeedMailbox::class);
    }

    public function warmupProfiles(): HasMany
    {
        return $this->hasMany(WarmupProfile::class);
    }

    public function warmupCampaigns(): HasMany
    {
        return $this->hasMany(WarmupCampaign::class);
    }

    public function contentTemplates(): HasMany
    {
        return $this->hasMany(ContentTemplate::class);
    }
}
