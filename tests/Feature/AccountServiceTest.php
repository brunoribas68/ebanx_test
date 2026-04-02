<?php

namespace Tests\Unit;

use App\Services\AccountService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Unit tests for AccountService.
 *
 * AccountService uses Laravel's Storage facade to persist state between
 * requests. We use Storage::fake() so tests run against a temporary
 * in-memory filesystem — no real files touched, no side effects between tests.
 */
class AccountServiceTest extends TestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->service = new AccountService();
    }

    // =========================================================================
    // reset()
    // =========================================================================

    public function test_reset_clears_all_existing_accounts(): void
    {
        $this->service->deposit('100', 50);
        $this->service->deposit('200', 30);

        $this->service->reset();

        $this->assertNull($this->service->getBalance('100'));
        $this->assertNull($this->service->getBalance('200'));
    }

    public function test_reset_on_empty_state_does_not_throw(): void
    {
        $this->service->reset(); // should be a no-op
        $this->assertNull($this->service->getBalance('any'));
    }

    // =========================================================================
    // getBalance()
    // =========================================================================

    public function test_get_balance_returns_null_for_unknown_account(): void
    {
        $this->assertNull($this->service->getBalance('does-not-exist'));
    }

    public function test_get_balance_returns_correct_value_after_deposit(): void
    {
        $this->service->deposit('100', 42);

        $this->assertSame(42, $this->service->getBalance('100'));
    }

    public function test_get_balance_is_read_only_and_does_not_create_the_account(): void
    {
        $this->service->getBalance('ghost');
        $this->service->getBalance('ghost');

        // Account must still not exist after reads.
        $this->assertNull($this->service->getBalance('ghost'));
    }

    public function test_get_balance_called_twice_returns_same_value(): void
    {
        $this->service->deposit('100', 20);

        $first  = $this->service->getBalance('100');
        $second = $this->service->getBalance('100');

        $this->assertSame($first, $second);
    }

    // =========================================================================
    // deposit()
    // =========================================================================

    public function test_deposit_creates_account_when_it_does_not_exist(): void
    {
        $result = $this->service->deposit('100', 10);

        $this->assertSame('100', $result['destination']['id']);
        $this->assertSame(10,    $result['destination']['balance']);
    }

    public function test_deposit_persists_balance_readable_via_get_balance(): void
    {
        $this->service->deposit('100', 10);

        $this->assertSame(10, $this->service->getBalance('100'));
    }

    public function test_deposit_accumulates_on_existing_account(): void
    {
        $this->service->deposit('100', 10);
        $result = $this->service->deposit('100', 15);

        $this->assertSame(25, $result['destination']['balance']);
        $this->assertSame(25, $this->service->getBalance('100'));
    }

    public function test_deposit_does_not_affect_other_accounts(): void
    {
        $this->service->deposit('100', 50);
        $this->service->deposit('200', 30);

        $this->service->deposit('100', 20);

        // Account 200 must remain untouched.
        $this->assertSame(30, $this->service->getBalance('200'));
    }

    // =========================================================================
    // withdraw()
    // =========================================================================

    public function test_withdraw_returns_null_for_unknown_account(): void
    {
        $this->assertNull($this->service->withdraw('does-not-exist', 10));
    }

    public function test_withdraw_reduces_balance_correctly(): void
    {
        $this->service->deposit('100', 30);
        $result = $this->service->withdraw('100', 10);

        $this->assertSame('100', $result['origin']['id']);
        $this->assertSame(20,    $result['origin']['balance']);
    }

    public function test_withdraw_persists_new_balance(): void
    {
        $this->service->deposit('100', 30);
        $this->service->withdraw('100', 10);

        $this->assertSame(20, $this->service->getBalance('100'));
    }

    public function test_withdraw_failure_does_not_create_account(): void
    {
        $this->service->withdraw('ghost', 10);

        $this->assertNull($this->service->getBalance('ghost'));
    }

    public function test_withdraw_failure_does_not_affect_other_accounts(): void
    {
        $this->service->deposit('200', 40);

        $this->service->withdraw('ghost', 10);

        $this->assertSame(40, $this->service->getBalance('200'));
    }

    // =========================================================================
    // transfer()
    // =========================================================================

    public function test_transfer_returns_null_when_origin_does_not_exist(): void
    {
        $this->assertNull($this->service->transfer('ghost', '200', 10));
    }

    public function test_transfer_deducts_from_origin(): void
    {
        $this->service->deposit('100', 50);
        $this->service->transfer('100', '200', 30);

        $this->assertSame(20, $this->service->getBalance('100'));
    }

    public function test_transfer_adds_to_destination(): void
    {
        $this->service->deposit('100', 50);
        $this->service->transfer('100', '200', 30);

        $this->assertSame(30, $this->service->getBalance('200'));
    }

    public function test_transfer_creates_destination_if_it_does_not_exist(): void
    {
        $this->service->deposit('100', 20);
        $this->service->transfer('100', '999', 20);

        $this->assertSame(20, $this->service->getBalance('999'));
    }

    public function test_transfer_returns_both_updated_accounts(): void
    {
        $this->service->deposit('100', 50);
        $result = $this->service->transfer('100', '200', 30);

        $this->assertSame('100', $result['origin']['id']);
        $this->assertSame(20,    $result['origin']['balance']);
        $this->assertSame('200', $result['destination']['id']);
        $this->assertSame(30,    $result['destination']['balance']);
    }

    public function test_transfer_failure_does_not_touch_destination(): void
    {
        $this->service->deposit('200', 10);

        $this->service->transfer('ghost', '200', 5);

        // Destination balance must be untouched.
        $this->assertSame(10, $this->service->getBalance('200'));
    }

    public function test_transfer_is_atomic_origin_not_modified_on_failure(): void
    {
        $this->service->deposit('100', 50);

        // A failing transfer (non-existent origin) must not touch anyone.
        $this->service->transfer('ghost', '100', 10);

        $this->assertSame(50, $this->service->getBalance('100'));
    }
}
