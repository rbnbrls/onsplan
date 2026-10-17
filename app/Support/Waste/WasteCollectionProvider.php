<?php

namespace App\Support\Waste;

use App\Models\FamilyWasteSettings;

interface WasteCollectionProvider
{
    /** @return list<array{type:string,label:string,pickup_date:string,raw:array}> */
    public function fetch(FamilyWasteSettings $settings, string $from, string $to): array;
}
