<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request for API.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        try {
            // Use the authenticate method from LoginRequest
            $request->authenticate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Check if the email exists
            if (!$request->emailExists()) {
                return response()->json(['message' => 'Email is invalid'], 401);
            }

            // If email exists but authentication failed, the password is incorrect
            return response()->json(['message' => 'Password is invalid'], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * Destroy an authenticated session (API logout).
     */
    public function destroy(Request $request): JsonResponse
    {
        // Revoke the token that was used to authenticate the current request
        $user = $request->user();

        // If there's no authenticated user, return 401 (Unauthorized)
        if (! $user) {
            return response()->json(['message' => 'Not authenticated'], 401);
        }

        // If the user has a current access token, delete it. Use null checks to avoid errors.
        $currentToken = $user->currentAccessToken();
        if ($currentToken) {
            $currentToken->delete();
        }

        return response()->json([
            'message' => 'Logout successful'
        ]);
    }
}