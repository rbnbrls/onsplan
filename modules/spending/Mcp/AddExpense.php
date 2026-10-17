<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Notifications\Notify;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;

class AddExpense extends Tool
{
    protected string $module = 'spending';

    protected string $access = 'write';

    protected string $name = 'spending_add_expense';

    protected string $description = 'Record an expense. Without list_id it goes to the family\'s default "Others" list. Leave category out to let onsplan guess it. If an expense with the same amount, date and shop already exists it is still added, and the response says so in `warning`.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->description('Amount in major units, e.g. 12.50.')->required(),
            'currency' => $schema->string()->description('EUR, RON, USD or GBP (default: the list\'s currency).'),
            'title' => $schema->string()->description('What it was, e.g. "Groceries".'),
            'category' => $schema->string()->description('Category key from spending_list_categories (default: auto-guessed).'),
            'shop' => $schema->string()->description('Shop / merchant name.'),
            'date' => $schema->string()->format('date')->description('YYYY-MM-DD (default: today).'),
            'paid_by' => $schema->integer()->description('Member id who paid (default: this person).'),
            'payer' => $schema->string()->description('Who paid, as a participant key of the list: "u7" (member) or "g12" (guest, see spending_add_guest). Overrides paid_by.'),
            'list_id' => $schema->integer()->description('Spending list id (default: the family\'s "Others" list).'),
            'count_in_monthly' => $schema->boolean()->description('Count it in monthly spending totals and analytics? Omit to follow the list\'s setting (project budget lists are off by default).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user();
        $request->validate(['list_id' => ['nullable', 'integer'], 'paid_by' => ['nullable', 'integer'], 'payer' => ['nullable', 'string']]);
        $event = $request->get('list_id')
            ? SpendData::event($user, $request->get('list_id'), edit: true)
            : SpendEvent::others($user);
        if (! $event) {
            return $this->notFound('List');
        }

        // Same rules as the web form (SpendEvent::spendRules): known currency, the family's categories,
        // a payer who takes part in this list.
        $data = Validator::validate([
            'amount' => $request->get('amount'),
            'currency' => $request->get('currency', $event->currency),
            'category' => $request->get('category', 'other'), // 'other' => the auto-categorizer guesses it
            // A participant key (member or guest) wins over the member id; validated against the list's
            // participants by spendRules, exactly like the web form's payer select.
            'payer_key' => $request->get('payer') ?: 'u'.($request->get('paid_by') ?: $user->id),
            'note' => $request->get('title'),
            'store' => $request->get('shop'),
            'spent_at' => $request->get('date', now()->toDateString()),
            // Only when given: absent = follow the list (SpendEvent::buildSpend).
            ...($request->get('count_in_monthly') !== null ? ['count_in_monthly' => $request->get('count_in_monthly')] : []),
        ], $event->spendRules(), ['payer_key.in' => 'paid_by / payer must be one of this list\'s participants: '.implode(', ', array_keys($event->participantNames())).'.']);

        // Soft duplicate check, same as the add form: warn, never block (the same purchase twice is real).
        $duplicate = Spend::looksDuplicate($data, SpendData::visibleEvents($user)->pluck('id'));
        $spend = $event->spends()->create([...$event->spendColumns($data), 'created_by' => $user->id]);

        $amount = $data['currency'].' '.number_format((float) $data['amount'], 2);
        Notify::toAccessOf($event, 'spending', $user->id, $event->name, "{$user->name} added {$amount}".(! empty($data['note']) ? " · {$data['note']}" : ''), "/m/spending/events/{$event->id}");
        SpendData::changed();

        return Response::structured(SpendData::payload($spend->fresh(['event', 'payer', 'payerGuest']), true) + ($duplicate ? [
            'warning' => 'This looks like a duplicate of an expense already recorded (same amount, date and shop/category).',
        ] : []));
    }
}
