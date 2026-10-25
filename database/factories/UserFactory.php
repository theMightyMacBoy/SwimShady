<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'password' => 'password',
            'role' => User::STANDARD,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => User::ADMIN]);
    }

    public function readonly(): static
    {
        return $this->state(fn () => ['role' => User::READONLY]);
    }
}
