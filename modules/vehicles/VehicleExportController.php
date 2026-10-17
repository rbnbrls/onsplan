<?php

namespace Modules\Vehicles;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * "Export history" PDF downloads: the History screen's button (session) and the link the
 * vehicles_export_history MCP tool hands out (signed, for a browser that has no onsplan session).
 */
class VehicleExportController
{
    public function download(Request $request, Vehicle $vehicle): Response
    {
        // Route bindings resolve before the family middleware scopes queries, so check the family here.
        abort_unless((int) $vehicle->family_id === (int) CurrentFamily::id(), 404);

        return $this->pdf($request, $vehicle, app()->getLocale());
    }

    /** The signature authorizes it; the family is the car's own. Still 404s if the module was turned off. */
    public function signed(Request $request, int $vehicle): Response
    {
        $v = Vehicle::withoutGlobalScope('family')->findOrFail($vehicle);
        abort_unless(app(ModuleRegistry::class)->isEnabled('vehicles', (int) $v->family_id), 404);
        CurrentFamily::set((int) $v->family_id); // the export reads family-scoped relations + settings
        $lang = array_key_exists((string) $request->query('lang'), Translations::LOCALES) ? (string) $request->query('lang') : 'en';

        return $this->pdf($request, $v, $lang);
    }

    /** A signed download link for the MCP tool: valid for a day, in the asking person's language. */
    public static function signedUrl(Vehicle $v, string $lang): string
    {
        return URL::temporarySignedRoute('vehicles.history.pdf.signed', now()->addDay(), ['vehicle' => $v->id, 'lang' => $lang]);
    }

    private function pdf(Request $request, Vehicle $v, string $lang): Response
    {
        // Numbers and dates follow the reader's browser locale (like the app, which formats with Intl in
        // the browser): "408,53 lei" for ro-RO. Labels follow the person's app language.
        $numberLocale = $request->getPreferredLanguage() ?: $lang;

        return response(VehicleHistoryExport::pdf($v, $lang, $numberLocale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.VehicleHistoryExport::filename($v).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
