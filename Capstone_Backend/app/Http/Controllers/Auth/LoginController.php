<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->only('username', 'password');

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid login details'], 401);
        }

        $user = $request->user();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
   public function register(Request $request)
{
    $request->validate([
       
        'username' => 'required|string|max:255|unique:users',
        'password' => 'required|string|min:6',
        'role' => 'required|in:vendor,customer', // Allow both roles
    ]);

    $user = User::create([
        
        'username' => $request->username,
        'password' => Hash::make($request->password),
        'role' => $request->role,
    ]);

    return response()->json([
        'message' => 'Account created successfully!',
        'user' => $user,
    ], 201);
}

public function AdminCreateAccount(Request $request)
{
    $request->validate([
       
        'username' => 'required|string|max:255|unique:users',
        'password' => 'required|string|min:6',
        'role' => 'required|string|in:incharge_collector,main_collector,meat_inspector' // Adjust roles as needed
    ]);

    $user = User::create([
     
        'username' => $request->username,
        'password' => Hash::make($request->password),
        'role' => $request->role,
    ]);

    return response()->json([
        'message' => 'Account created successfully!',
        'user' => $user,
    ], 201);
}

}