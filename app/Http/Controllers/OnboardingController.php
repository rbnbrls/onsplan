<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Support\Analytics;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    // Once a user belongs to a family, onboarding is done: bounce them to the app instead of
    // letting them create a second family through the first-run flow.
    private function redirectIfOnboarded(Request $request): ?RedirectResponse
    {
        return $request->user()->families()->exists()
            ? to_route('dashboard')
            : null;
    }

    // "How would you like to begin?" — the first screen after registration.
    public function index(Request $request): RedirectResponse|Response
    {
        return $this->redirectIfOnboarded($request)
            ?? Inertia::render('onboarding/welcome', [
                'firstName' => str($request->user()->name)->before(' ')->toString(),
            ]);
    }

    // The "Create a family" form (name, description, color + emoji).
    public function create(Request $request): RedirectResponse|Response
    {
        return $this->redirectIfOnboarded($request)
            ?? Inertia::render('onboarding/create-family');
    }

    public function store(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectIfOnboarded($request)) {
            return $redirect;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            // Constrained to the palette/emoji sets the picker offers, so a crafted request can't
            // store arbitrary values that would later render as raw text in the UI.
            'color' => ['required', 'string', 'in:'.implode(',', Family::COLORS)],
            'emoji' => ['required', 'string', 'in:'.implode(',', Family::EMOJIS)],
        ]);

        $user = $request->user();

        $family = Family::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'color' => $data['color'],
            'emoji' => $data['emoji'],
            'owner_id' => $user->id,
        ]);
        $family->members()->attach($user, ['role' => 'admin']);
        app(ModuleRegistry::class)->enableDefaults($family->id);
        // Modules set up their per-family defaults here (e.g. Spending's currency from where the person
        // signs up), with the request for context. Core doesn't know which modules care.
        app(Hooks::class)->fire('family.created', $family, $request);
        Analytics::track($user, 'family_created', [], '/onboarding');

        // Make it the active tenant immediately (SetActiveFamily reads this on the next request).
        $request->session()->put('active_family_id', $family->id);

        return to_route('dashboard');
    }
}
