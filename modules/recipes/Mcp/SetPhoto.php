<?php

namespace Modules\Recipes\Mcp;

use App\Mcp\Tool;
use App\Support\Mcp\IncomingFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsDestructive]
#[IsOpenWorld]
class SetPhoto extends Tool
{
    protected string $module = 'recipes';

    protected string $access = 'write';

    protected string $name = 'recipes_set_photo';

    protected string $description = 'Set or replace a recipe\'s photo (JPEG, PNG or WebP, up to 12 MB): in ChatGPT the image the person attached (`file`); elsewhere a public https image URL (`file_url`) or `file_base64` + `file_name`. Claude cannot pass an image attached in the chat: give the person the recipe link to add it in onsplan instead.';

    public function __construct()
    {
        $this->setMeta(IncomingFile::FILE_PARAMS_META);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['recipe_id' => $schema->integer()->required(), ...IncomingFile::schema($schema)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $recipe = RecipeData::recipe($this->user(), $request->get('recipe_id'));
        if (! $recipe) {
            return $this->notFound('Recipe');
        }
        $photo = IncomingFile::from($request, RecipeData::PHOTO_TYPES);
        if (! $photo) {
            return Response::error('No image given: pass file, file_url, or file_base64 + file_name.');
        }
        // No thumbnail here (the app makes those in the browser); StoredImage serves the full image instead.
        $recipe->setPhoto($photo);

        return Response::structured(['recipe_id' => $recipe->id, 'photo_url' => $recipe->fresh()->signedPhotoUrl(), 'url' => RecipeData::url("/m/recipes/{$recipe->id}")]);
    }
}
