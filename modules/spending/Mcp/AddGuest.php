<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use App\Models\Guest;
use App\Support\Sharing\ShareAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Spending\SpendEvent;

#[IsIdempotent]
class AddGuest extends Tool
{
    protected string $module = 'spending';

    protected string $access = 'write';

    protected string $name = 'spending_add_guest';

    protected string $description = 'Add a named guest (someone without a onsplan account, e.g. a friend on a trip) to a spending list, like Share > add guest in the app. Returns the guest\'s participant key (e.g. "g12") to use as `payer` in spending_add_expense, plus the list\'s participants. Adding the same name again returns the existing guest. Only the list\'s owner can add guests.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->required(),
            'name' => $schema->string()->required(),
            'role' => $schema->string()->enum(Guest::ROLES)->description('What the guest may do through the list\'s guest link: editor (add expenses, the app\'s default) or viewer.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        // Same rules as GuestLinkController::storeGuest (name max 60, a known role, owner only).
        $data = $request->validate([
            'list_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:60'],
            'role' => ['nullable', 'in:'.implode(',', Guest::ROLES)],
        ]);
        $event = SpendEvent::find($data['list_id']);
        if (! $event || ! app(ShareAccess::class)->canView($this->user(), $event)) {
            return $this->notFound('List');
        }
        if (! app(ShareAccess::class)->canManage($this->user(), $event)) {
            return Response::error('Only the list\'s owner can add guests to it.');
        }

        // Idempotent by name (trimmed, case-insensitive) so a retry never creates a second "Ana".
        $name = trim($data['name']);
        $guest = $event->guests()->get()->first(fn (Guest $g) => mb_strtolower(trim($g->name)) === mb_strtolower($name));
        $created = ! $guest;
        $guest ??= $event->guests()->create(['name' => $name, 'role' => $data['role'] ?? 'editor']);
        $event->unsetRelation('guests'); // participantNames() reads the relation; include the new guest

        if ($created) {
            SpendData::changed();
        }

        return Response::structured([
            'key' => 'g'.$guest->id,
            'name' => $guest->name,
            'role' => $guest->role,
            'created' => $created,
            'list' => SpendData::listPayload($event),
        ]);
    }
}
