<?php

namespace App\Support\Waste;

use App\Models\FamilyWasteSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MijnAfvalwijzerProvider implements WasteCollectionProvider
{
    private const URL = 'https://api.mijnafvalwijzer.nl/webservices/appsinput/';
    private const API_KEY = '5ef443e778f41c4f75c69459eea6e6ae0c2d92de729aa0fc61653815fbd6a8ca';

    public function fetch(FamilyWasteSettings $settings, string $from, string $to): array
    {
        $response = Http::timeout(20)->retry(2, 300)->get(self::URL, [
            'apikey' => self::API_KEY,
            'method' => 'postcodecheck',
            'postcode' => $settings->postal_code,
            'street' => '',
            'huisnummer' => $settings->house_number,
            'toevoeging' => $settings->house_number_suffix ?? '',
            'app_name' => 'onsplan',
            'platform' => 'web',
            'langs' => 'nl',
            'afvaldata' => $from,
        ]);
        if ($response->failed()) {
            throw new RuntimeException('De afvalprovider kon niet worden bereikt.');
        }

        $json = $response->json();
        $items = array_merge($json['ophaaldagen']['data'] ?? [], array_slice($json['ophaaldagenNext']['data'] ?? [], 0, 100));
        $result = [];
        foreach ($items as $item) {
            $date = $item['date'] ?? null;
            $type = trim(strtolower((string) ($item['type'] ?? '')));
            if (! $date || ! $type || $date < $from || $date > $to) continue;
            $result[] = ['type' => $type, 'label' => $this->label($type), 'pickup_date' => $date, 'raw' => $item];
        }

        if (! $result && empty($items)) throw new RuntimeException('De afvalprovider gaf geen ophaaldagen terug.');
        return $result;
    }

    private function label(string $type): string
    {
        return match ($type) {
            'gft', 'groente-, fruit- en tuinafval' => 'GFT',
            'papier', 'oud papier' => 'Papier',
            'pmd', 'plastic', 'plastic verpakkingen' => 'PMD',
            'rest', 'restafval' => 'Restafval',
            default => mb_convert_case(str_replace(['_', '-'], ' ', $type), MB_CASE_TITLE, 'UTF-8'),
        };
    }
}
