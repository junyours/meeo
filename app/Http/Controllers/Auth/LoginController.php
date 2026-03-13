<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    // Validate credentials and captcha
    public function validateCredentials(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'captcha' => 'required|string'
        ]);

        // Validate captcha from cache
        $captchaHash = $request->input('captcha_hash');
        $sessionCaptcha = null;
        
        if ($captchaHash) {
            $cacheKey = 'captcha_' . $captchaHash;
            $sessionCaptcha = Cache::get($cacheKey);
            
            // Debug logging
            \Log::info('Captcha validation attempt', [
                'captcha_hash' => $captchaHash,
                'cache_key' => $cacheKey,
                'cached_captcha' => $sessionCaptcha,
                'request_captcha' => $request->captcha,
                'uppercase_cached' => $sessionCaptcha ? strtoupper($sessionCaptcha) : null,
                'uppercase_request' => strtoupper($request->captcha)
            ]);
            
            if ($sessionCaptcha && strtoupper($request->captcha) !== strtoupper($sessionCaptcha)) {
                \Log::warning('Captcha mismatch', [
                    'cached' => $sessionCaptcha,
                    'request' => $request->captcha
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid captcha code'
                ], 422);
            }
            
            // Clear captcha after validation
            if ($sessionCaptcha) {
                Cache::forget($cacheKey);
            }
        } else {
            // Fallback to session-based validation for backward compatibility
            $sessionCaptcha = session('captcha_code');
            \Log::info('Fallback to session validation', [
                'session_captcha' => $sessionCaptcha,
                'request_captcha' => $request->captcha
            ]);
            
            if ($sessionCaptcha && strtoupper($request->captcha) !== strtoupper($sessionCaptcha)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid captcha code'
                ], 422);
            }
            
            // Clear captcha after validation
            if ($sessionCaptcha) {
                session()->forget('captcha_code');
            }
        }

        // Validate credentials
        $credentials = $request->only('username', 'password');
        
        if (!Auth::validate($credentials)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid username or password'
            ], 401);
        }

        $user = User::where('username', $request->username)->first();

        return response()->json([
            'success' => true,
            'message' => 'Credentials validated successfully',
            'user' => $user
        ]);
    }

    // Send OTP to user's email
    public function sendOTP(Request $request)
    {
        $request->validate([
            'username' => 'required|string'
        ]);

        $user = User::where('username', $request->username)->first();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Check if user has email
        if (!$user->email) {
            return response()->json([
                'success' => false,
                'message' => 'No email address associated with this account'
            ], 422);
        }

        // Generate 6-digit OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store OTP in cache with 5 minutes expiration
        Cache::put("otp_{$user->id}", $otp, now()->addMinutes(5));
        
        // Send OTP via email
        try {
            Mail::raw("Your OTP code is: {$otp}\n\nThis code will expire in 5 minutes.\n\nIf you didn't request this code, please ignore this email.\n\nMEEO System", function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('MEEO System - OTP Verification Code');
            });

            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully to your email',
                'email_hint' => 'OTP sent to: ' . substr($user->email, 0, 3) . '***@' . substr($user->email, strpos($user->email, '@') + 1)
            ]);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('OTP sending failed: ' . $e->getMessage());
            
            // For development, return OTP in response (remove in production)
            return response()->json([
                'success' => true,
                'message' => 'OTP generated successfully (email not configured)',
                'otp' => $otp, // Remove this in production
                'debug' => 'Email configuration needed. Check your .env file.',
                'email_sent_to' => $user->email
            ]);
        }
    }

    // Verify OTP
    public function verifyOTP(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'otp' => 'required|string|size:6'
        ]);

        $user = User::where('username', $request->username)->first();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Get OTP from cache
        $cachedOTP = Cache::get("otp_{$user->id}");
        
        if (!$cachedOTP || $request->otp !== $cachedOTP) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP'
            ], 422);
        }

        // Clear OTP after successful verification
        Cache::forget("otp_{$user->id}");

        // Generate authentication token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully',
            'user' => $user,
            'token' => $token
        ]);
    }

    // Generate captcha
    public function generateCaptcha(Request $request)
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $code = '';
        
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[rand(0, strlen($chars) - 1)];
        }

        // Create a hash of the code for validation (store in cache with short expiry)
        $hash = hash('sha256', $code . config('app.key'));
        Cache::put('captcha_' . $hash, $code, now()->addMinutes(5)); // Store for 5 minutes
        
        // Debug logging
        \Log::info('Captcha generated and stored', [
            'code' => $code,
            'hash' => $hash,
            'cache_key' => 'captcha_' . $hash
        ]);

        // Create captcha image
        $width = 180;
        $height = 60;
        $image = imagecreatetruecolor($width, $height);
        
        // Colors
        $bgColor = imagecolorallocate($image, 240, 242, 245);
        $textColor = imagecolorallocate($image, 44, 62, 80);
        $lineColor = imagecolorallocate($image, 200, 200, 200);
        
        // Fill background
        imagefill($image, 0, 0, $bgColor);
        
        // Add noise lines
        for ($i = 0; $i < 5; $i++) {
            imageline($image, 
                rand(0, $width), 
                rand(0, $height), 
                rand(0, $width), 
                rand(0, $height), 
                $lineColor
            );
        }
        
        // Add text
        $font = 5; // Built-in font
        $textWidth = imagefontwidth($font) * strlen($code);
        $textHeight = imagefontheight($font);
        $x = ($width - $textWidth) / 2;
        $y = ($height - $textHeight) / 2;
        
        imagestring($image, $font, $x, $y, $code, $textColor);
        
        // Capture image
        ob_start();
        imagepng($image);
        $imageData = ob_get_contents();
        ob_end_clean();
        
        imagedestroy($image);
        
        // Return both image and hash
        return response()->json([
            'image' => 'data:image/png;base64,' . base64_encode($imageData),
            'hash' => $hash
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

// Show Password Reset Form (Web Route)
public function showPasswordResetForm(Request $request)
{
    $token = $request->query('token');
    
    if (!$token) {
        return response()->json([
            'error' => 'Invalid reset token'
        ], 400);
    }
    
    // Verify token exists in cache
    $userId = Cache::get("password_reset_{$token}");
    
    if (!$userId) {
        return response()->json([
            'error' => 'Invalid or expired reset token'
        ], 400);
    }
    
    // For now, return a simple HTML form
    // In a real application, you would return a proper Blade view
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <title>Reset Password - MEEO System</title>
        <meta name="csrf-token" content="' . csrf_token() . '">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                background: linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #7e8ba3 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            
            .background-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: url("data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'%23ffffff\' fill-opacity=\'0.05\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
                z-index: -1;
            }
            
            .reset-container {
                background: rgba(255, 255, 255, 0.98);
                backdrop-filter: blur(25px);
                border-radius: 20px;
                box-shadow: 0 50px 100px rgba(0, 0, 0, 0.4), 0 20px 40px rgba(0, 0, 0, 0.3);
                width: 100%;
                max-width: 480px;
                padding: 50px 40px;
                border: 1px solid rgba(255, 255, 255, 0.3);
                position: relative;
                overflow: hidden;
            }
            
            .reset-container::before {
                content: "";
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 4px;
                background: linear-gradient(90deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            }
            
            .logo-section {
                text-align: center;
                margin-bottom: 40px;
            }
            
            .icon-circle {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 24px;
                box-shadow: 0 8px 24px rgba(245, 158, 11, 0.25);
                animation: pulse 2s infinite;
            }
            
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); }
                100% { transform: scale(1); }
            }
            
            .icon-circle i {
                font-size: 36px;
                color: #fff;
            }
            
            h2 {
                font-size: 28px;
                font-weight: 700;
                color: #1a202c;
                margin-bottom: 8px;
                letter-spacing: -0.5px;
            }
            
            .subtitle {
                color: #64748b;
                font-size: 16px;
                line-height: 1.6;
                font-weight: 400;
            }
            
            .form-group {
                margin-bottom: 24px;
            }
            
            .input-container {
                position: relative;
                display: flex;
                align-items: center;
                background: #f8fafc;
                border: 2px solid #e2e8f0;
                border-radius: 16px;
                padding: 4px;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                overflow: hidden;
            }
            
            .input-container:focus-within {
                border-color: #667eea;
                box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
                background: #ffffff;
                transform: translateY(-1px);
            }
            
            .input-icon {
                color: #667eea;
                font-size: 18px;
                margin-right: 14px;
                min-width: 24px;
                transition: all 0.3s ease;
            }
            
            .password-input {
                flex: 1;
                padding: 16px 14px;
                font-size: 15px;
                background: transparent;
                border: none;
                outline: none;
                color: #1e293b;
                font-weight: 500;
                font-family: "Inter", sans-serif;
            }
            
            .password-input::placeholder {
                color: #94a3b8;
            }
            
            .toggle-password {
                font-size: 20px;
                color: #667eea;
                cursor: pointer;
                margin-right: 12px;
                transition: all 0.3s ease;
                padding: 4px;
                border-radius: 8px;
            }
            
            .toggle-password:hover {
                background: rgba(102, 126, 234, 0.1);
                transform: scale(1.1);
            }
            
            .requirements {
                margin-top: 12px;
                padding: 12px 16px;
                background: rgba(102, 126, 234, 0.05);
                border-radius: 12px;
                border: 1px solid rgba(102, 126, 234, 0.1);
            }
            
            .requirements h4 {
                font-size: 14px;
                font-weight: 600;
                color: #667eea;
                margin-bottom: 8px;
            }
            
            .requirements ul {
                list-style: none;
                padding: 0;
            }
            
            .requirements li {
                font-size: 13px;
                color: #64748b;
                margin-bottom: 4px;
                display: flex;
                align-items: center;
            }
            
            .requirements li::before {
                content: "✓";
                color: #10b981;
                font-weight: bold;
                margin-right: 8px;
            }
            
            .submit-btn {
                width: 100%;
                padding: 16px 24px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                font-size: 16px;
                font-weight: 600;
                border: none;
                border-radius: 16px;
                cursor: pointer;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: 0 10px 25px rgba(102, 126, 234, 0.35);
                letter-spacing: 0.3px;
                font-family: "Inter", sans-serif;
                position: relative;
                overflow: hidden;
            }
            
            .submit-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 15px 35px rgba(102, 126, 234, 0.45);
                background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
            }
            
            .submit-btn:active {
                transform: translateY(0);
            }
            
            .submit-btn:disabled {
                opacity: 0.7;
                cursor: not-allowed;
                transform: none;
            }
            
            .message {
                margin-top: 20px;
                padding: 12px 16px;
                border-radius: 12px;
                font-size: 14px;
                font-weight: 500;
                display: none;
            }
            
            .message.error {
                background: rgba(239, 68, 68, 0.08);
                border: 1px solid rgba(239, 68, 68, 0.15);
                color: #dc2626;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .message.success {
                background: rgba(16, 185, 129, 0.08);
                border: 1px solid rgba(16, 185, 129, 0.15);
                color: #059669;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .back-link {
                text-align: center;
                margin-top: 24px;
            }
            
            .back-link a {
                color: #667eea;
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
                transition: all 0.3s ease;
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }
            
            .back-link a:hover {
                color: #5a67d8;
                transform: translateX(-2px);
            }
            
            .loading {
                display: inline-block;
                width: 16px;
                height: 16px;
                border: 2px solid rgba(255, 255, 255, 0.3);
                border-radius: 50%;
                border-top-color: #fff;
                animation: spin 0.8s linear infinite;
            }
            
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
            
            @media (max-width: 480px) {
                .reset-container {
                    padding: 40px 30px;
                    margin: 20px;
                }
                
                h2 {
                    font-size: 24px;
                }
            }
        </style>
    </head>
    <body>
        <div class="background-overlay"></div>
        
        <div class="reset-container">
            <div class="logo-section">
                <div class="icon-circle">
                    <i class="fas fa-key"></i>
                </div>
                <h2>Reset Password</h2>
                <p class="subtitle">Enter your new password below to secure your account</p>
            </div>
            
            <form id="resetForm">
                <input type="hidden" id="token" value="' . $token . '">
                
                <div class="form-group">
                    <div class="input-container">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" class="password-input" placeholder="Enter new password" required minlength="8">
                        <i class="fas fa-eye toggle-password" id="togglePassword" title="Show password"></i>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="input-container">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password_confirmation" name="password_confirmation" class="password-input" placeholder="Confirm new password" required>
                        <i class="fas fa-eye toggle-password" id="toggleConfirmPassword" title="Show password"></i>
                    </div>
                </div>
                
                <div class="requirements">
                    <h4>Password Requirements:</h4>
                    <ul>
                        <li>At least 8 characters long</li>
                        <li>Contains both letters and numbers</li>
                        <li>Includes special characters recommended</li>
                    </ul>
                </div>
                
                <button type="submit" class="submit-btn" id="submitBtn">
                    <span id="btnText">Reset Password</span>
                </button>
                
                <div id="message" class="message"></div>
            </form>
            
            <div class="back-link">
                <a href="http://localhost:3000/#/login">
                    <i class="fas fa-arrow-left"></i>
                    Back to Login
                </a>
            </div>
        </div>
        
        <script>
        // Password visibility toggle
        function setupPasswordToggle(toggleId, inputId) {
            const toggle = document.getElementById(toggleId);
            const input = document.getElementById(inputId);
            
            toggle.addEventListener("click", function() {
                const type = input.getAttribute("type") === "password" ? "text" : "password";
                input.setAttribute("type", type);
                
                // Update icon
                if (type === "text") {
                    toggle.classList.remove("fa-eye");
                    toggle.classList.add("fa-eye-slash");
                    toggle.setAttribute("title", "Hide password");
                } else {
                    toggle.classList.remove("fa-eye-slash");
                    toggle.classList.add("fa-eye");
                    toggle.setAttribute("title", "Show password");
                }
            });
        }
        
        setupPasswordToggle("togglePassword", "password");
        setupPasswordToggle("toggleConfirmPassword", "password_confirmation");
        
        document.getElementById("resetForm").addEventListener("submit", function(e) {
            e.preventDefault();
            
            var token = document.getElementById("token").value;
            var password = document.getElementById("password").value;
            var password_confirmation = document.getElementById("password_confirmation").value;
            var messageDiv = document.getElementById("message");
            var submitBtn = document.getElementById("submitBtn");
            var btnText = document.getElementById("btnText");
            
            // Clear previous messages
            messageDiv.className = "message";
            messageDiv.style.display = "none";
            
            if (password !== password_confirmation) {
                messageDiv.innerHTML = "<i class=\"fas fa-exclamation-circle\"></i> Passwords do not match";
                messageDiv.className = "message error";
                messageDiv.style.display = "flex";
                return;
            }
            
            if (password.length < 8) {
                messageDiv.innerHTML = "<i class=\"fas fa-exclamation-circle\"></i> Password must be at least 8 characters";
                messageDiv.className = "message error";
                messageDiv.style.display = "flex";
                return;
            }
            
            // Show loading state
            submitBtn.disabled = true;
            btnText.innerHTML = \'<span class="loading"></span> Resetting...\';
            
            // Get CSRF token
            var csrfToken = document.querySelector(\'meta[name="csrf-token"]\')?.getAttribute(\'content\');
            
            fetch("/reset-password", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                    "Accept": "application/json"
                },
                body: JSON.stringify({
                    token: token,
                    password: password,
                    password_confirmation: password_confirmation
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(\'Network response was not ok\');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    messageDiv.innerHTML = "<i class=\"fas fa-check-circle\"></i> " + data.message;
                    messageDiv.className = "message success";
                    messageDiv.style.display = "flex";
                    
                    // Redirect after 3 seconds
                    setTimeout(() => {
                        window.location.href = "http://localhost:3000/#/login";
                    }, 3000);
                } else {
                    messageDiv.innerHTML = "<i class=\"fas fa-exclamation-circle\"></i> " + data.message;
                    messageDiv.className = "message error";
                    messageDiv.style.display = "flex";
                }
            })
            .catch(error => {
                console.error(\'Error:\', error);
                messageDiv.innerHTML = "<i class=\"fas fa-exclamation-circle\"></i> An error occurred. Please try again.";
                messageDiv.className = "message error";
                messageDiv.style.display = "flex";
            })
            .finally(() => {
                // Reset button state
                submitBtn.disabled = false;
                btnText.innerHTML = "Reset Password";
            });
        });
        </script>
    </body>
    </html>';
    
    return response($html);
}

// Handle Password Reset (Web Route)
public function handlePasswordReset(Request $request)
{
    $request->validate([
        'token' => 'required|string',
        'password' => 'required|string|min:8|confirmed',
        'password_confirmation' => 'required|string'
    ]);

    $token = $request->token;
    
    // Verify token exists in cache
    $userId = Cache::get("password_reset_{$token}");
    
    if (!$userId) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid or expired reset token'
        ], 422);
    }

    // Find user
    $user = User::find($userId);
    
    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'User not found'
        ], 404);
    }

    // Update password
    $user->password = Hash::make($request->password);
    $user->save();

    // Clear reset token
    Cache::forget("password_reset_{$token}");

    return response()->json([
        'success' => true,
        'message' => 'Password reset successfully! You can now login with your new password.'
    ]);
}

// Check if username or email exists
public function checkUsername(Request $request)
{
    $request->validate([
        'username' => 'required|string'
    ]);

    $input = $request->username;
    
    // Check if input is email or username
    $isEmail = filter_var($input, FILTER_VALIDATE_EMAIL);
    
    if ($isEmail) {
        // Search by email
        $user = User::where('email', $input)->first();
    } else {
        // Search by username
        $user = User::where('username', $input)->first();
    }
    
    return response()->json([
        'exists' => $user !== null,
        'user_type' => $isEmail ? 'email' : 'username',
        'email' => $user ? $user->email : null
    ]);
}

// Forgot Password - Send reset link via email
public function forgotPassword(Request $request)
{
    $request->validate([
        'email' => 'required|string'
    ]);

    $input = $request->email;
    
    // Check if input is email or username
    $isEmail = filter_var($input, FILTER_VALIDATE_EMAIL);
    
    if ($isEmail) {
        // Search by email
        $user = User::where('email', $input)->first();
    } else {
        // Search by username
        $user = User::where('username', $input)->first();
    }
    
    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'If an account with that email/username exists, a password reset link has been sent.'
        ]);
    }

    // Generate password reset token
    $token = Str::random(60);
    
    // Store token in cache with 1 hour expiration
    Cache::put("password_reset_{$token}", $user->id, now()->addHour());
    
    // Send reset email
    try {
        $resetUrl = url("/reset-password?token={$token}");
        
        Mail::raw("Hello {$user->username},\n\nYou requested a password reset for your MEEO System account.\n\nClick the link below to reset your password:\n{$resetUrl}\n\nThis link will expire in 1 hour.\n\nIf you didn't request this reset, please ignore this email.\n\nMEEO System", function ($message) use ($user) {
            $message->to($user->email)
                ->subject('MEEO System - Password Reset Request');
        });

        return response()->json([
            'success' => true,
            'message' => 'Password reset link sent to your email'
        ]);
    } catch (\Exception $e) {
        \Log::error('Password reset email failed: ' . $e->getMessage());
        
        return response()->json([
            'success' => true,
            'message' => 'Password reset token generated (email not configured)',
            'token' => $token, // Remove this in production
            'debug' => 'Email configuration needed. Check your .env file.',
            'reset_url' => url("/reset-password?token={$token}")
        ]);
    }
}

// Send Reset OTP
public function sendResetOTP(Request $request)
{
    $request->validate([
        'email' => 'required|string'  // Removed email validation to accept username
    ]);

    $input = $request->email;
    
    // Check if input is email or username
    $isEmail = filter_var($input, FILTER_VALIDATE_EMAIL);
    
    if ($isEmail) {
        // Search by email
        $user = User::where('email', $input)->first();
    } else {
        // Search by username
        $user = User::where('username', $input)->first();
    }
    
    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'If an account with that email/username exists, an OTP has been sent.'
        ]);
    }

    // Generate 6-digit OTP
    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // Store OTP in cache with 5 minutes expiration
    Cache::put("reset_otp_{$user->id}", $otp, now()->addMinutes(5));
    
    // Send OTP via email
    try {
        Mail::raw("Your password reset OTP code is: {$otp}\n\nThis code will expire in 5 minutes.\n\nIf you didn't request this code, please ignore this email.\n\nMEEO System", function ($message) use ($user) {
            $message->to($user->email)
                ->subject('MEEO System - Password Reset OTP');
        });

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully to your email',
            'email_hint' => 'OTP sent to: ' . substr($user->email, 0, 3) . '***@' . substr($user->email, strpos($user->email, '@') + 1)
        ]);
    } catch (\Exception $e) {
        \Log::error('Reset OTP sending failed: ' . $e->getMessage());
        
        return response()->json([
            'success' => true,
            'message' => 'OTP generated successfully (email not configured)',
            'otp' => $otp, // Remove this in production
            'debug' => 'Email configuration needed. Check your .env file.',
            'email_sent_to' => $user->email
        ]);
    }
}

// Verify Reset OTP
public function verifyResetOTP(Request $request)
{
    $request->validate([
        'email' => 'required|string',  // Removed email validation to accept username
        'otp' => 'required|string|size:6'
    ]);

    $input = $request->email;
    
    // Check if input is email or username
    $isEmail = filter_var($input, FILTER_VALIDATE_EMAIL);
    
    if ($isEmail) {
        // Search by email
        $user = User::where('email', $input)->first();
    } else {
        // Search by username
        $user = User::where('username', $input)->first();
    }
    
    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'User not found'
        ], 404);
    }

    // Get OTP from cache
    $cachedOTP = Cache::get("reset_otp_{$user->id}");
    
    if (!$cachedOTP || $request->otp !== $cachedOTP) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid or expired OTP'
        ], 422);
    }

    // Store verified status in cache for 10 minutes (time to set new password)
    Cache::put("reset_verified_{$user->id}", true, now()->addMinutes(10));
    
    // Clear OTP after successful verification
    Cache::forget("reset_otp_{$user->id}");

    return response()->json([
        'success' => true,
        'message' => 'OTP verified successfully. You can now set your new password.'
    ]);
}

// Reset Password
public function resetPassword(Request $request)
{
    $request->validate([
        'email' => 'required|string',  // Removed email validation to accept username
        'password' => 'required|string|min:8|confirmed',
        'password_confirmation' => 'required|string'
    ]);

    $input = $request->email;
    
    // Check if input is email or username
    $isEmail = filter_var($input, FILTER_VALIDATE_EMAIL);
    
    if ($isEmail) {
        // Search by email
        $user = User::where('email', $input)->first();
    } else {
        // Search by username
        $user = User::where('username', $input)->first();
    }
    
    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'User not found'
        ], 404);
    }

    // Check if user has verified OTP or valid reset token
    $isVerified = Cache::get("reset_verified_{$user->id}");
    
    if (!$isVerified) {
        return response()->json([
            'success' => false,
            'message' => 'Password reset session expired. Please try again.'
        ], 422);
    }

    // Update password
    $user->password = Hash::make($request->password);
    $user->save();

    // Clear all reset-related cache
    Cache::forget("reset_verified_{$user->id}");
    Cache::forget("reset_otp_{$user->id}");

    return response()->json([
        'success' => true,
        'message' => 'Password reset successfully! You can now login with your new password.'
    ]);
}

}