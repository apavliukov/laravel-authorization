<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'secret',
        ];
    }

    public function admin(): static
    {
        return $this->afterCreating(static function (User $user): void {
            $user->assignRole(Role::ADMIN->value);
        });
    }

    public function member(): static
    {
        return $this->afterCreating(static function (User $user): void {
            $user->assignRole(Role::MEMBER->value);
        });
    }
}
