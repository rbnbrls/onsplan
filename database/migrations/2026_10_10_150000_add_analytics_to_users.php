<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Per-user analytics (hosted onsplan.nl only, see App\Support\Analytics):
//   - analytics_id: a random UUID that is the person's ONLY identity in Umami (never the user id, name or
//     email). A new user gets one on create (User::booted), or adopts the onsplan.nl visitor id they
//     arrived with, so pre-signup visits on the marketing site and in-app use join up.
//   - first_touch: where they came from before signing up ({source, medium, campaign, referrer,
//     landing_path, at}), for the admin's user page.
return new class extends Migration
{
    // Not one transaction, so the backfill is resumable: if it dies halfway, the next `migrate` skips the
    // column that already exists and fills only the rows still null (see the guards below).
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'analytics_id')) {
            Schema::table('users', function (Blueprint $table) {
                // Nullable first: existing rows need a value before NOT NULL can apply.
                $table->uuid('analytics_id')->nullable();
                $table->json('first_touch')->nullable();
            });
        }

        // Chunks of 500, re-querying the nulls each round (not offset paging, which skips rows as they fill).
        // ponytail: one UPDATE per row, fine for our user count; a single UPDATE ... gen_random_uuid() is the
        // upgrade if the table ever gets big (Postgres only, so not portable to the SQLite test DB).
        do {
            $ids = DB::table('users')->whereNull('analytics_id')->orderBy('id')->limit(500)->pluck('id');
            foreach ($ids as $id) {
                DB::table('users')->where('id', $id)->whereNull('analytics_id')->update(['analytics_id' => (string) Str::uuid()]);
            }
        } while ($ids->isNotEmpty());

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('analytics_id')->nullable(false)->change();
            $table->unique('analytics_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['analytics_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['analytics_id', 'first_touch']);
        });
    }
};
