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
    private const MANAGED_ROLES = ['collector', 'staff'];

    public function index(Request $request)
    {
        $this->ensureAdmin($request);

        $defaultPassword = config('collector_accounts.default_password');

        return User::query()
            ->whereIn('role', self::MANAGED_ROLES)
            ->orderBy('username')
            ->get(['id', 'username', 'role', 'password', 'created_at'])
            ->map(static function (User $user) use ($defaultPassword) {
                $user->setAttribute(
                    'is_default_password',
                    is_string($defaultPassword)
                        && $defaultPassword !== ''
                        && Hash::check($defaultPassword, $user->password)
                );

                return $user;
            });
    }

    public function store(Request $request)
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'role' => ['required', Rule::in(self::MANAGED_ROLES)],
        ]);

        $defaultPassword = $this->defaultPassword();
        $user = User::create([
            'username' => trim($validated['username']),
            'password' => $defaultPassword,
            'role' => $validated['role'],
        ]);

        return response()->json([
            'message' => 'Account created with the default password.',
            'user' => $this->accountData($user, $defaultPassword),
        ], 201);
    }

    public function resetDefaultPassword(Request $request, User $user)
    {
        $this->ensureAdmin($request);
        abort_unless(in_array($user->role, self::MANAGED_ROLES, true), 404);

        $defaultPassword = $this->defaultPassword();

        DB::transaction(function () use ($user, $defaultPassword) {
            $user->password = $defaultPassword;
            $user->remember_token = null;
            $user->save();
            $user->tokens()->delete();
        });

        return response()->json([
            'message' => 'The account password has been reset to the default. Existing sessions were signed out.',
            'user' => $this->accountData($user->refresh(), $defaultPassword),
        ]);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user() && $request->user()->role === 'admin', 403, 'Only administrators can manage collector accounts.');
    }

    private function defaultPassword(): string
    {
        $password = config('collector_accounts.default_password');

        if (!is_string($password) || strlen($password) < 8) {
            abort(503, 'The default account password must be at least 8 characters.');
        }

        return $password;
    }

    private function accountData(User $user, string $defaultPassword): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'created_at' => $user->created_at,
            'is_default_password' => Hash::check($defaultPassword, $user->password),
        ];
    }
}
