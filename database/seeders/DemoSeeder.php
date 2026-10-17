<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\User;
use App\Support\Demo\DemoFamily;
use App\Support\Media\StoredImage;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Calendar\CalendarEvent;
use Modules\Contacts\Person;
use Modules\ExpenseImport\ImportBatch;
use Modules\ExpenseImport\Importer;
use Modules\ExpenseImport\ImportSetting;
use Modules\Inventory\InventoryCategory;
use Modules\Inventory\InventoryItem;
use Modules\Inventory\InventoryLocation;
use Modules\Inventory\InventorySetting;
use Modules\Notes\Folder;
use Modules\Notes\Note;
use Modules\Projects\Project;
use Modules\Projects\ProjectLink;
use Modules\Recipes\Recipe;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;
use Modules\Shopping\ShoppingShop;
use Modules\Spending\Spend;
use Modules\Spending\SpendCategory;
use Modules\Spending\SpendEvent;
use Modules\Spending\SpendSetting;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleSetting;

/**
 * The public demo family ("The Millers", a US household of 2 parents + 3 kids), filled across every
 * module. Idempotent and deterministic: running it again wipes the demo family's data (DemoFamily::wipe)
 * and rebuilds exactly the same rows, so the demo looks identical after every hourly `demo:reset`.
 * Dates are relative to today (so "this month" and "due" always mean something), which is the only
 * input — no randomness anywhere.
 *
 * First run creates the family (flagged is_demo) and its member users; later runs refresh them in place
 * (same family id, same user ids), so visitor sessions and the /demo entry keep working.
 */
class DemoSeeder extends Seeder
{
    public const FAMILY_NAME = 'The Millers';

    /** The account /demo signs visitors in as (the family owner). */
    public const OWNER_EMAIL = 'demo@onsplan.nl';

    /** @var list<array{name:string,email:string,role:string,birthday:string}> */
    public const MEMBERS = [
        ['name' => 'Sarah Miller', 'email' => self::OWNER_EMAIL, 'role' => 'admin', 'birthday' => '1984-03-14'],
        ['name' => 'David Miller', 'email' => 'demo-david@onsplan.nl', 'role' => 'admin', 'birthday' => '1982-07-22'],
        ['name' => 'Emma Miller', 'email' => 'demo-emma@onsplan.nl', 'role' => 'member', 'birthday' => '2012-05-09'],
        ['name' => 'Noah Miller', 'email' => 'demo-noah@onsplan.nl', 'role' => 'member', 'birthday' => '2016-10-30'],
        // A leap-day kid: guarantees Feb 29 is a birthday even before the generated contacts.
        ['name' => 'Lily Miller', 'email' => 'demo-lily@onsplan.nl', 'role' => 'member', 'birthday' => '2020-02-29'],
    ];

    public const MODULES = [
        'shopping', 'spending', 'inventory', 'vehicles', 'tasks', 'notes', 'contacts', 'birthdays',
        'projects', 'notifications', 'important_todos', 'recurring_todos', 'calendar', 'recipes', 'expense_import',
    ];

    private Carbon $today;

    /** @var array<string, User> keyed by first name */
    private array $u = [];

    public function run(): void
    {
        $this->today = today();

        // The ONLY way the demo family is found. Throws on 2+ flagged rows; null means first run.
        $family = DemoFamily::locate();

        DB::transaction(function () use ($family) {
            $family = $this->family($family);
            CurrentFamily::set($family->id); // BelongsToFamily fills family_id / scopes queries below

            $this->modules($family);
            $this->shopping();
            $this->spending();
            $this->expenseImport();
            $this->inventory($family);
            $this->vehicles($family);
            $this->tasks();
            $this->notes();
            $this->projects();
            $this->calendar();
            $this->recipes();
            $this->people();
        });

        CurrentFamily::forget();
    }

    // ---- Family + members ----------------------------------------------------------------------------

    private function family(?Family $family): Family
    {
        // Members are matched by email so their ids survive a reset (sessions, shares, /demo all keep
        // working). Passwords are random (the only way in is the /demo route), except the owner's when
        // onsplan.demo_password is set: that's the app-directory reviewers' login.
        foreach (self::MEMBERS as $m) {
            $this->u[Str::before($m['name'], ' ')] = User::updateOrCreate(['email' => $m['email']], [
                'name' => $m['name'],
                'password' => ($m['email'] === self::OWNER_EMAIL ? config('onsplan.demo_password') : null) ?: Str::random(40),
                'email_verified_at' => $this->today,
                'locale' => 'en',
                'theme' => null,
                'notification_prefs' => null,
            ]);
        }
        $owner = $this->u['Sarah'];

        if (! $family) {
            $family = new Family;
        } else {
            // Refresh: drop everything the family owns (scoped to its id, flag re-checked inside).
            DemoFamily::wipe($family);
        }

        $family->forceFill([
            'name' => self::FAMILY_NAME,
            'description' => 'Public demo household. Poke around, change anything: it resets every night.',
            'color' => Family::COLORS[0],
            'emoji' => '🏡',
            'theme' => null,
            'owner_id' => $owner->id,
            'is_demo' => true,
        ])->save();

        $family->members()->sync(
            collect(self::MEMBERS)->mapWithKeys(fn ($m) => [$this->u[Str::before($m['name'], ' ')]->id => ['role' => $m['role']]])->all()
        );

        // Per-user residue from the previous day's visitors (not family-scoped, so not in wipe()).
        $ids = collect($this->u)->pluck('id')->all();
        DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $ids)->delete();
        DB::table('entity_visits')->whereIn('user_id', $ids)->delete();
        DB::table('push_subscriptions')->where('subscribable_type', User::class)->whereIn('subscribable_id', $ids)->delete();

        return $family;
    }

    private function modules(Family $family): void
    {
        $registry = app(ModuleRegistry::class);
        $known = array_map(fn ($m) => $m->id, $registry->discover());
        foreach (array_intersect(self::MODULES, $known) as $id) {
            $registry->enable($id, $family->id);
        }
    }

    // ---- Expense import --------------------------------------------------------------------------------

    // Both ways in switched on, plus a statement waiting for review and two Apple Pay payments in Sarah's queue.
    // The statement rows show every case on the review screen: categories learned from the spends above,
    // a possible duplicate (the coffee already in Spending today), income, a transfer, a refund, an unknown shop.
    private function expenseImport(): void
    {
        $sarah = $this->u['Sarah'];
        ImportSetting::current()->update(['statements_enabled' => true, 'shortcut_enabled' => true]);
        $day = fn (int $ago) => $this->today->copy()->subDays($ago)->toDateString();
        $tx = fn (int $ago, float $usd, string $merchant, string $kind = 'expense', ?string $ref = null) => [
            'date' => $day($ago), 'amount' => (int) round($usd * 100), 'currency' => 'USD', 'merchant' => $merchant, 'reference' => $ref, 'kind' => $kind,
        ];

        $statement = ImportBatch::create(['user_id' => $sarah->id, 'source' => 'statement', 'status' => ImportBatch::REVIEW, 'bank' => 'Wise', 'filename' => 'wise-statement.csv']);
        Importer::stage($statement, [
            $tx(0, -6.45, 'Starbucks', ref: 'CARD-9001'),
            $tx(1, -63.12, "Trader Joe's", ref: 'CARD-9002'),
            $tx(2, -48.90, 'Shell', ref: 'CARD-9003'),
            $tx(3, -15.49, 'Netflix', ref: 'CARD-9004'),
            $tx(3, 3200.00, 'Paycheck', 'income', 'TRANSFER-9005'),
            $tx(4, -500.00, 'To savings', 'transfer', 'BALANCE-9006'),
            $tx(5, -27.30, 'Target', ref: 'CARD-9007'),
            $tx(5, 19.99, 'Amazon refund', 'refund', 'CARD-9008'),
        ], 'USD', $sarah);

        $queue = ImportBatch::create(['user_id' => $sarah->id, 'source' => 'shortcut', 'status' => ImportBatch::REVIEW, 'bank' => 'Apple Pay']);
        Importer::stage($queue, [$tx(0, -5.75, 'Blue Bottle Coffee'), $tx(0, -18.40, 'Uber')], 'USD', $sarah);
    }

    // ---- Shopping ------------------------------------------------------------------------------------

    private function shopping(): void
    {
        foreach ([['Costco', 'amber'], ['Target', 'red'], ["Trader Joe's", 'green'], ['Walmart', 'blue'], ['Whole Foods', 'green']] as $i => [$name, $color]) {
            ShoppingShop::create(['name' => $name, 'icon' => 'store', 'color' => $color, 'position' => $i]);
        }

        $lists = [
            ['Weekly groceries', "Trader Joe's", 'green', 'cart', 'Sarah', [
                ['Bananas', '1 bunch', 'produce', true], ['Avocados', '4', 'produce', true], ['Baby spinach', '1 bag', 'produce', false],
                ['Whole milk', '1 gal', 'dairy', true], ['Eggs', '1 dozen', 'dairy', false], ['Greek yogurt', '2', 'dairy', false],
                ['Sourdough loaf', '1', 'bakery', false], ['Chicken thighs', '2 lb', 'meat', false],
                ['Frozen mango', '1 bag', 'frozen', true], ['Sparkling water', '12-pack', 'beverages', false],
                ['Olive oil', '1', 'pantry', false], ['Peanut butter', '1 jar', 'pantry', false],
            ]],
            ['Costco run', 'Costco', 'amber', 'store', 'David', [
                ['Paper towels', '12-pack', 'household', false], ['Rotisserie chicken', '1', 'meat', false],
                ['Kirkland almonds', '1', 'pantry', true], ['Dish soap', '2', 'household', false],
                ['Blueberries', '2 lb', 'produce', false], ['String cheese', '1 box', 'dairy', true],
                ['Laundry pods', '1', 'household', false], ['Coffee beans', '2.5 lb', 'pantry', false],
            ]],
            ['Back to school', 'Target', 'blue', 'gift', 'Sarah', [
                ['Composition notebooks', '6', 'other', true], ['#2 pencils', '2 packs', 'other', true],
                ['Backpack for Lily', '1', 'other', false], ['Glue sticks', '4', 'other', true],
                ['Lunch box', '1', 'other', false], ['Crayons (24)', '2', 'other', false],
            ]],
            ['Saturday dinner party', 'Whole Foods', 'green', 'party', 'David', [
                ['Salmon fillet', '3 lb', 'meat', false], ['Asparagus', '2 bunches', 'produce', false],
                ['Lemons', '4', 'produce', false], ['Baguette', '2', 'bakery', false],
                ['Vanilla ice cream', '1', 'frozen', false], ['Sauvignon blanc', '2 bottles', 'beverages', false],
            ]],
            ['Household odds and ends', 'Walmart', 'amber', 'home', 'Sarah', [
                ['AA batteries', '1 pack', 'household', false], ['Light bulbs (60W LED)', '4', 'household', true],
                ['Trash bags', '1 box', 'household', false], ['Furnace filter 16x25', '2', 'household', false],
            ]],
        ];

        foreach ($lists as $li => [$name, $store, $color, $icon, $by, $items]) {
            $list = ShoppingList::create(['name' => $name, 'store' => $store, 'color' => $color, 'icon' => $icon, 'position' => $li, 'created_by' => $this->u[$by]->id]);
            foreach ($items as $ii => [$item, $qty, $dept, $bought]) {
                ShoppingItem::create([
                    'shopping_list_id' => $list->id, 'name' => $item, 'quantity' => $qty, 'department' => $dept,
                    'bought' => $bought, 'store' => $store, 'position' => $ii, 'created_by' => $this->u[$by]->id,
                ]);
            }
        }
    }

    // ---- Spending ------------------------------------------------------------------------------------

    private function spending(): void
    {
        SpendSetting::current()->forceFill(['default_currency' => 'EUR'])->save();
        SpendCategory::ensureDefaults();
        $sarah = $this->u['Sarah'];

        $lists = [
            'others' => SpendEvent::others($sarah),
            'groceries' => SpendEvent::createFor($sarah, ['name' => 'Groceries & household', 'currency' => 'USD', 'icon' => 'shopping-cart']),
            'kids' => SpendEvent::createFor($this->u['David'], ['name' => 'Kids & school', 'currency' => 'USD', 'icon' => 'backpack']),
            'trip' => SpendEvent::createFor($sarah, ['name' => 'Fall road trip', 'currency' => 'USD', 'icon' => 'car']),
        ];

        // [days ago, list, amount in dollars, category, shop, note, payer]. Days 0-6 always fall in the
        // current month, so the month-to-date widget and analytics never read zero.
        $spends = [
            [0, 'groceries', 142.37, 'food', "Trader Joe's", 'Weekly groceries', 'Sarah'],
            [0, 'others', 6.45, 'food', 'Starbucks', 'Coffee on the way to work', 'David'],
            [1, 'kids', 38.00, 'education', 'Target', 'Composition notebooks + pencils', 'Sarah'],
            [1, 'others', 62.10, 'transport', 'Shell', 'Gas, Camry', 'David'],
            [2, 'groceries', 287.64, 'food', 'Costco', 'Big Costco run', 'David'],
            [2, 'others', 24.99, 'activities', 'AMC Theatres', 'Movie night with Emma', 'Sarah'],
            [3, 'others', 15.00, 'health', 'CVS Pharmacy', 'Allergy meds', 'Sarah'],
            [4, 'kids', 120.00, 'activities', 'YMCA', 'Noah swim lessons (Sept)', 'David'],
            [4, 'groceries', 54.20, 'food', 'Whole Foods', 'Salmon + veg for Saturday', 'Sarah'],
            [5, 'others', 89.99, 'shopping', 'Amazon', 'Desk lamp for Emma', 'David'],
            [6, 'groceries', 31.80, 'food', 'Walmart', 'Paper towels, batteries', 'Sarah'],
            [6, 'others', 45.00, 'health', 'Dr. Patel Pediatrics', 'Lily checkup copay', 'Sarah'],
            [7, 'others', 12.50, 'food', "Domino's", 'Pizza Friday', 'David'],
            [8, 'groceries', 118.42, 'food', "Trader Joe's", 'Weekly groceries', 'Sarah'],
            [9, 'others', 58.30, 'transport', 'Costco', 'Gas, CR-V', 'David'],
            [10, 'kids', 65.00, 'education', 'Scholastic', 'Book fair', 'Sarah'],
            [11, 'others', 210.00, 'other', 'Comcast', 'Internet + cable', 'David'],
            [12, 'others', 32.75, 'activities', 'Chuck E. Cheese', "Lily's friend's party", 'Sarah'],
            [13, 'groceries', 96.18, 'food', 'Target', 'Groceries + kitchen stuff', 'David'],
            [14, 'others', 140.00, 'health', 'Aetna', 'Dental cleaning x2', 'Sarah'],
            [15, 'groceries', 133.05, 'food', "Trader Joe's", 'Weekly groceries', 'Sarah'],
            [16, 'others', 60.00, 'transport', 'Shell', 'Gas, Camry', 'David'],
            [17, 'kids', 150.00, 'activities', 'Fairfax Soccer Club', 'Emma fall soccer fee', 'David'],
            [18, 'others', 27.40, 'food', 'Chipotle', 'Lunch out', 'Sarah'],
            [20, 'groceries', 302.11, 'food', 'Costco', 'Costco + gas station snacks', 'David'],
            [21, 'others', 49.99, 'shopping', 'Home Depot', 'Furnace filters', 'David'],
            [22, 'groceries', 121.90, 'food', "Trader Joe's", 'Weekly groceries', 'Sarah'],
            [24, 'others', 18.00, 'activities', 'Regal Cinemas', 'Matinee with the kids', 'Sarah'],
            [25, 'kids', 42.00, 'education', 'Amazon', 'Science kit for Noah', 'David'],
            [27, 'others', 55.60, 'transport', 'Costco', 'Gas, CR-V', 'David'],
            [29, 'groceries', 139.77, 'food', "Trader Joe's", 'Weekly groceries', 'Sarah'],
            [31, 'others', 95.00, 'health', 'Walgreens', 'Prescriptions', 'Sarah'],
            [33, 'others', 210.00, 'other', 'Comcast', 'Internet + cable', 'David'],
            [36, 'groceries', 265.30, 'food', 'Costco', 'Costco run', 'David'],
            [38, 'others', 22.15, 'food', 'Panera Bread', 'Breakfast after church', 'Sarah'],
            [41, 'kids', 80.00, 'shopping', 'Old Navy', 'School clothes', 'Sarah'],
            [43, 'groceries', 127.48, 'food', "Trader Joe's", 'Weekly groceries', 'Sarah'],
            [45, 'others', 61.20, 'transport', 'Shell', 'Gas, Camry', 'David'],
            [48, 'trip', 189.00, 'lodging', 'Hampton Inn', 'Night in Asheville', 'David'],
            [49, 'trip', 74.32, 'food', 'Cracker Barrel', 'Dinner on the road', 'Sarah'],
            [49, 'trip', 52.00, 'transport', 'Exxon', 'Gas near Roanoke', 'David'],
            [50, 'trip', 96.00, 'activities', 'Biltmore Estate', 'Tickets', 'Sarah'],
            [50, 'trip', 189.00, 'lodging', 'Hampton Inn', 'Night in Asheville', 'David'],
            [51, 'trip', 38.90, 'food', 'Waffle House', 'Breakfast', 'David'],
            [51, 'trip', 58.40, 'transport', 'BP', 'Gas heading home', 'Sarah'],
            [55, 'groceries', 144.60, 'food', "Trader Joe's", 'Weekly groceries', 'Sarah'],
        ];

        foreach ($spends as [$ago, $list, $dollars, $category, $shop, $note, $payer]) {
            $cents = (int) round($dollars * 100);
            Spend::create([
                'spend_event_id' => $lists[$list]->id, 'payer_id' => $this->u[$payer]->id, 'created_by' => $this->u[$payer]->id,
                'amount' => $cents, 'currency' => 'USD', 'base_amount' => $cents, 'rate' => 1,
                'category' => $category, 'store' => $shop, 'note' => $note, 'spent_at' => $this->today->copy()->subDays($ago),
            ]);
        }
    }

    // ---- Inventory -----------------------------------------------------------------------------------

    private function inventory(Family $family): void
    {
        InventoryCategory::ensureDefaults($family->id);
        InventoryLocation::ensureDefaults($family->id);
        InventorySetting::current($family->id)->forceFill(['unit_system' => 'imperial'])->save();

        // [name, qty (null = not counted), unit, category, location, min, target, shop, expires in days]
        $items = [
            ['Paper towels', 1, 'packs', 'household', 'garage', 2, 6, 'Costco', null],          // low
            ['Dish soap', 0, 'bottles', 'cleaning', 'kitchen', 1, 2, 'Costco', null],            // empty
            ['Laundry pods', 4, 'pieces', 'laundry', 'other', 10, 60, 'Costco', null],           // low
            ['Toilet paper', 18, 'pieces', 'bathroom', 'bathroom', 6, 30, 'Costco', null],
            ['Coffee beans', 0.5, 'kg', 'food', 'pantry', 0.5, 2, 'Costco', null],               // low
            ['Peanut butter', 1, 'pieces', 'food', 'pantry', 1, 2, "Trader Joe's", 120],          // low
            ['Whole milk', 1, 'bottles', 'drinks', 'fridge', 1, 2, "Trader Joe's", 3],            // low + expiring
            ['Eggs', 0, 'pieces', 'food', 'fridge', 6, 24, "Trader Joe's", null],                // empty
            ['Greek yogurt', 3, 'pieces', 'food', 'fridge', 2, 6, "Trader Joe's", -2],             // expired
            ['Frozen mango', 2, 'packs', 'food', 'freezer', 1, 3, "Trader Joe's", 200],
            ['Chicken thighs', 2, 'kg', 'food', 'freezer', 1, 3, 'Costco', 90],
            ['Sparkling water', 8, 'pieces', 'drinks', 'pantry', 6, 24, 'Costco', null],
            ['Olive oil', 1, 'bottles', 'food', 'pantry', 1, 2, 'Whole Foods', 300],
            ['Pasta', 4, 'boxes', 'food', 'pantry', 2, 6, 'Walmart', 400],
            ['Rice (jasmine)', 3, 'kg', 'food', 'pantry', 1, 5, 'Costco', 500],
            ['Hand soap refill', 1, 'bottles', 'bathroom', 'bathroom', 1, 2, 'Target', null],    // low
            ['Shampoo', 2, 'bottles', 'bathroom', 'bathroom', 1, 3, 'Target', null],
            ['All-purpose cleaner', 1, 'bottles', 'cleaning', 'kitchen', 1, 2, 'Walmart', null], // low
            ['Trash bags (13 gal)', 40, 'pieces', 'household', 'garage', 20, 100, 'Costco', null],
            ['AA batteries', 6, 'pieces', 'household', 'other', 8, 24, 'Walmart', null],         // low
            ['Furnace filter 16x25', 0, 'pieces', 'household', 'garage', 1, 2, 'Home Depot', null], // empty
            ['Dog food', 1, 'packs', 'other', 'garage', 1, 2, 'Costco', null],                   // low
            ['Ketchup', null, 'bottles', 'food', 'fridge', 1, 2, 'Walmart', null],               // unknown
            ['Dishwasher tabs', 30, 'pieces', 'cleaning', 'kitchen', 10, 60, 'Costco', null],
        ];

        foreach ($items as [$name, $qty, $unit, $cat, $loc, $min, $target, $shop, $expires]) {
            InventoryItem::create([
                'name' => $name, 'quantity' => $qty, 'unit' => $unit, 'category' => $cat, 'location' => $loc,
                'min_quantity' => $min, 'target_quantity' => $target, 'shop' => $shop,
                'expires_at' => $expires === null ? null : $this->today->copy()->addDays($expires),
                'auto_replenish' => true, 'created_by' => $this->u['Sarah']->id,
            ]);
        }
    }

    // ---- Vehicles ------------------------------------------------------------------------------------

    private function vehicles(Family $family): void
    {
        VehicleSetting::current($family->id)->forceFill(['unit_system' => 'imperial'])->save();
        $mi = fn (int $miles) => (int) round($miles * 1.609344); // stored metric, displayed in miles
        $gal = fn (float $g) => round($g * 3.78541, 2);
        $sarah = $this->u['Sarah'];
        $david = $this->u['David'];

        $crv = Vehicle::create([
            'make' => 'Honda', 'model' => 'CR-V', 'year' => 2019, 'version' => 'EX-L AWD', 'fuel_type' => 'petrol',
            'transmission' => 'automatic', 'color' => 'Modern Steel', 'license_plate' => 'VA 7KL-2841',
            'vin' => '2HKRW2H85KH612345', 'purchase_date' => '2019-06-15', 'purchase_price' => 31450,
            'insurance_company' => 'State Farm', 'owner_id' => $sarah->id, 'primary_driver_id' => $sarah->id,
            'current_mileage' => $mi(62410), 'created_by' => $sarah->id, 'notes' => 'The family hauler. Roof box lives in the garage.',
            'photo_path' => $this->demoImage('vehicle-crv'),
        ]);
        $camry = Vehicle::create([
            'make' => 'Toyota', 'model' => 'Camry', 'year' => 2016, 'version' => 'SE', 'fuel_type' => 'petrol',
            'transmission' => 'automatic', 'color' => 'Blue Crush Metallic', 'license_plate' => 'VA 3XG-9027',
            'vin' => '4T1BF1FK6GU123456', 'purchase_date' => '2018-03-02', 'purchase_price' => 17900,
            'insurance_company' => 'State Farm', 'owner_id' => $david->id, 'primary_driver_id' => $david->id,
            'current_mileage' => $mi(98230), 'created_by' => $david->id, 'notes' => "David's commuter.",
            'photo_path' => $this->demoImage('vehicle-camry'),
        ]);

        foreach ([[$crv, 62410, $sarah], [$camry, 98230, $david]] as [$v, $miles, $by]) {
            $v->mileageLogs()->create(['mileage' => $mi($miles - 600), 'recorded_by' => $by->id, 'recorded_at' => $this->today->copy()->subDays(30)]);
            $v->mileageLogs()->create(['mileage' => $mi($miles), 'recorded_by' => $by->id, 'recorded_at' => $this->today->copy()->subDays(2)]);
        }

        // [vehicle, name, template type, km interval (miles), months, last miles, last months ago, cost]
        // Mix of overdue / due soon / fine so the maintenance screen has something to say.
        $schedules = [
            [$crv, 'Oil change', 'oil_change', 5000, 6, 56200, 8, 79.95],        // overdue (miles + time)
            [$crv, 'Cabin air filter', 'cabin_filter', 15000, 12, 48900, 15, 34.00], // overdue (time)
            [$crv, 'Tire rotation', 'tires', 6000, null, 57100, 5, 25.00],          // due soon
            [$crv, 'Brake inspection', 'brake_inspection', 12000, 12, 58800, 4, 0], // fine
            [$crv, 'Wiper blades', 'wipers', null, 12, 51000, 11, 42.50],           // due soon
            [$camry, 'Oil change', 'oil_change', 5000, 6, 96100, 2, 74.99],         // fine
            [$camry, 'Tires', 'tires', 50000, null, 49200, 50, 612.00],             // due (miles)
            [$camry, 'Battery', 'battery', null, 48, 62000, 50, 189.00],            // overdue (time)
            [$camry, 'Transmission fluid', 'transmission_fluid', 40000, 48, 60200, 40, 160.00], // fine
            [$camry, 'Coolant flush', 'coolant', 40000, 36, 58000, 46, 129.00],     // overdue (time)
        ];
        foreach ($schedules as [$v, $name, $type, $intMiles, $months, $lastMiles, $lastAgo, $cost]) {
            $lastAt = $this->today->copy()->subMonths($lastAgo);
            $v->maintenanceSchedules()->create([
                'name' => $name, 'type' => $type, 'interval_km' => $intMiles ? $mi($intMiles) : null, 'interval_months' => $months,
                'last_performed_at' => $lastAt, 'last_performed_mileage' => $mi($lastMiles), 'active' => true, 'created_by' => $sarah->id,
            ]);
            $v->maintenanceHistory()->create([
                'name' => $name, 'performed_at' => $lastAt, 'mileage' => $mi($lastMiles), 'cost' => $cost ?: null,
                'provider' => $v->make === 'Honda' ? 'Honda of Fairfax' : 'Jiffy Lube', 'created_by' => $sarah->id,
            ]);
        }

        // Fuel: [vehicle, days ago, gallons, cost, miles, station]
        $fuel = [
            [$crv, 27, 12.8, 44.67, 61080, 'Costco Gas'], [$crv, 16, 13.1, 45.85, 61540, 'Costco Gas'],
            [$crv, 9, 12.4, 43.40, 61960, 'Shell'], [$crv, 2, 13.0, 45.50, 62410, 'Costco Gas'],
            [$camry, 30, 11.9, 41.29, 97120, 'Shell'], [$camry, 20, 12.2, 42.33, 97490, 'Exxon'],
            [$camry, 11, 11.6, 40.25, 97850, 'Shell'], [$camry, 1, 12.0, 41.64, 98230, 'Shell'],
        ];
        foreach ($fuel as [$v, $ago, $gallons, $cost, $miles, $station]) {
            $v->fuelEntries()->create([
                'filled_on' => $this->today->copy()->subDays($ago), 'liters' => $gal($gallons), 'cost' => $cost,
                'mileage' => $mi($miles), 'is_full_tank' => true, 'station' => $station, 'created_by' => $v->owner_id,
            ]);
        }

        $crv->repairs()->create([
            'title' => 'Rear brake pads + rotors', 'occurred_on' => $this->today->copy()->subDays(95), 'mileage' => $mi(59900),
            'cost' => 412.80, 'provider' => 'Midas', 'notes' => 'Grinding noise when braking. Pads were down to metal on the left rear.',
            'created_by' => $sarah->id,
        ]);
        $camry->repairs()->create([
            'title' => 'Replaced starter motor', 'occurred_on' => $this->today->copy()->subDays(210), 'mileage' => $mi(94300),
            'cost' => 386.00, 'provider' => 'Jiffy Lube', 'notes' => 'Intermittent no-start on cold mornings.', 'created_by' => $david->id,
        ]);
    }

    // ---- Tasks ---------------------------------------------------------------------------------------

    private function tasks(): void
    {
        $sarah = $this->u['Sarah'];
        $d = fn (int $offset) => $this->today->copy()->addDays($offset);

        // [list name, color, icon, tasks: [title, assignee, done, due offset days (null = none), subtasks]]
        $lists = [
            ['Household', 'green', 'home', [
                ['Replace furnace filter', 'David', false, -3, []],
                ['Call the plumber about the upstairs faucet', 'Sarah', false, 0, []],
                ['Rake the leaves', 'Noah', false, 2, []],
                ['Schedule chimney sweep', 'David', false, 6, []],
                ['Clean out the garage', 'David', false, 12, [['Sort camping gear', false], ['Drop donations at Goodwill', false], ['Sell the old bikes', false]]],
                ['Pay water bill', 'Sarah', true, -5, []],
                ['Return Amazon package', 'Sarah', true, -1, []],
            ]],
            ['Kids & school', 'amber', 'star', [
                ["Sign Emma's field trip permission slip", 'Sarah', false, 1, []],
                ['Noah: science fair project', 'Noah', false, 9, [['Pick a topic', true], ['Buy poster board', false], ['Run the experiment', false]]],
                ["Lily's dentist appointment 3:30pm", 'David', false, 4, []],
                ['Order Halloween costumes', 'Sarah', false, 14, []],
                ['Book parent-teacher conferences', 'Sarah', true, -4, []],
            ]],
            ['Weekend projects', 'blue', 'work', [
                ['Build raised garden beds', 'David', false, 20, [['Buy cedar boards', true], ['Cut + assemble', false], ['Fill with soil', false]]],
                ['Hang the new shelves in the den', 'David', false, 5, []],
                ['Frame vacation photos', 'Emma', false, null, []],
                ['Fix the wobbly deck step', 'David', true, -10, []],
            ]],
        ];

        foreach ($lists as $li => [$name, $color, $icon, $tasks]) {
            $list = TaskList::create(['name' => $name, 'color' => $color, 'icon' => $icon, 'position' => $li, 'created_by' => $sarah->id]);
            foreach ($tasks as $ti => [$title, $who, $done, $due, $subs]) {
                $task = Task::create([
                    'task_list_id' => $list->id, 'title' => $title, 'assigned_to' => $this->u[$who]->id, 'done' => $done,
                    'due_date' => $due === null ? null : $d($due), 'position' => $ti, 'created_by' => $sarah->id,
                ]);
                foreach ($subs as $si => [$sub, $subDone]) {
                    Task::create(['task_list_id' => $list->id, 'parent_id' => $task->id, 'title' => $sub, 'done' => $subDone, 'position' => $si, 'created_by' => $sarah->id]);
                }
            }
        }
    }

    // ---- Notes ---------------------------------------------------------------------------------------

    private function notes(): void
    {
        $sarah = $this->u['Sarah'];
        $david = $this->u['David'];
        $home = Folder::create(['name' => 'Home', 'color' => 'green', 'position' => 0, 'created_by' => $sarah->id]);
        $recipes = Folder::create(['name' => 'Recipes', 'color' => 'yellow', 'position' => 1, 'created_by' => $sarah->id]);
        $trips = Folder::create(['name' => 'Trips', 'color' => 'blue', 'position' => 2, 'created_by' => $david->id]);

        $notes = [
            [$home, 'House info & wifi', "**Wifi:** MillerHouse / password on the fridge\n**Trash:** Tuesday (recycling every other week)\n**HVAC:** Comfort Air, 703-555-0142\n**Water shutoff:** basement, behind the stairs", 'yellow', true, $sarah],
            [$home, 'Babysitter notes', "- Lily: bed by 7:30, one story, night light on\n- Noah: no screens after 8\n- Emma can stay up till 9:30 on weekends\n- Pediatrician: Dr. Patel 703-555-0199\n- We're usually back by 11", 'default', true, $sarah],
            [$recipes, "Grandma Jo's chili", "1 lb ground beef, 1 onion, 2 cans kidney beans, 1 can crushed tomatoes, 2 tbsp chili powder, 1 tsp cumin, salt.\n\nBrown the beef with the onion, add everything else, simmer 45 min. Serve with cornbread. Doubles well for the freezer.", 'default', false, $sarah],
            [$recipes, 'Sunday pancakes', "2 cups flour, 2 tbsp sugar, 2 tsp baking powder, 1/2 tsp salt, 2 eggs, 1 3/4 cups milk, 4 tbsp melted butter.\n\nKids like blueberries in Noah's, chocolate chips in Lily's.", 'yellow', false, $david],
            [$trips, 'Spring break ideas', "- Outer Banks (again?)\n- Great Smoky Mountains: cabin near Gatlinburg\n- Washington DC museums day trips\n- Check Southwest points balance before booking", 'blue', false, $david],
            [$home, 'Gift ideas', "**Emma:** drawing tablet, concert tickets\n**Noah:** telescope, Lego Technic\n**Lily:** scooter, the big dollhouse\n**David:** smoker (the pellet one)", 'green', false, $sarah],
        ];
        foreach ($notes as $i => [$folder, $title, $body, $color, $pinned, $by]) {
            Note::create(['folder_id' => $folder->id, 'title' => $title, 'body' => $body, 'color' => $color, 'pinned' => $pinned, 'position' => $i, 'created_by' => $by->id]);
        }
    }

    // ---- Contacts + birthdays ------------------------------------------------------------------------

    private const FIRST = [
        'James', 'Mary', 'Robert', 'Patricia', 'John', 'Jennifer', 'Michael', 'Linda', 'William', 'Elizabeth',
        'Richard', 'Barbara', 'Joseph', 'Susan', 'Thomas', 'Jessica', 'Charles', 'Karen', 'Christopher', 'Nancy',
        'Daniel', 'Lisa', 'Matthew', 'Betty', 'Anthony', 'Margaret', 'Mark', 'Sandra', 'Donald', 'Ashley',
        'Steven', 'Kimberly', 'Paul', 'Emily', 'Andrew', 'Donna', 'Joshua', 'Michelle', 'Kenneth', 'Carol',
        'Kevin', 'Amanda', 'Brian', 'Dorothy', 'George', 'Melissa', 'Timothy', 'Deborah', 'Ronald', 'Stephanie',
        'Edward', 'Rebecca', 'Jason', 'Sharon', 'Jeffrey', 'Laura', 'Ryan', 'Cynthia', 'Jacob', 'Kathleen',
        'Gary', 'Amy', 'Nicholas', 'Angela', 'Eric', 'Shirley', 'Jonathan', 'Anna', 'Stephen', 'Brenda',
        'Larry', 'Pamela', 'Justin', 'Nicole', 'Scott', 'Samantha', 'Brandon', 'Katherine', 'Benjamin', 'Christine',
        'Samuel', 'Helen', 'Gregory', 'Debra', 'Alexander', 'Rachel', 'Frank', 'Carolyn', 'Patrick', 'Janet',
        'Raymond', 'Maria', 'Jack', 'Olivia', 'Dennis', 'Heather', 'Jerry', 'Diane', 'Tyler', 'Julie',
        'Aaron', 'Joyce', 'Jose', 'Victoria', 'Adam', 'Ruth', 'Nathan', 'Virginia', 'Henry', 'Lauren',
        'Zachary', 'Kelly', 'Douglas', 'Christina', 'Peter', 'Joan', 'Kyle', 'Evelyn', 'Ethan', 'Judith',
    ];

    private const LAST = [
        'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez',
        'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez',
        'Thompson', 'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson', 'Walker', 'Young',
        'Allen', 'King', 'Wright', 'Scott', 'Torres', 'Nguyen', 'Hill', 'Flores', 'Green', 'Adams',
        'Nelson', 'Baker', 'Hall', 'Rivera', 'Campbell', 'Mitchell', 'Carter', 'Roberts', 'Gomez', 'Phillips',
        'Evans', 'Turner', 'Diaz', 'Parker', 'Cruz', 'Edwards', 'Collins', 'Reyes', 'Stewart', 'Morris',
        'Murphy',
    ];

    /**
     * The five Millers plus 366 generated contacts, one per calendar day of a leap year, so the birthdays
     * list has someone on every single day, Feb 29 included. Names are picked by index from two lists
     * whose sizes are coprime with 7, so the 366 (first, last) pairs never repeat (and never collide with
     * the Millers). Ages spread 16-78 from the same index.
     */
    // ---- Projects, calendar, recipes (added for the public README screenshots and the live demo) -----
    // Rows are created directly (not through createFor/addTask) where those would push notifications:
    // seeding must not ring the demo members' bells. Dates are relative to today so the demo stays current.

    private function projects(): void
    {
        $sarah = $this->u['Sarah'];
        $david = $this->u['David'];
        // [name, cover image, owner, status, progress, color, target start offset days, description, tasks: [title, assignee, done]]
        $projects = [
            ['Kitchen refresh', 'project-kitchen', $sarah, 'in_progress', 40, 'amber', -14, 'New paint, open shelves and a brighter light over the sink.', [
                ['Pick a paint color', 'Sarah', true], ['Buy brushes and drop cloths', 'David', true],
                ['Paint the walls', 'David', false], ['Install the open shelves', 'David', false], ['Swap the sink light', 'Sarah', false],
            ]],
            ['Backyard vegetable garden', 'project-garden', $david, 'planning', 10, 'green', 21, 'Three raised beds: tomatoes, herbs and strawberries for the kids.', [
                ['Sketch the bed layout', 'Emma', true], ['Order cedar boards', 'David', false], ['Buy soil and compost', 'David', false],
            ]],
        ];
        foreach ($projects as [$name, $cover, $owner, $status, $progress, $color, $start, $desc, $tasks]) {
            $project = Project::create([
                'name' => $name, 'description' => $desc, 'status' => $status, 'progress' => $progress, 'color' => $color,
                'owner_id' => $owner->id, 'target_start' => $this->today->copy()->addDays($start), 'created_by' => $owner->id,
                'cover_path' => $this->demoImage($cover),
            ]);
            $project->shares()->create(['user_id' => $owner->id, 'role' => 'owner']);
            foreach ($tasks as $i => [$title, $who, $done]) {
                // Project tasks are listless tasks-module tasks linked to the project (see the projects module).
                $task = Task::create(['task_list_id' => null, 'title' => $title, 'assigned_to' => $this->u[$who]->id, 'done' => $done, 'position' => $i, 'created_by' => $owner->id]);
                ProjectLink::create(['project_id' => $project->id, 'linkable_type' => 'task', 'linkable_id' => $task->id]);
            }
        }
    }

    private function calendar(): void
    {
        $sarah = $this->u['Sarah'];
        $day = fn (int $offset) => $this->today->copy()->addDays($offset)->toDateString();
        $users = fn (string ...$names) => array_map(fn ($n) => ['type' => 'user', 'id' => $this->u[$n]->id], $names);

        // A trip happening now (shows on the home screen's Now & next card and the timeline).
        $trip = CalendarEvent::createFor($sarah, CalendarEvent::validated([
            'title' => 'Lake Tahoe trip', 'icon' => 'tent', 'color' => 'blue', 'start_date' => $day(-2), 'end_date' => $day(5),
            'notes' => 'Cabin check-in after 3pm. Bring the kayak paddles.', 'people' => $users('Sarah', 'David', 'Emma', 'Noah', 'Lily'),
        ]));
        foreach (['Pack the kayak paddles', 'Download offline maps', 'Book the boat tour'] as $i => $title) {
            $t = Task::create(['task_list_id' => null, 'title' => $title, 'done' => $i === 0, 'position' => $i, 'created_by' => $sarah->id]);
            $trip->links()->create(['linkable_type' => 'task', 'linkable_id' => $t->id]);
        }
        $events = [
            ['Swim practice', 'waves', 'green', $day(1), $day(1), '17:00', '18:00', 'weekly', $users('Noah')],
            ["Emma's piano recital", 'music', 'purple', $day(9), $day(9), '18:30', '20:00', 'none', $users('Emma', 'Sarah', 'David')],
            ['Grandma visits', 'heart', 'red', $day(16), $day(20), null, null, 'none', $users('Sarah', 'Lily')],
            ['Parent-teacher conferences', 'school', 'amber', $day(12), $day(12), '15:30', '16:30', 'none', $users('Sarah')],
            ['Anniversary dinner', 'utensils', 'red', $day(25), $day(25), '19:30', '22:00', 'yearly', $users('Sarah', 'David')],
        ];
        foreach ($events as [$title, $icon, $color, $start, $end, $from, $to, $repeat, $people]) {
            CalendarEvent::createFor($sarah, CalendarEvent::validated([
                'title' => $title, 'icon' => $icon, 'color' => $color, 'start_date' => $start, 'end_date' => $end,
                'start_time' => $from, 'end_time' => $to, 'repeat' => $repeat, 'people' => $people,
            ]));
        }
    }

    private function recipes(): void
    {
        $sarah = $this->u['Sarah'];
        Recipe::createFor($sarah, [
            'title' => 'Sunday pancakes', 'description' => 'Fluffy buttermilk pancakes, the kids\' weekend favourite.',
            'servings' => 4, 'prep_minutes' => 10, 'cook_minutes' => 20, 'tags' => ['breakfast', 'kids'],
            'ingredients' => [
                ['name' => 'flour', 'qty' => 250, 'unit' => 'g'], ['name' => 'buttermilk', 'qty' => 500, 'unit' => 'ml'],
                ['name' => 'eggs', 'qty' => 2, 'unit' => 'pcs'], ['name' => 'sugar', 'qty' => 2, 'unit' => 'tbsp'],
                ['name' => 'baking powder', 'qty' => 2, 'unit' => 'tsp'], ['name' => 'salt', 'qty' => null, 'unit' => null, 'scalable' => false],
            ],
            'steps' => ['Whisk the dry ingredients.', 'Beat the eggs into the buttermilk, then fold into the flour. Leave a few lumps.', 'Cook on a hot buttered pan until bubbles pop, flip once.'],
        ]);
        Recipe::createFor($sarah, [
            'title' => 'Weeknight tomato soup', 'description' => 'Ready in 30 minutes, great with grilled cheese.',
            'servings' => 6, 'prep_minutes' => 10, 'cook_minutes' => 20, 'tags' => ['dinner', 'vegetarian'],
            'ingredients' => [
                ['name' => 'canned tomatoes', 'qty' => 800, 'unit' => 'g'], ['name' => 'onion', 'qty' => 1, 'unit' => 'pcs'],
                ['name' => 'garlic cloves', 'qty' => 3, 'unit' => 'pcs'], ['name' => 'vegetable stock', 'qty' => 500, 'unit' => 'ml'],
                ['name' => 'cream', 'qty' => 100, 'unit' => 'ml', 'note' => 'optional'],
            ],
            'steps' => ['Soften the onion and garlic in olive oil.', 'Add tomatoes and stock, simmer 15 minutes.', 'Blend smooth and stir in the cream.'],
        ]);
    }

    /**
     * A demo photo: shipped in the repo (database/seeders/demo-images/{name}.webp + .thumb.jpg) and copied
     * to a FIXED path on the r2 disk only when missing, so hourly resets don't re-upload, and a visitor who
     * deletes a demo photo just gets it back on the next reset. Decorative only: if storage is unreachable
     * (a dev box without R2 keys) the demo seeds without photos instead of failing the whole reset.
     */
    private function demoImage(string $name): ?string
    {
        $path = "demo/{$name}.webp";
        try {
            $disk = Storage::disk('r2');
            if (! $disk->exists($path)) {
                $disk->put($path, (string) file_get_contents(database_path("seeders/demo-images/{$name}.webp")));
                $disk->put(StoredImage::thumbPath($path), (string) file_get_contents(database_path("seeders/demo-images/{$name}.thumb.jpg")));
            }

            return $path;
        } catch (\Throwable $e) {
            Log::warning('demo image skipped', ['image' => $name, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function people(): void
    {
        $sarah = $this->u['Sarah'];
        $now = $this->today->copy()->startOfDay();
        $people = [];
        foreach (self::MEMBERS as $m) {
            $people[$m['name']] = $m['birthday'];
        }
        $leap = Carbon::create(2024, 1, 1); // any leap year: only month/day are taken from it
        for ($d = 0; $d < 366; $d++) {
            $date = $leap->copy()->addDays($d);
            $name = self::FIRST[$d % count(self::FIRST)].' '.self::LAST[($d * 7) % count(self::LAST)];
            $year = 1948 + ($d * 17) % 62;
            if ($date->month === 2 && $date->day === 29) {
                $year -= $year % 4; // 1948..2008: every multiple of 4 in range is a leap year
            }
            $people[$name] = sprintf('%04d-%02d-%02d', $year, $date->month, $date->day);
        }

        $rows = [];
        foreach ($people as $name => $birthday) {
            $rows[] = ['family_id' => CurrentFamily::id(), 'name' => $name, 'created_by' => $sarah->id, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            Person::insert($chunk);
        }
        $ids = Person::pluck('id', 'name');

        $dates = [];
        foreach ($people as $name => $birthday) {
            [$y, $m, $day] = array_map('intval', explode('-', $birthday));
            $dates[] = [
                'family_id' => CurrentFamily::id(), 'person_id' => $ids[$name], 'birthdate' => $birthday,
                'month' => $m, 'day' => $day, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($dates, 200) as $chunk) {
            DB::table('birthdays_dates')->insert($chunk);
        }

        // A few phone numbers on the grandparents so the contact cards aren't all bare.
        foreach ([['Grandma Jo (Josephine Miller)', '703-555-0117'], ['Grandpa Frank (Frank Miller)', '703-555-0118'], ['Nana Ruth (Ruth Callahan)', '804-555-0163']] as $i => [$name, $phone]) {
            $person = Person::create(['name' => $name, 'created_by' => $sarah->id]);
            $person->contactMethods()->create(['family_id' => CurrentFamily::id(), 'type' => 'phone', 'label' => 'Mobile', 'value' => $phone, 'position' => 0]);
            DB::table('birthdays_dates')->insert([
                'family_id' => CurrentFamily::id(), 'person_id' => $person->id, 'birthdate' => ['1951-08-03', '1949-11-21', '1955-04-17'][$i],
                'month' => [8, 11, 4][$i], 'day' => [3, 21, 17][$i], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
