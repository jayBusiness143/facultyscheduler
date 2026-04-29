<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserProfileController extends Controller
{
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Not authenticated.'], 401);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'location' => $validated['location'] ?? null,
        ]);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user,
        ], 200);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Not authenticated.'], 401);
        }

        $validated = $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
        ]);

        $avatarDirectory = public_path('avatars');
        if (!file_exists($avatarDirectory)) {
            mkdir($avatarDirectory, 0755, true);
        }

        if (!empty($user->profile_picture)) {
            $oldPath = public_path($user->profile_picture);
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        $file = $validated['avatar'];
        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $file->move($avatarDirectory, $filename);

        $user->update([
            'profile_picture' => 'avatars/' . $filename,
        ]);

        return response()->json([
            'message' => 'Profile picture updated successfully.',
            'user' => $user,
        ], 200);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Not authenticated.'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'new_password.confirmed' => 'New password confirmation does not match.',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
        ], 200);
    }
}
