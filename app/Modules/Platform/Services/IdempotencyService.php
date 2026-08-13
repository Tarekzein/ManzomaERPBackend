<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\CommandReceipt;
use App\Support\ConflictException;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Run a command at most once per idempotency key.
 *
 * The contract is deliberately strict, because the caller is usually moving
 * money and stock:
 *
 *   - First call with a key runs the command and stores its result.
 *   - A repeat of the same key and payload replays the stored result without
 *     re-running anything.
 *   - The same key with a *different* payload is rejected: that is a client
 *     bug, and returning an unrelated receipt would be worse than an error.
 *   - A call that arrives while the first is still running is rejected as a
 *     conflict rather than queued, so a double-tap cannot produce two sales.
 *   - A command that throws leaves no claim behind, so a genuine retry after a
 *     failure is allowed to succeed.
 *
 * The claim is committed before the command starts. The business writes and
 * completed response then commit together, so a crash cannot leave a durable
 * sale hidden behind a permanently in-progress receipt.
 */
class IdempotencyService
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $command  the work to run at most once
     * @param  Closure(TResult): array  $present  serialises the result for replay
     * @return array{result: TResult|null, response: array, replayed: bool}
     */
    public function execute(
        int $companyId,
        string $command,
        string $key,
        array $payload,
        Closure $callback,
        ?Closure $present = null,
        ?int $userId = null,
    ): array {
        if (DB::transactionLevel() > $this->baselineTransactionLevel()) {
            throw new LogicException('Idempotent commands must be started outside an existing database transaction.');
        }

        $hash = $this->hash($payload);
        $claim = $this->claim($companyId, $command, $key, $hash, $userId);

        if ($claim['replayed']) {
            return ['result' => null, 'response' => $claim['response'], 'replayed' => true];
        }

        $receiptId = $claim['receipt_id'];
        $claimToken = $claim['claim_token'];

        try {
            // The business writes and the completed receipt commit together.
            // This closes the dangerous window where a sale could commit and
            // a process crash could leave its receipt permanently in_progress.
            [$result, $response] = DB::transaction(function () use (
                $callback,
                $present,
                $receiptId,
                $claimToken,
            ) {
                $result = $callback();
                $response = $present ? $present($result) : [];

                $completed = $this->completeClaim($receiptId, $claimToken, $result, $response);

                if ($completed !== 1) {
                    throw new ConflictException(
                        'The command reservation was lost before it completed. Retry the request.',
                        'COMMAND_RESERVATION_LOST',
                    );
                }

                return [$result, $response];
            });
        } catch (Throwable $exception) {
            // Release the key so the caller can genuinely retry. Deleting is
            // safe only while this worker still owns the same claim epoch.
            // A stale worker must never delete a replacement worker's claim.
            $this->releaseClaim($receiptId, $claimToken);

            throw $exception;
        }

        return ['result' => $result, 'response' => $response, 'replayed' => false];
    }

    /**
     * The transaction depth that is not the caller's fault.
     *
     * In production a command must start at depth 0, so its claim is a real
     * commit rather than a savepoint that a caller's rollback would undo. The
     * test harness wraps every test in one transaction of its own
     * (RefreshDatabase), which is not misuse — so that single level is the
     * baseline there. Genuine nesting still trips the guard in both.
     */
    private function baselineTransactionLevel(): int
    {
        return app()->runningUnitTests() ? 1 : 0;
    }

    /**
     * Take the key, or return the completed response already stored under it.
     *
     * @return array{receipt_id: int, claim_token: string, response: array, replayed: bool}
     */
    private function claim(int $companyId, string $command, string $key, string $hash, ?int $userId): array
    {
        $claimToken = (string) Str::uuid();

        try {
            // execute() rejects outer transactions, so this transaction is a
            // real commit rather than a savepoint inside the caller's work.
            $receipt = DB::transaction(fn () => CommandReceipt::query()->create([
                'company_id' => $companyId,
                'command' => $command,
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'claim_token' => $claimToken,
                'status' => CommandReceipt::STATUS_IN_PROGRESS,
                'user_id' => $userId,
            ]));

            return $this->ownedClaim($receipt->getKey(), $claimToken);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($companyId, $command, $key, $hash, $userId);
        }
    }

    /** @return array{receipt_id: int, claim_token: string, response: array, replayed: bool} */
    private function replay(
        int $companyId,
        string $command,
        string $key,
        string $hash,
        ?int $userId,
    ): array {
        /** @var CommandReceipt $receipt */
        $receipt = CommandReceipt::query()
            ->where('company_id', $companyId)
            ->where('command', $command)
            ->where('idempotency_key', $key)
            ->firstOrFail();

        if ($receipt->request_hash !== $hash) {
            throw new ConflictException(
                'This idempotency key was already used for a different request.',
                'IDEMPOTENCY_KEY_REUSED',
            );
        }

        if ($receipt->status === CommandReceipt::STATUS_IN_PROGRESS) {
            // A reclaim keeps the receipt id/key stable and atomically rotates
            // its ownership token. Only one contender can replace the token;
            // all older workers are fenced from completion and cleanup.
            if ($receipt->updated_at?->lt(now()->subHour())) {
                $replacementToken = (string) Str::uuid();
                $reclaimed = CommandReceipt::query()
                    ->whereKey($receipt->getKey())
                    ->where('status', CommandReceipt::STATUS_IN_PROGRESS)
                    ->where('request_hash', $hash)
                    ->where(fn ($query) => $receipt->claim_token === null
                        ? $query->whereNull('claim_token')
                        : $query->where('claim_token', $receipt->claim_token))
                    ->update([
                        'claim_token' => $replacementToken,
                        'user_id' => $userId,
                        'updated_at' => now(),
                    ]);

                if ($reclaimed === 1) {
                    return $this->ownedClaim($receipt->getKey(), $replacementToken);
                }
            }

            throw new ConflictException(
                'An identical request is still being processed. Retry in a moment.',
                'COMMAND_IN_PROGRESS',
            );
        }

        return [
            'receipt_id' => (int) $receipt->getKey(),
            'claim_token' => (string) $receipt->claim_token,
            'response' => $receipt->response ?? [],
            'replayed' => true,
        ];
    }

    /** @return array{receipt_id: int, claim_token: string, response: array, replayed: false} */
    private function ownedClaim(int $receiptId, string $claimToken): array
    {
        return [
            'receipt_id' => $receiptId,
            'claim_token' => $claimToken,
            'response' => [],
            'replayed' => false,
        ];
    }

    private function completeClaim(int $receiptId, string $claimToken, mixed $result, array $response): int
    {
        return CommandReceipt::query()
            ->whereKey($receiptId)
            ->where('claim_token', $claimToken)
            ->where('status', CommandReceipt::STATUS_IN_PROGRESS)
            ->update([
                'status' => CommandReceipt::STATUS_COMPLETED,
                'response' => $response,
                'resource_type' => is_object($result) ? $result::class : null,
                'resource_id' => is_object($result) && method_exists($result, 'getKey') ? $result->getKey() : null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function releaseClaim(int $receiptId, string $claimToken): int
    {
        return CommandReceipt::query()
            ->whereKey($receiptId)
            ->where('claim_token', $claimToken)
            ->where('status', CommandReceipt::STATUS_IN_PROGRESS)
            ->delete();
    }

    private function hash(array $payload): string
    {
        // Key order must not change the hash: the same command sent by two
        // clients that serialise their JSON differently is the same command.
        $normalized = $this->normalize($payload);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map(fn ($item) => $this->normalize($item), $value);

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
