<?php

namespace App\Support\Waste;

use App\Models\FamilyWasteSettings;
use RuntimeException;

class WasteProviderRegistry
{
    public const PROVIDERS = [
        'mijnafvalwijzer' => 'Mijn Afvalwijzer',
    ];

    public function provider(?string $id): WasteCollectionProvider
    {
        return match ($id) {
            'mijnafvalwijzer' => app(MijnAfvalwijzerProvider::class),
            default => throw new RuntimeException('Deze afvalinzamelaar wordt nog niet ondersteund.'),
        };
    }

    /** @return list<array{id:string,name:string}> */
    public function options(): array
    {
        return collect(self::PROVIDERS)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all();
    }

    /** Try the default provider; the UI can still select a provider explicitly after failure. */
    public function detect(FamilyWasteSettings $settings): ?string
    {
        try {
            $from = now()->toDateString();
            $this->provider('mijnafvalwijzer')->fetch($settings, $from, now()->addMonths(3)->toDateString());
            return 'mijnafvalwijzer';
        } catch (\Throwable) {
            return null;
        }
    }
}
