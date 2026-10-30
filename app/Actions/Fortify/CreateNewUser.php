<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Services\RegistrationAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private RegistrationAccess $registrationAccess,
        private CreateTeam $createTeam,
    ) {}

    /**
     * Validate and create a newly registered user along with their personal team.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $email = Str::lower(trim((string) ($input['email'] ?? '')));
        $invitationCode = $input['invitation'] ?? null;

        Validator::make([...$input, 'email' => $email], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
            'invitation' => ['nullable', 'string'],
        ])->after(function (ValidatorInstance $validator) use ($email, $invitationCode) {
            if ($validator->errors()->isEmpty() && ! $this->registrationAccess->allows($email, $invitationCode)) {
                $validator->errors()->add('email', __('Registration is by invitation only. Ask a team owner to invite this email address.'));
            }
        })->validate();

        return DB::transaction(function () use ($input, $email) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $email,
                'password' => $input['password'],
            ]);

            $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            return $user;
        });
    }
}
