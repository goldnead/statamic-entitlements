<?php

namespace Goldnead\Entitlements\Database\Factories;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entitlement>
 */
class EntitlementFactory extends Factory
{
    protected $model = Entitlement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'subject_type' => 'user',
            'subject_id' => (string) $this->faker->numberBetween(1, 100000),
            'product_slug' => $this->faker->unique()->slug(2),
            'source' => 'manual',
            // The empty string, not null. A factory that produced NULLs here
            // would build test data the unique index cannot see, and the
            // idempotency tests would pass against a constraint that was never
            // actually engaged.
            'source_ref' => '',
            'status' => EntitlementState::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => null,
            'grace_until' => null,
            'revoked_at' => null,
            'revoked_reason' => null,
            'announced_state' => EntitlementState::Active->value,
            'meta' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => EntitlementState::Pending->value,
            'starts_at' => null,
            'announced_state' => EntitlementState::Pending->value,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->addWeek(),
            'announced_state' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
            'announced_state' => null,
        ]);
    }

    public function inGracePeriod(): static
    {
        return $this->state(fn (): array => [
            'status' => EntitlementState::GracePeriod->value,
            'expires_at' => now()->subDay(),
            'grace_until' => now()->addWeek(),
        ]);
    }

    public function revoked(string $reason = 'Refunded'): static
    {
        return $this->state(fn (): array => [
            'status' => EntitlementState::Revoked->value,
            'revoked_at' => now()->subHour(),
            'revoked_reason' => $reason,
            'announced_state' => EntitlementState::Revoked->value,
        ]);
    }
}
