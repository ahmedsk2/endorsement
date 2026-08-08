<?php

namespace Database\Factories;

use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password = null;

    /**
     * Attributes that belong to the PERSON, not the account — `people` columns since P0c.
     *
     * @var list<string>
     */
    private const PERSON_ONLY = ['full_name', 'position', 'short_name', 'external'];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'institution_id' => null,
            'member_name' => fake()->unique()->userName(),
            'member_email' => $email,
            'password' => static::$password ??= Hash::make('password'),
            'active' => true,
            'remember_token' => Str::random(10),
            // An account in NORMAL USE, which is what almost every test means by "a user".
            // Left null, RequireSetup would redirect every request in the suite to /setup
            // and 150 tests would be asserting against the onboarding page.
            //
            // A genuinely new account is the deliberate case: state it with
            // ->create(['setup_completed_at' => null]), as FirstLoginSetupTest does.
            'setup_completed_at' => now(),

            // Every account belongs to a person (P0c). This key is LAST on purpose: factory
            // closures are resolved against the already-expanded attribute array, so the person
            // inherits whatever the caller overrode (routed through routePersonAttributes()
            // below for full_name/position; member_email/institution_id are mirrored here).
            'person_id' => fn (array $attributes) => Person::factory()->create([
                'email' => $attributes['member_email'] ?? null,
                'institution_id' => $attributes['institution_id'] ?? null,
            ])->id,
        ];
    }

    /**
     * Indicate that the user is an administrator (position 0).
     */
    public function admin(): static
    {
        return $this->for(Person::factory()->state(['position' => 0]), 'person');
    }

    /**
     * Deactivate the ACCOUNT — "cannot log in", which is what every existing caller means. The
     * PERSON stays nameable; that separation is what P0c introduces.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }

    /**
     * Route person-owned overrides onto the linked person.
     *
     * `full_name`/`position` (and `short_name`/`external`) are no longer `users` columns, so the
     * ~40 existing calls of the shape `User::factory()->create(['position' => 4, 'full_name' =>
     * 'Dr X'])` would otherwise try to insert columns that do not exist. Rewriting all forty would
     * be a mechanical diff with no behavioural content and one chance in forty of a silent typo —
     * this routes them instead.
     *
     * Termination: the returned attribute array never contains a PERSON_ONLY key, and Laravel's
     * `create($attrs)` re-enters as `state($attrs)->create([])`, so the second pass sees an empty
     * override array and falls straight through.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: static, 1: array<string, mixed>}
     */
    private function routePersonAttributes(array $attributes): array
    {
        $personOnly = Arr::only($attributes, self::PERSON_ONLY);

        if ($personOnly === []) {
            return [$this, $attributes];
        }

        // Mirror the two columns that live on BOTH rows, when the caller named them here.
        if (array_key_exists('member_email', $attributes)) {
            $personOnly['email'] = $attributes['member_email'];
        }

        if (array_key_exists('institution_id', $attributes)) {
            $personOnly['institution_id'] = $attributes['institution_id'];
        }

        return [
            $this->for(Person::factory()->state($personOnly), 'person'),
            Arr::except($attributes, self::PERSON_ONLY),
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    public function create($attributes = [], ?Model $parent = null)
    {
        [$factory, $rest] = $this->routePersonAttributes((array) $attributes);

        return $factory === $this
            ? parent::create($rest, $parent)
            : $factory->create($rest, $parent);
    }

    /** @param  array<string, mixed>  $attributes */
    public function make($attributes = [], ?Model $parent = null)
    {
        [$factory, $rest] = $this->routePersonAttributes((array) $attributes);

        return $factory === $this
            ? parent::make($rest, $parent)
            : $factory->make($rest, $parent);
    }
}
