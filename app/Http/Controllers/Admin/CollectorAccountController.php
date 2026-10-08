<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class CollectorAccountController extends Controller
{
    private const MANAGED_ROLES = [
        'collector',
        'staff',
    ];

    /**
     * List Collector and Staff accounts.
     */
    public function index(Request $request)
    {
        $this->ensureAdmin($request);

        $defaultPassword = $this->defaultPassword();

        return User::query()
            ->whereIn('role', self::MANAGED_ROLES)
            ->orderBy('username')
            ->get([
                'id',
                'username',
                'email',
                'role',
                'password',
                'created_at',
            ])
            ->map(function (User $user) use ($defaultPassword) {

                $isDefaultPassword = false;

                /*
                 * Only check passwords that are actually
                 * valid bcrypt hashes.
                 */
                if (
                    is_string($user->password) &&
                    str_starts_with($user->password, '$2y$')
                ) {
                    try {
                        $isDefaultPassword = Hash::check(
                            $defaultPassword,
                            $user->password
                        );
                    } catch (\Throwable $e) {
                        $isDefaultPassword = false;
                    }
                }

                return [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'created_at' => $user->created_at,
                    'is_default_password' => $isDefaultPassword,
                ];
            });
    }

    /**
     * Create a new Collector or Staff account.
     */
    public function store(Request $request)
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username'),
            ],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],

            'role' => [
                'required',
                Rule::in(self::MANAGED_ROLES),
            ],
        ]);

        $defaultPassword = $this->defaultPassword();

        /*
         * Explicitly create the bcrypt password hash.
         */
        $hashedPassword = Hash::make($defaultPassword);

        $user = User::create([
            'username' => trim($validated['username']),
            'email' => strtolower(trim($validated['email'])),

            /*
             * Store the actual bcrypt hash.
             */
            'password' => $hashedPassword,

            'role' => $validated['role'],
        ]);

        return response()->json([
            'message' =>
                'Account created successfully with the default password.',

            'user' => $this->accountData(
                $user,
                $defaultPassword
            ),
        ], 201);
    }

    /**
     * Reset account password to the default password.
     */
    public function resetDefaultPassword(
        Request $request,
        User $user
    ) {
        $this->ensureAdmin($request);

        abort_unless(
            in_array(
                $user->role,
                self::MANAGED_ROLES,
                true
            ),
            404
        );

        $defaultPassword = $this->defaultPassword();

        DB::transaction(function () use (
            $user,
            $defaultPassword
        ) {
            /*
             * Explicitly generate a new bcrypt hash.
             */
            $user->password = Hash::make(
                $defaultPassword
            );

            $user->remember_token = null;

            $user->save();

            /*
             * Sign out all active Sanctum tokens.
             */
            $user->tokens()->delete();
        });

        return response()->json([
            'message' =>
                'The account password has been reset to p@ssword123. Existing sessions were signed out.',

            'user' => $this->accountData(
                $user->refresh(),
                $defaultPassword
            ),
        ]);
    }

    /**
     * Make sure only administrators can manage
     * Collector and Staff accounts.
     */
    private function ensureAdmin(Request $request): void
    {
        abort_unless(
            $request->user() &&
            $request->user()->role === 'admin',
            403,
            'Only administrators can manage collector accounts.'
        );
    }

    /**
     * Get the configured default password.
     */
    private function defaultPassword(): string
    {
        $password = config(
            'collector_accounts.default_password',
            'p@ssword123'
        );

        if (
            !is_string($password) ||
            strlen($password) < 8
        ) {
            abort(
                503,
                'The default account password must be at least 8 characters.'
            );
        }

        return $password;
    }

    /**
     * Return safe account information.
     */
    private function accountData(
        User $user,
        string $defaultPassword
    ): array {
        $isDefaultPassword = false;

        if (
            is_string($user->password) &&
            str_starts_with($user->password, '$2y$')
        ) {
            try {
                $isDefaultPassword = Hash::check(
                    $defaultPassword,
                    $user->password
                );
            } catch (\Throwable $e) {
                $isDefaultPassword = false;
            }
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'created_at' => $user->created_at,
            'is_default_password' => $isDefaultPassword,
        ];
    }
}