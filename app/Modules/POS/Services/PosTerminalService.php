<?php

namespace App\Modules\POS\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\POS\Models\PosPaymentAttempt;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Policies\PosPolicy;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Card terminal handshake.
 *
 * The provider call happens *outside* any database transaction. Holding row
 * locks across a network round-trip to an acquirer is how a busy till deadlocks
 * itself, and a terminal can take thirty seconds to answer.
 *
 * The flow is therefore three steps:
 *   1. `intent()`   — record an attempt, return its reference. No locks held.
 *   2. the terminal is operated, and the provider is polled/calls back, or an
 *      assigned supervisor verifies an attended manual terminal.
 *   3. the verified capture is recorded against the attempt.
 *
 * Only a *verified* attempt can be presented to checkout as a card tender, so a
 * client cannot claim a payment the acquirer never authorised.
 */
class PosTerminalService
{
    public function __construct(private readonly PosPolicy $policy) {}

    /** Open an attempt before the terminal is used. */
    public function intent(User $user, array $data): PosPaymentAttempt
    {
        $companyId = $this->policy->companyId($user, 'pos.sell');
        $register = $this->policy->register($user, (int) $data['pos_register_id']);
        $this->policy->ensureRegisterIsUsable($register);
        $this->policy->ensureAssigned($user, $register);

        $amount = Decimal::of($data['amount']);
        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => ['Enter an amount greater than zero.']]);
        }

        $provider = $this->assertProviderIsConfigured($register, $data['provider'] ?? null);

        return PosPaymentAttempt::query()->create([
            'company_id' => $companyId,
            'pos_register_id' => $register->getKey(),
            'provider' => $provider,
            'external_reference' => $data['external_reference'] ?? (string) Str::uuid(),
            'state' => PosPaymentAttempt::STATE_PENDING,
            'amount' => $amount->toString(),
            'attempt' => 1,
        ]);
    }

    /**
     * Record the answer shown on an attended manual terminal.
     *
     * This is deliberately not a generic provider-confirmation method: the
     * authenticated approver must be a supervisor assigned to this register.
     * Integrated providers need a separate signed webhook/server-side poll.
     */
    public function confirmManual(
        User $user,
        string $externalReference,
        bool $approved,
        array $response = [],
        ?string $failureReason = null,
    ): PosPaymentAttempt {
        $companyId = $this->policy->companyId($user, 'pos.supervisor_override');

        return DB::transaction(function () use ($user, $companyId, $externalReference, $approved, $response, $failureReason) {
            $attempt = PosPaymentAttempt::query()
                ->where('company_id', $companyId)
                ->where('provider', 'manual_terminal')
                ->where('external_reference', $externalReference)
                ->lockForUpdate()
                ->firstOrFail();

            $register = PosRegister::query()
                ->where('company_id', $companyId)
                ->whereKey($attempt->pos_register_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->policy->ensureRegisterIsUsable($register);
            $this->policy->ensureSupervisorAssigned($user, $register);

            // Providers retry their callbacks. A second delivery must not
            // reopen or double-capture a settled attempt.
            if ($attempt->state === PosPaymentAttempt::STATE_CAPTURED) {
                return $attempt;
            }

            if ($attempt->state === PosPaymentAttempt::STATE_FAILED) {
                if ($approved) {
                    throw ValidationException::withMessages([
                        'external_reference' => ['A declined terminal attempt is final. Start a new payment attempt.'],
                    ]);
                }

                return $attempt;
            }

            $attempt->forceFill([
                'state' => $approved ? PosPaymentAttempt::STATE_CAPTURED : PosPaymentAttempt::STATE_FAILED,
                'provider_response' => $this->redact($response),
                'failure_reason' => $failureReason,
                'verified_at' => $approved ? now() : null,
            ])->save();

            return $attempt->refresh();
        });
    }

    /**
     * The attempt a checkout may spend, or a refusal.
     *
     * Checks the amount matches too: an authorisation for 5.00 cannot be used
     * to settle a 500.00 basket.
     */
    public function claimForCheckout(
        int $companyId,
        int $registerId,
        string $provider,
        string $externalReference,
        string $amount,
    ): PosPaymentAttempt {
        $attempt = PosPaymentAttempt::query()
            ->where('company_id', $companyId)
            ->where('pos_register_id', $registerId)
            ->where('provider', $provider)
            ->where('external_reference', $externalReference)
            ->lockForUpdate()
            ->first();

        if (! $attempt) {
            throw ValidationException::withMessages([
                'tenders' => ['That card payment reference does not belong to this register and provider.'],
            ]);
        }

        if ($attempt->state !== PosPaymentAttempt::STATE_CAPTURED || $attempt->verified_at === null) {
            throw ValidationException::withMessages([
                'tenders' => ['That card payment has not been confirmed by the provider.'],
            ]);
        }

        if ($attempt->pos_sale_id !== null) {
            throw ValidationException::withMessages([
                'tenders' => ['That card payment has already been used on another sale.'],
            ]);
        }

        if (! Decimal::of($attempt->amount)->equals(Decimal::of($amount))) {
            throw ValidationException::withMessages([
                'tenders' => ['The authorised amount does not match this sale.'],
            ]);
        }

        return $attempt;
    }

    private function assertProviderIsConfigured(PosRegister $register, ?string $provider): string
    {
        $methods = $register->paymentMethods()
            ->where('tender_type', 'card')
            ->where('is_active', true)
            ->get();

        if ($methods->isEmpty()) {
            throw ValidationException::withMessages([
                'provider' => ['This register does not accept card payments.'],
            ]);
        }

        $configured = $methods
            ->pluck('provider')
            ->map(fn ($value) => $value ?: 'manual_terminal')
            ->unique()
            ->values();

        if ($provider !== null && ! $configured->contains($provider)) {
            throw ValidationException::withMessages([
                'provider' => ['That card provider is not configured for this register.'],
            ]);
        }

        return $provider ?? (string) $configured->first();
    }

    /**
     * Providers echo back more than we should keep. Only a shortlist survives,
     * so a PAN or a token can never reach the database through this path.
     */
    private function redact(array $response): array
    {
        $allowed = ['status', 'code', 'message', 'auth_code', 'rrn', 'scheme', 'card_brand', 'last4', 'terminal_id', 'operator_user_id'];

        return collect($response)
            ->only($allowed)
            ->map(fn ($value) => is_scalar($value) ? $value : null)
            ->filter(fn ($value) => $value !== null)
            ->all();
    }
}
