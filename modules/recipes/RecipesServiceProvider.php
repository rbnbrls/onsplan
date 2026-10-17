<?php

namespace Modules\Recipes;

use App\Models\EntityVisit;
use App\Support\Dashboard\DashboardItems;
use App\Support\Engagement;
use App\Support\Guests\GuestableRegistry;
use App\Support\Mcp\McpTools;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Trash\Trash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RecipesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Adding a Recipe counts as using this module (paywall timing + the admin's progress bar).
        Engagement::track('recipes', Recipe::class);

        // MCP: recipes for AI assistants, gated by the `recipes:read` / `recipes:write` scopes.
        McpTools::register('recipes', 'Recipes', [
            Mcp\ListRecipes::class, Mcp\GetRecipe::class, Mcp\ScaleRecipe::class,
            Mcp\CreateRecipe::class, Mcp\UpdateRecipe::class, Mcp\SetPhoto::class,
            Mcp\AddToShopping::class, Mcp\DeleteRecipe::class,
        ]);

        // 30-day trash, the core sharing system (`recipe` in share URLs) and the public guest link.
        Trash::source('recipes', RecipesTrash::class);
        Trash::register(Recipe::class);
        ShareableRegistry::register('recipe', Recipe::class);
        GuestableRegistry::register('recipe', Recipe::class);

        // "Pick up where you left off": recipes opened recently, visibility respected.
        DashboardItems::register('recipes', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('recipes', $familyId)) {
                return [];
            }
            $visits = EntityVisit::recentMap('recipe');
            if ($visits->isEmpty()) {
                return [];
            }

            return Recipe::query()->visibleTo($userId)->whereIn('id', $visits->keys())->get()
                ->map(fn (Recipe $r) => [
                    'title' => $r->title,
                    'subtitle' => 'Recipes',
                    'href' => "/m/recipes/{$r->id}",
                    'category' => 'recipes',
                    'icon' => 'chef-hat',
                    'visitedAt' => $visits[$r->id]->toIso8601String(),
                ])->values()->all();
        });

        // The photo behind a signed URL, for viewers with no onsplan session: the guest page and the
        // MCP widget inside Claude/ChatGPT. The signature authorizes it; no family or module gate needed.
        Route::middleware(['web', 'signed', 'throttle:120,1'])
            ->get('/recipes/photo/{recipe}', [RecipesController::class, 'signedPhoto'])
            ->whereNumber('recipe')
            ->name('recipes.photo.signed');

        Route::middleware(['web', 'auth', 'family', 'module:recipes'])
            ->prefix('m/recipes')
            ->name('recipes.')
            ->group(function () {
                Route::get('/', [RecipesController::class, 'index'])->name('index');
                Route::get('/new', [RecipesController::class, 'create'])->name('create');
                Route::post('/', [RecipesController::class, 'store'])->name('store');
                Route::get('/{recipe}', [RecipesController::class, 'show'])->name('show');
                Route::get('/{recipe}/edit', [RecipesController::class, 'edit'])->name('edit');
                Route::patch('/{recipe}', [RecipesController::class, 'update'])->name('update');
                Route::delete('/{recipe}', [RecipesController::class, 'destroy'])->name('destroy');
                Route::post('/{recipe}/photo', [RecipesController::class, 'uploadPhoto'])->name('photo.upload');
                Route::get('/{recipe}/photo', [RecipesController::class, 'photo'])->name('photo');
                Route::delete('/{recipe}/photo', [RecipesController::class, 'deletePhoto'])->name('photo.delete');
            });
    }
}
