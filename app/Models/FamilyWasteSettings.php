<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FamilyWasteSettings extends Model
{
    use BelongsToFamily;

    protected $table = 'family_waste_settings';
    protected $fillable = [
        'family_id', 'enabled', 'postal_code', 'house_number', 'house_number_suffix', 'street_name', 'country',
        'collector', 'collector_detection_status', 'selected_waste_types', 'task_list_id', 'task_offset_days',
        'task_time', 'sync_horizon_months', 'last_synced_at', 'last_error', 'status',
    ];
    protected $casts = ['enabled' => 'boolean', 'selected_waste_types' => 'array', 'last_synced_at' => 'datetime'];

    public function pickups(): HasMany
    {
        return $this->hasMany(FamilyWastePickup::class, 'family_id', 'family_id');
    }
}
