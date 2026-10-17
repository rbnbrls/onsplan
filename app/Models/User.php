<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    // HasApiTokens: Passport tokens issued to MCP clients through the OAuth consent flow (see docs/mcp.md).
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    // Every user gets a pseudonymous analytics id up front (the column is NOT NULL). Sign-up may then swap it
    // for the onsplan.nl visitor id they arrived with (RegisteredUserController::adoptAnalytics), so
    // `??=` leaves a preset one alone. Deliberately not fillable, like is_platform_admin.
    protected static function booted(): void
    {
        static::creating(fn (User $user) => $user->analytics_id ??= (string) Str::uuid());
    }

    /**
     * Member of the public demo family (is_demo): the account anyone can enter via /demo. Memoized per
     * request; BlockDemo asks it on the guarded account/family routes.
     */
    public function isDemo(): bool
    {
        return $this->isDemoCache ??= $this->families()->where('is_demo', true)->exists();
    }

    private ?bool $isDemoCache = null;

    public function families(): BelongsToMany
    {
        return $this->belongsToMany(Family::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    // "Sign in with Google / Apple" links (App\Support\Auth\SocialLogin).
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'notification_prefs',
        'locale',
        'theme',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // Analytics stays out of every serialized user (auth.user, member lists): the id reaches the page
        // only as the `analyticsId` prop, and only where Umami is configured (HandleInertiaRequests).
        'analytics_id',
        'first_touch',
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
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'notification_prefs' => 'array',
            'first_touch' => 'array',
            // Set only by admin:grant / admin:revoke; deliberately not fillable.
            'is_platform_admin' => 'boolean',
        ];
    }

    // Does this user want pushes for the given category? (missing/unset = yes). Categories themselves
    // live in the notifications module's registry; this only reads the saved on/off map.
    public function wantsPush(string $category): bool
    {
        return (bool) ($this->notification_prefs[$category] ?? true);
    }
}
