<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * AccountService manages financial accounts with file-based persistence.
 *
 * WHY FILE STORAGE?
 * The spec says "durability is NOT a requirement" and forbids a database,
 * but the API must work across multiple HTTP requests. Under php artisan serve
 * and php-fpm, each request is a separate PHP process — a plain in-memory
 * array dies with the process. A JSON file in Laravel's storage directory is
 * the simplest mechanism that actually persists state between requests without
 * introducing a database.
 *
 * FILE FORMAT: { "100": 20, "300": 15 }
 */
class AccountService
{
    private const STORAGE_FILE = 'accounts.json';

    /**
     * Wipe all accounts.
     */
    public function reset(): void
    {
        Storage::put(self::STORAGE_FILE, '{}');
    }

    /**
     * Return the balance of an account, or null if it does not exist.
     * READ-ONLY — no side effects.
     */
    public function getBalance(string $accountId): int|float|null
    {
        $accounts = $this->read();

        return array_key_exists($accountId, $accounts)
            ? $accounts[$accountId]
            : null;
    }

    /**
     * Add amount to destination account.
     * Creates the account if it does not exist yet.
     *
     * @return array{destination: array{id: string, balance: int|float}}
     */
    public function deposit(string $destination, int|float $amount): array
    {
        $accounts = $this->read();

        $accounts[$destination] = ($accounts[$destination] ?? 0) + $amount;

        $this->write($accounts);

        return [
            'destination' => [
                'id'      => $destination,
                'balance' => $accounts[$destination],
            ],
        ];
    }

    /**
     * Subtract amount from origin account.
     * Returns null if the origin account does not exist.
     *
     * @return array{origin: array{id: string, balance: int|float}}|null
     */
    public function withdraw(string $origin, int|float $amount): array|null
    {
        $accounts = $this->read();

        if (!array_key_exists($origin, $accounts)) {
            return null;
        }

        $accounts[$origin] -= $amount;

        $this->write($accounts);

        return [
            'origin' => [
                'id'      => $origin,
                'balance' => $accounts[$origin],
            ],
        ];
    }

    /**
     * Move amount from origin to destination atomically.
     *
     * We read once, validate, update both accounts in memory, then write once.
     * If the origin does not exist, nothing is written — neither account is touched.
     *
     * Returns null if the origin account does not exist.
     * Destination is created automatically if it does not exist.
     *
     * @return array{
     *   origin: array{id: string, balance: int|float},
     *   destination: array{id: string, balance: int|float}
     * }|null
     */
    public function transfer(string $origin, string $destination, int|float $amount): array|null
    {
        $accounts = $this->read();

        if (!array_key_exists($origin, $accounts)) {
            return null;
        }

        $accounts[$origin]      -= $amount;
        $accounts[$destination]  = ($accounts[$destination] ?? 0) + $amount;

        $this->write($accounts);

        return [
            'origin' => [
                'id'      => $origin,
                'balance' => $accounts[$origin],
            ],
            'destination' => [
                'id'      => $destination,
                'balance' => $accounts[$destination],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @return array<string, int|float> */
    private function read(): array
    {
        if (!Storage::exists(self::STORAGE_FILE)) {
            return [];
        }

        return json_decode(Storage::get(self::STORAGE_FILE), associative: true) ?? [];
    }

    /** @param array<string, int|float> $accounts */
    private function write(array $accounts): void
    {
        Storage::put(self::STORAGE_FILE, json_encode($accounts));
    }
}
