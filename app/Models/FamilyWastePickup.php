<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

class FamilyWastePickup extends Model
{
    use BelongsToFamily;

    protected $table = 'family_waste_pickups';
    protected $fillable = [
        'family_id', 'waste_type', 'waste_label', 'pickup_date', 'source_event_key', 'source_payload',
        'calendar_event_id', 'task_id', 'source_hash', 'status',
    ];
    protected $casts = ['pickup_date' => 'date', 'source_payload' => 'array'];
}
