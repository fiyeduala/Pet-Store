<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates an administrator.
 *
 * There is deliberately NO seeded default admin account and no shared
 * default password anywhere in this codebase. The first administrator is
 * created here, interactively, with a password the operator chooses.
 */
class CreateStaffUser extends Command
{
    protected $signature = 'petstore:make-admin
                            {--name= : Full name}
                            {--email= : Email address}
                            {--role=owner : owner, operations or support}';

    protected $description = 'Create a staff account for the admin panel.';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Full name');
        $email = $this->option('email') ?: $this->ask('Email address');
        $role = (string) $this->option('role');

        if (! in_array($role, [User::ROLE_OWNER, User::ROLE_OPERATIONS, User::ROLE_SUPPORT], true)) {
            $this->error("Unknown role [{$role}]. Use owner, operations or support.");

            return self::FAILURE;
        }

        $password = $this->secret('Password (input hidden)');
        $confirm = $this->secret('Confirm password');

        if ($password !== $confirm) {
            $this->error('Those passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email:rfc', 'max:190'],
                'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::withTrashed()->firstOrNew(['email' => $email]);
        $user->fill(['name' => $name, 'is_staff' => true]);
        $user->password = Hash::make($password);
        $user->email_verified_at ??= now();
        $user->deleted_at = null;
        $user->save();

        $user->syncRoles([$role]);

        $this->info("Staff account ready: {$email} ({$role}).");
        $this->line('Enable two-factor authentication from the profile page after signing in.');

        return self::SUCCESS;
    }
}
