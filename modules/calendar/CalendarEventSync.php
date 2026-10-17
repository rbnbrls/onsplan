<?php

namespace Modules\Calendar;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEventSync extends Model
{
    use BelongsToFamily;

    protected $fillable = [
        'family_id', 'calendar_integration_id', 'calendar_event_id', 'external_event_id',
        'external_ical_uid', 'external_href', 'etag', 'external_recurrence', 'external_sequence', 'origin', 'sync_hash', 'local_changed_at', 'external_changed_at',
        'external_deleted',
    ];

    protected $casts = [
        'local_changed_at' => 'datetime',
        'external_changed_at' => 'datetime',
        'external_deleted' => 'boolean',
        'external_sequence' => 'integer',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(CalendarIntegration::class, 'calendar_integration_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }
}
