<?php

namespace Tests\Feature;

use App\Services\AccountService;
use Tests\TestCase;

/**
 * Feature tests for the three API routes: POST /reset, GET /balance, POST /event.
 *
 * Each route has its own clearly named section.
 * Tests verify REAL state changes, not just return values.
 *
 * The final test mirrors the EBANX automated suite step-by-step.
 */
class AccountApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset in-memory state before each test so tests are independent.
        $this->app->make(AccountService::class)->reset();
    }

    // =========================================================================
    // POST /reset
    // =========================================================================

    public function test_reset_returns_200_ok(): void
    {
        $this->post('/reset')->assertStatus(200);
    }

    public function test_reset_wipes_all_accounts(): void
    {
        // Seed some state.
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 50]);
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '200', 'amount' => 30]);

        $this->post('/reset');

        // Both accounts must be gone.
        $this->get('/balance?account_id=100')->assertStatus(404)->assertContent('0');
        $this->get('/balance?account_id=200')->assertStatus(404)->assertContent('0');
    }

    public function test_reset_is_idempotent_when_called_on_empty_state(): void
    {
        $this->post('/reset')->assertStatus(200);
        $this->post('/reset')->assertStatus(200);
    }

    // =========================================================================
    // GET /balance
    // =========================================================================

    public function test_balance_returns_404_for_non_existing_account(): void
    {
        $this->get('/balance?account_id=1234')
            ->assertStatus(404)
            ->assertContent('0');
    }

    public function test_balance_returns_200_with_correct_value(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 10]);

        $this->get('/balance?account_id=100')
            ->assertStatus(200)
            ->assertContent('10');
    }

    public function test_balance_reflects_multiple_deposits(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 10]);
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 10]);

        $this->get('/balance?account_id=100')
            ->assertStatus(200)
            ->assertContent('20');
    }

    public function test_balance_does_not_change_state_on_multiple_reads(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 30]);

        // Calling GET /balance three times must always return the same value.
        $this->get('/balance?account_id=100')->assertContent('30');
        $this->get('/balance?account_id=100')->assertContent('30');
        $this->get('/balance?account_id=100')->assertContent('30');
    }

    public function test_balance_does_not_create_account_for_unknown_id(): void
    {
        $this->get('/balance?account_id=ghost');

        // Still 404 after the read.
        $this->get('/balance?account_id=ghost')->assertStatus(404);
    }

    // =========================================================================
    // POST /event — deposit
    // =========================================================================

    public function test_deposit_creates_account_with_initial_balance(): void
    {
        $this->postJson('/event', [
            'type'        => 'deposit',
            'destination' => '100',
            'amount'      => 10,
        ])
            ->assertStatus(201)
            ->assertJson(['destination' => ['id' => '100', 'balance' => 10]]);
    }

    public function test_deposit_into_existing_account_accumulates_balance(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 10]);

        $this->postJson('/event', [
            'type'        => 'deposit',
            'destination' => '100',
            'amount'      => 10,
        ])
            ->assertStatus(201)
            ->assertJson(['destination' => ['id' => '100', 'balance' => 20]]);

        // Confirm persisted via balance endpoint.
        $this->get('/balance?account_id=100')->assertContent('20');
    }

    public function test_deposit_does_not_affect_other_accounts(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '200', 'amount' => 50]);
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 20]);

        $this->get('/balance?account_id=200')->assertContent('50');
    }

    public function test_deposit_missing_destination_returns_error(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'amount' => 10])
            ->assertStatus(422);
    }

    public function test_deposit_with_zero_amount_returns_error(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 0])
            ->assertStatus(422);
    }

    public function test_deposit_with_negative_amount_returns_error(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => -5])
            ->assertStatus(422);
    }

    // =========================================================================
    // POST /event — withdraw
    // =========================================================================

    public function test_withdraw_from_non_existing_account_returns_404(): void
    {
        $this->postJson('/event', [
            'type'   => 'withdraw',
            'origin' => '200',
            'amount' => 10,
        ])
            ->assertStatus(404)
            ->assertContent('0');
    }

    public function test_withdraw_from_existing_account_reduces_balance(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 20]);

        $this->postJson('/event', [
            'type'   => 'withdraw',
            'origin' => '100',
            'amount' => 5,
        ])
            ->assertStatus(201)
            ->assertJson(['origin' => ['id' => '100', 'balance' => 15]]);

        // Confirm state persisted.
        $this->get('/balance?account_id=100')->assertContent('15');
    }

    public function test_withdraw_failure_does_not_create_account(): void
    {
        $this->postJson('/event', ['type' => 'withdraw', 'origin' => 'ghost', 'amount' => 10]);

        $this->get('/balance?account_id=ghost')->assertStatus(404);
    }

    public function test_withdraw_failure_does_not_affect_other_accounts(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '300', 'amount' => 40]);

        $this->postJson('/event', ['type' => 'withdraw', 'origin' => 'ghost', 'amount' => 10]);

        $this->get('/balance?account_id=300')->assertContent('40');
    }

    public function test_withdraw_missing_origin_returns_error(): void
    {
        $this->postJson('/event', ['type' => 'withdraw', 'amount' => 10])
            ->assertStatus(422);
    }

    // =========================================================================
    // POST /event — transfer
    // =========================================================================

    public function test_transfer_from_non_existing_account_returns_404(): void
    {
        $this->postJson('/event', [
            'type'        => 'transfer',
            'origin'      => '200',
            'destination' => '300',
            'amount'      => 15,
        ])
            ->assertStatus(404)
            ->assertContent('0');
    }

    public function test_transfer_deducts_from_origin(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 50]);

        $this->postJson('/event', [
            'type'        => 'transfer',
            'origin'      => '100',
            'destination' => '300',
            'amount'      => 15,
        ]);

        $this->get('/balance?account_id=100')->assertContent('35');
    }

    public function test_transfer_adds_to_destination(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 50]);

        $this->postJson('/event', [
            'type'        => 'transfer',
            'origin'      => '100',
            'destination' => '300',
            'amount'      => 15,
        ]);

        $this->get('/balance?account_id=300')->assertContent('15');
    }

    public function test_transfer_returns_both_accounts_in_response(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 15]);

        $this->postJson('/event', [
            'type'        => 'transfer',
            'origin'      => '100',
            'destination' => '300',
            'amount'      => 15,
        ])
            ->assertStatus(201)
            ->assertJson([
                'origin'      => ['id' => '100', 'balance' => 0],
                'destination' => ['id' => '300', 'balance' => 15],
            ]);
    }

    public function test_transfer_creates_destination_account_when_it_does_not_exist(): void
    {
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 40]);

        $this->postJson('/event', [
            'type'        => 'transfer',
            'origin'      => '100',
            'destination' => '999',
            'amount'      => 20,
        ]);

        $this->get('/balance?account_id=999')->assertStatus(200)->assertContent('20');
    }

    public function test_transfer_failure_is_atomic_destination_unchanged(): void
    {
        // Pre-seed destination.
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '300', 'amount' => 10]);

        // Transfer from non-existing origin — must not touch destination.
        $this->postJson('/event', [
            'type'        => 'transfer',
            'origin'      => 'ghost',
            'destination' => '300',
            'amount'      => 5,
        ]);

        $this->get('/balance?account_id=300')->assertContent('10');
    }

    public function test_transfer_missing_origin_returns_error(): void
    {
        $this->postJson('/event', ['type' => 'transfer', 'destination' => '300', 'amount' => 10])
            ->assertStatus(422);
    }

    public function test_transfer_missing_destination_returns_error(): void
    {
        $this->postJson('/event', ['type' => 'transfer', 'origin' => '100', 'amount' => 10])
            ->assertStatus(422);
    }

    // =========================================================================
    // POST /event — invalid type
    // =========================================================================

    public function test_unknown_event_type_returns_error(): void
    {
        $this->postJson('/event', ['type' => 'refund', 'destination' => '100', 'amount' => 10])
            ->assertStatus(422);
    }

    // =========================================================================
    // Full EBANX automated suite — exact sequence from ipkiss.ebanx.ninja
    // =========================================================================

    public function test_full_ebanx_automated_suite_sequence(): void
    {
        // 1. Reset state before starting tests
        $this->post('/reset')->assertStatus(200);

        // 2. Get balance for non-existing account
        $this->get('/balance?account_id=1234')
            ->assertStatus(404)
            ->assertContent('0');

        // 3. Create account with initial balance
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 10])
            ->assertStatus(201)
            ->assertJson(['destination' => ['id' => '100', 'balance' => 10]]);

        // 4. Deposit into existing account
        $this->postJson('/event', ['type' => 'deposit', 'destination' => '100', 'amount' => 10])
            ->assertStatus(201)
            ->assertJson(['destination' => ['id' => '100', 'balance' => 20]]);

        // 5. Get balance for existing account
        $this->get('/balance?account_id=100')
            ->assertStatus(200)
            ->assertContent('20');

        // 6. Withdraw from non-existing account
        $this->postJson('/event', ['type' => 'withdraw', 'origin' => '200', 'amount' => 10])
            ->assertStatus(404)
            ->assertContent('0');

        // 7. Withdraw from existing account
        $this->postJson('/event', ['type' => 'withdraw', 'origin' => '100', 'amount' => 5])
            ->assertStatus(201)
            ->assertJson(['origin' => ['id' => '100', 'balance' => 15]]);

        // 8. Transfer from existing account
        $this->postJson('/event', [
            'type'        => 'transfer',
            'origin'      => '100',
            'amount'      => 15,
            'destination' => '300',
        ])
            ->assertStatus(201)
            ->assertJson([
                'origin'      => ['id' => '100', 'balance' => 0],
                'destination' => ['id' => '300', 'balance' => 15],
            ]);

        // 9. Transfer from non-existing account
        $this->postJson('/event', [
            'type'        => 'transfer',
            'origin'      => '200',
            'amount'      => 15,
            'destination' => '300',
        ])
            ->assertStatus(404)
            ->assertContent('0');
    }
}
