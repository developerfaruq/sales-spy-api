<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\UploadedFile;

class ProfileService
{
    public function __construct(
        protected CloudinaryService $cloudinaryService
    ) {}

    /**
     * Update a user's basic profile information.
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->update($data);
        $user->refresh();
        return $user;
    }

    /**
     * Upload a new avatar to Cloudinary and update the user record.
     * Deletes the old avatar from Cloudinary if one exists.
     */
    public function updateAvatar(User $user, UploadedFile $file): User
    {
        // Delete old avatar from Cloudinary if it exists
        if ($user->profile_image_public_id) {
            $this->cloudinaryService->deleteImage($user->profile_image_public_id);
        }

        // Upload new avatar
        $uploaded = $this->cloudinaryService->uploadImage(
            $file->getRealPath(),
            'avatars'
        );

        // Save the URL and public_id to the user
        $user->update([
            'profile_image_url'       => $uploaded['url'],
            'profile_image_public_id' => $uploaded['public_id'],
        ]);

        $user->refresh();
        return $user;
    }

    /**
     * Remove the user's avatar from Cloudinary and clear it from the database.
     */
    public function deleteAvatar(User $user): User
    {
        if ($user->profile_image_public_id) {
            $this->cloudinaryService->deleteImage($user->profile_image_public_id);
        }

        $user->update([
            'profile_image_url'       => null,
            'profile_image_public_id' => null,
        ]);

        $user->refresh();
        return $user;
    }

    /**
     * Change the user's password.
     * Verifies the current password before updating.
     * Returns false if current password is wrong.
     */
    public function changePassword(
        User $user,
        string $currentPassword,
        string $newPassword
    ): bool {
        if (!Hash::check($currentPassword, $user->password)) {
            return false;
        }

        $user->update(['password' => $newPassword]); // Auto-hashed by model cast
        return true;
    }

    /**
     * Get or create notification preferences for a user.
     * firstOrCreate means: if preferences exist return them,
     * if they don't exist yet, create them with all defaults.
     */
    public function getNotificationPreferences(User $user): NotificationPreference
    {
        return NotificationPreference::firstOrCreate(
            ['user_id' => $user->id]
        );
    }

    /**
     * Update notification preferences.
     */
    public function updateNotificationPreferences(
        User $user,
        array $data
    ): NotificationPreference {
        $preferences = $this->getNotificationPreferences($user);
        $preferences->update($data);
        $preferences->refresh();
        return $preferences;
    }
}
