<?php

namespace Modules\Recipes\Mcp;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Modules\Recipes\Recipe;
use Modules\Recipes\RecipeScale;
use Modules\Shopping\ShoppingList;

// Shared lookups + payload shapes for the Recipes MCP tools. Access follows the web UI: anyone with a
// role on a recipe (ShareAccess, family visibility by default) can read and edit it, only its creator
// deletes it, and the family scope comes from CurrentFamily.
class RecipeData
{
    /** Image types a recipe photo may be (no HEIC: browsers, and so the widget, can't show it). */
    public const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function recipe(User $user, mixed $id): ?Recipe
    {
        $recipe = Recipe::find((int) $id);

        return $recipe && app(ShareAccess::class)->roleFor($user, $recipe) !== null ? $recipe : null;
    }

    /** Short shape for lists. Photos are signed URLs: the widget's sandbox has no onsplan session. */
    public static function summary(Recipe $r): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'thumb_url' => $r->signedPhotoUrl(true),
            'tags' => $r->tags ?? [],
            'servings' => $r->servings,
            'total_minutes' => ($r->prep_minutes || $r->cook_minutes) ? (int) $r->prep_minutes + (int) $r->cook_minutes : null,
            'url' => self::url("/m/recipes/{$r->id}"),
        ];
    }

    public static function payload(Recipe $r): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'description' => $r->description,
            'photo_url' => $r->signedPhotoUrl(),
            'servings' => $r->servings,
            'yield_amount' => $r->yield_amount,
            'yield_unit' => $r->yield_unit,
            'prep_minutes' => $r->prep_minutes,
            'cook_minutes' => $r->cook_minutes,
            'ingredients' => $r->ingredients ?? [],   // [{group, name, qty, unit, note, scalable}]; qty null = to taste
            'steps' => $r->steps ?? [],
            'tags' => $r->tags ?? [],
            'source_url' => $r->source_url,
            'notes' => $r->notes,
            'visibility' => $r->visibility(),
            'url' => self::url("/m/recipes/{$r->id}"),
        ];
    }

    /** Tool parameters for creating/changing a recipe. Ingredients and steps replace the whole list. */
    public static function fieldSchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string(),
            'description' => $schema->string()->nullable(),
            'servings' => $schema->integer()->nullable()->description('How many people the amounts are for.'),
            'yield_amount' => $schema->number()->nullable()->description('Total amount the recipe makes, e.g. 1200 (with yield_unit "g"). Lets the person scale by "I want 800 g".'),
            'yield_unit' => $schema->string()->nullable()->description('Unit of yield_amount: g, kg, ml, l, pcs...'),
            'prep_minutes' => $schema->integer()->nullable(),
            'cook_minutes' => $schema->integer()->nullable(),
            'ingredients' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'qty' => $schema->number()->nullable()->description('Omit/null for "to taste".'),
                'unit' => $schema->string()->nullable()->description('g, kg, ml, l, tsp, tbsp, cup, pcs, pinch, clove... (metric units and spoons convert when scaling).'),
                'group' => $schema->string()->nullable()->description('Section, e.g. "Dough", "Filling".'),
                'note' => $schema->string()->nullable()->description('e.g. "room temperature", "finely chopped".'),
                'scalable' => $schema->boolean()->description('false = never scaled (e.g. 1 bay leaf). Default true.'),
            ]))->description('The full ingredient list, in order (replaces the existing list).'),
            'steps' => $schema->array()->items($schema->string())->description('The method, one entry per step, in order (replaces the existing steps).'),
            'tags' => $schema->array()->items($schema->string())->description('e.g. ["dessert", "vegetarian"].'),
            'source_url' => $schema->string()->nullable()->description('Where the recipe came from.'),
            'notes' => $schema->string()->nullable(),
        ];
    }

    /** Schema for "scale to...": servings, a target yield, or what you have of one ingredient. */
    public static function scaleSchema(JsonSchema $schema): array
    {
        return [
            'servings' => $schema->number()->description('Scale to this many servings.'),
            'yield_amount' => $schema->number()->description('Scale to make this much in total (with yield_unit), e.g. 800 g.'),
            'yield_unit' => $schema->string()->description('Unit for yield_amount (default: the recipe\'s yield unit).'),
            'have_ingredient' => $schema->string()->description('Scale to what you have of one ingredient: its name (or part of it), e.g. "flour".'),
            'have_amount' => $schema->number()->description('How much of have_ingredient you have.'),
            'have_unit' => $schema->string()->description('Unit for have_amount (default: the ingredient\'s unit); g/kg, ml/l and spoons convert.'),
        ];
    }

    /**
     * The scale target from the tool arguments, as RecipeScale takes it, plus a human label. Null when no
     * target was given (= the recipe as written). Throws a ValidationException the assistant can act on.
     *
     * @return array{target: array, label: string}|null
     */
    public static function target(Recipe $r, array $args): ?array
    {
        if (isset($args['servings'])) {
            return ['target' => ['type' => 'servings', 'value' => (float) $args['servings']], 'label' => "{$args['servings']} servings"];
        }
        if (isset($args['yield_amount'])) {
            $unit = $args['yield_unit'] ?? $r->yield_unit;

            return ['target' => ['type' => 'yield', 'amount' => (float) $args['yield_amount'], 'unit' => $unit], 'label' => trim("{$args['yield_amount']} {$unit}")];
        }
        if (isset($args['have_ingredient'], $args['have_amount'])) {
            $needle = mb_strtolower(trim($args['have_ingredient']));
            // Exact name first, else the first ingredient containing it ("flour" -> "flour (type 000)").
            $ings = collect($r->ingredients ?? []);
            $index = $ings->search(fn ($i) => mb_strtolower($i['name']) === $needle);
            if ($index === false) {
                $index = $ings->search(fn ($i) => str_contains(mb_strtolower($i['name']), $needle));
            }
            if ($index === false) {
                throw ValidationException::withMessages(['have_ingredient' => "This recipe has no ingredient called \"{$args['have_ingredient']}\"."]);
            }
            $unit = $args['have_unit'] ?? ($r->ingredients[$index]['unit'] ?? null);

            return [
                'target' => ['type' => 'ingredient', 'index' => $index, 'amount' => (float) $args['have_amount'], 'unit' => $unit],
                'label' => trim("{$args['have_amount']} {$unit}").' of '.$r->ingredients[$index]['name'],
            ];
        }

        return null;
    }

    /**
     * Factor + scaled ingredients for a target (or as written). A target that can't be computed (no
     * servings on the recipe, units that don't convert, a to-taste ingredient) is explained, not guessed.
     */
    public static function scaled(Recipe $r, array $args): array
    {
        $t = self::target($r, $args);
        $factor = $t ? RecipeScale::factor($r->scaleInput(), $t['target']) : 1.0;
        if ($factor === null) {
            throw ValidationException::withMessages(['scale' => "Can't scale \"{$r->title}\" to {$t['label']}: ".match ($t['target']['type']) {
                'servings' => 'the recipe has no servings set.',
                'yield' => 'the recipe has no total yield set, or the units don\'t convert ('.($r->yield_unit ?? 'none').').',
                default => 'that ingredient has no amount, or the units don\'t convert.',
            }]);
        }

        return [
            'factor' => round($factor, 4),
            'scaled_to' => $t['label'] ?? 'as written',
            'target' => $t['target'] ?? null,
            'ingredients' => array_map(function (array $ing) use ($factor) {
                $s = RecipeScale::ingredient($ing, $factor);

                return $ing + ['scaled_qty' => $s['qty'], 'scaled_unit' => $s['unit'], 'line' => RecipeScale::line($ing, $s)];
            }, $r->ingredients ?? []),
        ];
    }

    /** Shopping lists the widget can offer ("add to shopping"), or null when that isn't possible here. */
    public static function shoppingLists(User $user): ?array
    {
        if (! self::shoppingWritable($user)) {
            return null;
        }

        return ShoppingList::query()->visibleTo($user->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->values()->all();
    }

    /** Writing to Shopping needs the module on AND this connection allowed to change Shopping. */
    public static function shoppingWritable(User $user): bool
    {
        return app(ModuleRegistry::class)->isEnabled('shopping', (int) CurrentFamily::id()) && $user->tokenCan('shopping:write');
    }

    public static function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
