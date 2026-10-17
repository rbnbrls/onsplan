<?php

namespace App\Mcp;

use App\Support\Analytics;
use Generator;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

// tools/call, plus an `assistant_tool` analytics event naming the tool (never its arguments or result).
// Hooked here, the one place every tool call passes through (OnsplanServer::boot swaps it in), instead
// of in 100+ tool classes. parent::handle() throws for an unknown/unlisted tool, so only real calls count.
class TrackedCallTool extends CallTool
{
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $response = parent::handle($request, $context);
        Analytics::track(auth()->user(), 'assistant_tool', ['tool' => (string) $request->get('name')], '/mcp');

        return $response;
    }
}
