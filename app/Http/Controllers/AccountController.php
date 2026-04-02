<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventRequest;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * AccountController handles HTTP transport only.
 *
 * Responsibilities:
 *  - Parse and validate HTTP input (delegated to EventRequest)
 *  - Call the appropriate AccountService method
 *  - Map service results to HTTP responses
 *
 * There is no business logic here. If you need to change a rule
 * (e.g. allow overdraft), you touch AccountService, not this file.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $accountService,
    ) {}

    /**
     * POST /reset
     * Wipes all in-memory state.
     */
    public function reset(): Response
    {
        $this->accountService->reset();

        return response('OK', 200);
    }

    /**
     * GET /balance?account_id={id}
     * Returns the current balance. Read-only — no state changes.
     */
    public function balance(Request $request): Response
    {
        $balance = $this->accountService->getBalance(
            (string) $request->query('account_id')
        );

        if ($balance === null) {
            return response('0', 404);
        }

        return response((string) $balance, 200);
    }

    /**
     * POST /event
     * Dispatches deposit, withdraw, or transfer operations.
     */
    public function event(EventRequest $request): JsonResponse|Response
    {
        $data = $request->validated();

        $result = match ($data['type']) {
            'deposit'  => $this->accountService->deposit(
                destination: $data['destination'],
                amount:      $data['amount'],
            ),
            'withdraw' => $this->accountService->withdraw(
                origin: $data['origin'],
                amount: $data['amount'],
            ),
            'transfer' => $this->accountService->transfer(
                origin:      $data['origin'],
                destination: $data['destination'],
                amount:      $data['amount'],
            ),
        };

        if ($result === null) {
            return response('0', 404);
        }

        return response()->json($result, 201);
    }
}
