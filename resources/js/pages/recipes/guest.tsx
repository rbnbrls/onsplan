import { Head } from '@inertiajs/react';

import { RecipeHeader, RecipeView } from '@/components/recipes/recipe-view';
import { type Recipe } from '@/lib/recipes';

// The public page behind a recipe's guest link (/g/{token}, optionally password-gated by the core guest
// system). Read-only, no app shell, no account: the recipe with the full calculator. The photo comes
// from a signed URL (the visitor has no session).
export default function RecipeGuest({ recipe }: { recipe: Recipe }) {
    return (
        <div className="bg-background min-h-screen">
            <Head title={`${recipe.title} · onsplan`} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                <div className="flex items-center gap-2 pt-2">
                    <span className="bg-tile text-tile-foreground flex size-7 items-center justify-center rounded-lg text-xs font-bold">FW</span>
                    <span className="text-muted-foreground text-sm">onsplan · shared with you</span>
                </div>
                <RecipeHeader recipe={recipe} />
                <RecipeView recipe={recipe} />
            </div>
        </div>
    );
}
