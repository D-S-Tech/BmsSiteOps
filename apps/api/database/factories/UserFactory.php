<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'current_tenant_id' => null,
            'is_super_admin' => false,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(['is_super_admin' => true]);
    }

    /**
     * Associate the user with a tenant (creates the tenant_user pivot)
     * and set it as the user's current_tenant_id.
     */
    public function inTenant(Tenant $tenant, string $role = 'member'): static
    {
        return $this->state(['current_tenant_id' => $tenant->id])
            ->afterCreating(function (User $user) use ($tenant, $role) {
                $user->tenants()->attach($tenant, ['role' => $role]);
            });
    }
}
