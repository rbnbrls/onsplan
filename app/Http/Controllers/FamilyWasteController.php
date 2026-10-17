<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\FamilyWasteSettings;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Waste\WasteCollectionSyncService;
use App\Support\Waste\WasteProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FamilyWasteController extends Controller
{
    public function index(WasteProviderRegistry $providers): Response
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);
        $settings = FamilyWasteSettings::firstOrCreate(['family_id' => $family->id], ['country' => 'NL']);
        return Inertia::render('settings/household', [
            'settings' => $settings->only(['enabled', 'postal_code', 'house_number', 'house_number_suffix', 'street_name', 'collector', 'collector_detection_status', 'selected_waste_types', 'task_offset_days', 'task_time', 'sync_horizon_months', 'status', 'last_synced_at', 'last_error']),
            'providers' => $providers->options(),
            'wasteTypes' => collect($settings->selected_waste_types ?? [])->map(fn ($type) => ['id' => $type, 'label' => mb_convert_case(str_replace(['_', '-'], ' ', $type), MB_CASE_TITLE, 'UTF-8')])->values(),
            'canManage' => true,
        ]);
    }

    public function update(Request $request, WasteProviderRegistry $providers, WasteCollectionSyncService $sync): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);
        $data = $request->validate([
            'postal_code' => ['required', 'string', 'regex:/^\d{4}\s?[A-Za-z]{2}$/'],
            'house_number' => ['required', 'string', 'regex:/^\d+$/', 'max:20'],
            'house_number_suffix' => ['nullable', 'string', 'max:20'],
            'street_name' => ['nullable', 'string', 'max:255'],
            'collector' => ['nullable', Rule::in(array_keys(WasteProviderRegistry::PROVIDERS))],
            'selected_waste_types' => ['nullable', 'array'],
            'selected_waste_types.*' => ['string', 'max:80'],
            'task_offset_days' => ['required', 'integer', 'min:0', 'max:7'],
            'task_time' => ['required', 'date_format:H:i'],
            'sync_horizon_months' => ['required', 'integer', 'min:1', 'max:12'],
        ]);
        $data['postal_code'] = strtoupper(str_replace(' ', '', $data['postal_code']));
        $settings = FamilyWasteSettings::updateOrCreate(['family_id' => $family->id], $data + ['enabled' => true, 'status' => 'unconfigured']);
        if (! $settings->collector) {
            $settings->update(['collector_detection_status' => 'detecting']);
            $detected = $providers->detect($settings);
            $settings->update(['collector' => $detected, 'collector_detection_status' => $detected ? 'detected' : 'manual_required']);
        }
        if (! $settings->collector) return back()->with('waste_error', 'Kies een afvalinzamelaar om de synchronisatie te starten.');
        try { $sync->sync($settings->fresh(), $request->user()); }
        catch (\Throwable $e) { $settings->update(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 1000)]); Log::warning('waste.sync.failed', ['family_id' => $family->id, 'collector' => $settings->collector]); }
        return back();
    }

    public function sync(Request $request, WasteCollectionSyncService $sync): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);
        $settings = FamilyWasteSettings::firstOrFail();
        try { $sync->sync($settings, $request->user()); }
        catch (\Throwable $e) { $settings->update(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 1000)]); }
        return back();
    }
}
