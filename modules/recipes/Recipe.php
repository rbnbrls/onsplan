<?php

namespace Modules\Recipes;

use App\Models\User;
use App\Support\Guests\GuestShareable;
use App\Support\Guests\ProvidesGuestView;
use App\Support\Media\StoredImage;
use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Modules\Shopping\ShoppingList;

/**
 * A family recipe: photo, ingredients (JSON, see the migration), steps, and the reference amount the
 * calculator scales from (servings and/or a total yield like 1200 g). Shared like other family things
 * (Shareable: family / invited / private) and publicly through the core guest link (GuestShareable: an
 * unguessable /g/{token} URL, optionally password-gated), which shows a read-only page with the calculator.
 */
class Recipe extends Model implements ProvidesGuestView
{
    use BelongsToFamily;
    use GuestShareable;
    use Shareable;
    use SoftDeletes; // 30-day trash (RecipesTrash, trash:purge)

    protected $table = 'recipes_recipes';

    protected $fillable = [
        'title', 'description', 'photo_path', 'servings', 'yield_amount', 'yield_unit', 'prep_minutes', 'cook_minutes',
        'ingredients', 'steps', 'tags', 'source_url', 'notes', 'created_by',
    ];

    protected $casts = [
        'ingredients' => 'array',
        'steps' => 'array',
        'tags' => 'array',
        'servings' => 'integer',
        'yield_amount' => 'float',
        'prep_minutes' => 'integer',
        'cook_minutes' => 'integer',
    ];

    protected $attributes = ['ingredients' => '[]', 'steps' => '[]', 'tags' => '[]'];

    protected static function booted(): void
    {
        // A trashed recipe keeps its photo (it can be restored); the file goes when the trash is purged.
        static::forceDeleting(fn (self $r) => StoredImage::delete($r->photo_path));
    }

    /** Validation shared by the web editor and the MCP tools (field names = columns). */
    public static function rules(bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'servings' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'yield_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:1000000', 'required_with:yield_unit'],
            'yield_unit' => ['sometimes', 'nullable', 'string', 'max:20', 'required_with:yield_amount'],
            'prep_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'cook_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'ingredients' => ['sometimes', 'array', 'max:200'],
            'ingredients.*.name' => ['required', 'string', 'max:255'],
            'ingredients.*.qty' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:20'],
            'ingredients.*.group' => ['nullable', 'string', 'max:100'],
            'ingredients.*.note' => ['nullable', 'string', 'max:255'],
            'ingredients.*.scalable' => ['nullable', 'boolean'],
            'steps' => ['sometimes', 'array', 'max:100'],
            'steps.*' => ['nullable', 'string', 'max:5000'], // blanks arrive as null (ConvertEmptyStringsToNull); clean() drops them
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['nullable', 'string', 'max:40'],
            'source_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * Validated input -> stored shape: ingredients keep only known keys (qty as a number, `scalable` true
     * unless said otherwise), blank steps and tags dropped, tags trimmed + de-duplicated.
     */
    public static function clean(array $data): array
    {
        if (array_key_exists('ingredients', $data)) {
            $data['ingredients'] = array_values(array_map(fn (array $i) => [
                'group' => filled($i['group'] ?? null) ? trim($i['group']) : null,
                'name' => trim($i['name']),
                'qty' => isset($i['qty']) && $i['qty'] !== '' ? (float) $i['qty'] : null,
                'unit' => filled($i['unit'] ?? null) ? trim($i['unit']) : null,
                'note' => filled($i['note'] ?? null) ? trim($i['note']) : null,
                'scalable' => (bool) ($i['scalable'] ?? true),
            ], $data['ingredients']));
        }
        if (array_key_exists('steps', $data)) {
            $data['steps'] = array_values(array_filter(array_map(fn ($s) => trim((string) $s), $data['steps']), 'strlen'));
        }
        if (array_key_exists('tags', $data)) {
            $data['tags'] = array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), $data['tags']), 'strlen')));
        }

        return $data;
    }

    /** Create a recipe owned by $user (owner share; `family` visibility by default). Web + MCP. */
    public static function createFor(User $user, array $data): self
    {
        $recipe = static::create(self::clean($data) + ['created_by' => $user->id]);
        $recipe->shares()->create(['user_id' => $user->id, 'role' => 'owner']);

        return $recipe;
    }

    /** Store a new photo (and its client-made thumbnail when there is one), replacing the old one. */
    public function setPhoto(UploadedFile $photo, ?UploadedFile $thumb = null): void
    {
        StoredImage::delete($this->photo_path);
        $this->update(['photo_path' => StoredImage::store($photo, $thumb, 'recipes/'.$this->family_id)]);
    }

    public function removePhoto(): void
    {
        StoredImage::delete($this->photo_path);
        $this->update(['photo_path' => null]);
    }

    /** For the app (session-authenticated route). */
    public function photoUrl(bool $thumb = false): ?string
    {
        return $this->photo_path ? StoredImage::url('recipes.photo', $this, $this->photo_path, $thumb) : null;
    }

    /**
     * For places with no onsplan session: the guest page and the MCP widget (rendered by Claude /
     * ChatGPT in their own sandbox). A signed URL that works until the end of tomorrow; the expiry is
     * rounded to a day so the URL stays the same, and cacheable, for a whole day.
     */
    public function signedPhotoUrl(bool $thumb = false): ?string
    {
        return $this->photo_path ? URL::temporarySignedRoute('recipes.photo.signed', now()->addDays(2)->startOfDay(), array_filter([
            'recipe' => $this->id,
            'v' => $thumb ? 'thumb' : null,
            'k' => substr(md5($this->photo_path), 0, 8),
        ])) : null;
    }

    /** The calculator's view of the recipe (camelCase, as recipe-scale.js / RecipeScale take it). */
    public function scaleInput(): array
    {
        return [
            'servings' => $this->servings,
            'yieldAmount' => $this->yield_amount,
            'yieldUnit' => $this->yield_unit,
            'ingredients' => $this->ingredients ?? [],
        ];
    }

    /** Shape for the web pages and the guest page. */
    public function toPayload(bool $signedPhotos = false): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'photoUrl' => $signedPhotos ? $this->signedPhotoUrl() : $this->photoUrl(),
            'thumbUrl' => $signedPhotos ? $this->signedPhotoUrl(true) : $this->photoUrl(true),
            'servings' => $this->servings,
            'yieldAmount' => $this->yield_amount,
            'yieldUnit' => $this->yield_unit,
            'prepMinutes' => $this->prep_minutes,
            'cookMinutes' => $this->cook_minutes,
            'ingredients' => $this->ingredients ?? [],
            'steps' => $this->steps ?? [],
            'tags' => $this->tags ?? [],
            'sourceUrl' => $this->source_url,
            'notes' => $this->notes,
            'visibility' => $this->visibility(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Read-only page behind the guest link: the recipe + calculator, photos via signed URLs. */
    public function guestView(): array
    {
        return ['component' => 'recipes/guest', 'props' => ['recipe' => $this->toPayload(signedPhotos: true)]];
    }

    /**
     * Put the ingredients on a shopping list at the given scale: one item per ingredient with the scaled
     * amount as its quantity ("400 g"), to-taste ones skipped. $only limits it to those ingredient indexes
     * (the person unticks what they already have). Returns the item names added.
     *
     * @param  list<int>|null  $only
     * @return list<string>
     */
    public function addToShopping(User $by, ShoppingList $list, float $factor = 1, ?array $only = null): array
    {
        $items = [];
        foreach ($this->ingredients ?? [] as $i => $ing) {
            if (($only !== null && ! in_array($i, $only, true)) || ($ing['qty'] ?? null) === null) {
                continue;
            }
            $s = RecipeScale::ingredient($ing, $factor);
            $qty = rtrim(rtrim(number_format((float) $s['qty'], 2, '.', ''), '0'), '.');
            $items[] = [
                'name' => $ing['name'],
                'quantity' => trim($qty.' '.($s['unit'] ?? '')),
                'note' => $this->title,
            ];
        }
        if ($items !== []) {
            $list->addItems($by, $items);
        }

        return array_column($items, 'name');
    }
}
