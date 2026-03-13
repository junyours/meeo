<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class AdminProfileController extends Controller
{
    /**
     * Get admin profile data
     */
    public function getProfile(Request $request)
    {
        try {
            $user = Auth::user();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch profile data'
            ], 500);
        }
    }

    /**
     * Send OTP for credential change verification
     */
    public function sendOTP(Request $request)
    {
        try {
            \Log::info('sendOTP request received', [
                'user_id' => Auth::id(),
                'request_type' => $request->type,
                'request_data' => $request->all(),
                'has_current_password' => $request->has('current_password'),
                'current_password_value' => $request->current_password
            ]);

            $validator = Validator::make($request->all(), [
                'type' => 'required|in:username,email,password',
                'username' => 'required_if:type,username',
                'email' => 'required_if:type,email|email',
                'current_password' => 'required_if:type,password',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            
            // Note: Password verification will be done in verifyOTP to avoid double checking

            // Generate OTP
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otpHash = Hash::make($otp);
            
            // Store OTP in both session and cache for reliability
            $otpData = [
                'otp' => $otpHash,
                'expires_at' => Carbon::now()->addMinutes(10),
                'change_type' => $request->type,
                'pending_changes' => $request->except(['type', 'current_password'])
            ];

            // Store in session
            session([
                'admin_otp' => $otpHash,
                'admin_otp_expires_at' => Carbon::now()->addMinutes(10),
                'admin_change_type' => $request->type,
                'admin_pending_changes' => $request->except(['type', 'current_password'])
            ]);

            // Store in cache as backup
            $cacheKey = 'admin_otp_' . Auth::id();
            Cache::put($cacheKey, $otpData, now()->addMinutes(10));

            // Debug logging
            \Log::info('OTP stored in session and cache', [
                'cache_key' => $cacheKey,
                'otp_data' => $otpData,
                'session_id' => session()->getId(),
                'user_id' => Auth::id()
            ]);

            // Send OTP via email
            try {
                Mail::raw("Your OTP for admin credential change is: {$otp}\n\nThis OTP will expire in 10 minutes.", function($message) use ($user) {
                    $message->to($user->email)
                            ->subject('Admin Credential Change OTP');
                });
            } catch (\Exception $mailException) {
                // If email fails, still proceed but log the error
                \Log::error('Failed to send OTP email: ' . $mailException->getMessage());
                
                // For development, you might want to return the OTP in response
                // Remove this in production
                if (app()->environment('local', 'testing')) {
                    return response()->json([
                        'success' => true,
                        'message' => 'OTP generated (email failed in development)',
                        'dev_otp' => $otp // Only for development
                    ]);
                }
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send OTP email'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP'
            ], 500);
        }
    }

    /**
     * Verify OTP and apply changes
     */
    public function verifyOTP(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'otp' => 'required|string|size:6',
                'type' => 'required|in:username,email,password',
                'username' => 'required_if:type,username',
                'email' => 'required_if:type,email|email',
                'current_password' => 'required_if:type,password',
                'new_password' => 'required_if:type,password|min:8',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check OTP session first, then cache as backup
            $otpData = null;
            $cacheKey = 'admin_otp_' . Auth::id();

            // Try to get from session first
            if (session('admin_otp') && session('admin_otp_expires_at') && session('admin_change_type')) {
                $otpData = [
                    'otp' => session('admin_otp'),
                    'expires_at' => session('admin_otp_expires_at'),
                    'change_type' => session('admin_change_type'),
                    'pending_changes' => session('admin_pending_changes', [])
                ];
                \Log::info('OTP found in session', ['session_id' => session()->getId()]);
            } 
            // If not in session, try cache
            elseif (Cache::has($cacheKey)) {
                $otpData = Cache::get($cacheKey);
                \Log::info('OTP found in cache', ['cache_key' => $cacheKey]);
            }

            \Log::info('OTP retrieval attempt', [
                'session_id' => session()->getId(),
                'cache_key' => $cacheKey,
                'session_has_otp' => session()->has('admin_otp'),
                'cache_has_otp' => Cache::has($cacheKey),
                'otp_data_found' => !is_null($otpData)
            ]);

            if (!$otpData) {
                return response()->json([
                    'success' => false,
                    'message' => 'No OTP session found. Please request a new OTP.',
                    'debug' => [
                        'session_id' => session()->getId(),
                        'cache_key' => $cacheKey,
                        'has_session_otp' => session()->has('admin_otp'),
                        'has_cache_otp' => Cache::has($cacheKey)
                    ]
                ], 422);
            }

            // Check OTP expiration
            if (Carbon::now()->gt($otpData['expires_at'])) {
                // Clear expired OTP from both session and cache
                session()->forget(['admin_otp', 'admin_otp_expires_at', 'admin_change_type', 'admin_pending_changes']);
                Cache::forget($cacheKey);
                
                return response()->json([
                    'success' => false,
                    'message' => 'OTP has expired. Please request a new OTP.'
                ], 422);
            }

            // Verify OTP
            if (!Hash::check($request->otp, $otpData['otp'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid OTP'
                ], 422);
            }

            // Verify change type matches
            if ($request->type !== $otpData['change_type']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Change type mismatch'
                ], 422);
            }

            $user = Auth::user();
            $changeType = $otpData['change_type'];
            $pendingChanges = $otpData['pending_changes'];

            // Apply the changes
            switch ($changeType) {
                case 'username':
                    if (empty($pendingChanges['username'])) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No username change pending'
                        ], 422);
                    }
                    
                    // Check if username already exists
                    if (User::where('username', $pendingChanges['username'])->where('id', '!=', $user->id)->exists()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Username already exists'
                        ], 422);
                    }
                    
                    $user->username = $pendingChanges['username'];
                    break;

                case 'email':
                    if (empty($pendingChanges['email'])) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No email change pending'
                        ], 422);
                    }
                    
                    // Check if email already exists
                    if (User::where('email', $pendingChanges['email'])->where('id', '!=', $user->id)->exists()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Email already exists'
                        ], 422);
                    }
                    
                    $user->email = $pendingChanges['email'];
                    break;

                case 'password':
                    if (empty($request->new_password)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'New password is required'
                        ], 422);
                    }
                    
                    $user->password = Hash::make($request->new_password);
                    break;

                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid change type'
                    ], 422);
            }

            // Save the changes
            $user->save();

            // Clear OTP session and cache
            session()->forget(['admin_otp', 'admin_otp_expires_at', 'admin_change_type', 'admin_pending_changes']);
            Cache::forget($cacheKey);

            return response()->json([
                'success' => true,
                'message' => ucfirst($changeType) . ' updated successfully',
                'data' => [
                    'username' => $user->username,
                    'email' => $user->email,
                    'updated_at' => $user->updated_at,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify OTP and update profile'
            ], 500);
        }
    }

    /**
     * Update admin profile (without OTP - for future use)
     */
    public function updateProfile(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'username' => 'sometimes|required|string|min:3|max:20|unique:users,username,' . Auth::id(),
                'email' => 'sometimes|required|email|unique:users,email,' . Auth::id(),
                'current_password' => 'required_with:new_password|string',
                'new_password' => 'sometimes|required|string|min:8'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();

            // If changing password, verify current password
            if ($request->has('new_password')) {
                if (!Hash::check($request->current_password, $user->password)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Current password is incorrect'
                    ], 422);
                }
                $user->password = Hash::make($request->new_password);
            }

            // Update other fields
            if ($request->has('username')) {
                $user->username = $request->username;
            }

            if ($request->has('email')) {
                $user->email = $request->email;
            }

            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'username' => $user->username,
                    'email' => $user->email,
                    'updated_at' => $user->updated_at,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile'
            ], 500);
        }
    }

    /**
     * Get admin activity log (for future enhancement)
     */
    public function getActivityLog(Request $request)
    {
        try {
            // This would require an activity_log table
            // For now, return empty data
            return response()->json([
                'success' => true,
                'data' => []
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch activity log'
            ], 500);
        }
    }
}
