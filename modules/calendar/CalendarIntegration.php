<?php

namespace Modules\Calendar;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarIntegration extends Model
{
    use BelongsToFamily;

    protected $fillable = [
        'family_id', 'provider', 'server_url', 'endpoint_url', 'principal_url', 'calendar_home_url', 'calendar_id', 'calendar_name', 'access_token', 'refresh_token', 'access_token_expires_at', 'username', 'password',
        'microsoft_user_id', 'microsoft_email', 'status', 'subscription_id', 'subscription_expires_at',
        'sync_cursor', 'last_synced_at', 'last_error',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'username' => 'encrypted',
        'password' => 'encrypted',
        'subscription_expires_at' => 'datetime',
        'access_token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function eventSyncs(): HasMany
    {
        return $this->hasMany(CalendarEventSync::class);
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && ($this->provider === 'nextcloud_caldav' ? filled($this->password) : filled($this->refresh_token));
    }
}
