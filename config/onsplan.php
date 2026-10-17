<?php

return [
    // Days a deleted task, list, shopping item, expense or note stays restorable before `trash:purge`
    // removes it for good. Deletes from the web UI and from MCP clients both land in the trash.
    'trash_days' => (int) env('TRASH_DAYS', 30),

    // Password for the demo family's owner (demo@onsplan.nl), so app-directory reviewers (Claude,
    // ChatGPT) can sign in and connect the MCP server to the demo data. Unset = random, as before: /demo
    // is then the only way in. Exposes nothing new (the demo is public via /demo) and DemoSeeder
    // re-applies it on every nightly reset, so a visitor changing it only lasts until midnight.
    'demo_password' => env('DEMO_PASSWORD'),
];
