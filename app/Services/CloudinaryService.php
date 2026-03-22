<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Cloudinary\Transformation\Resize;
use Cloudinary\Transformation\Quality;

class CloudinaryService
{
    protected Cloudinary $cloudinary;

    public function __construct()
    {
        $this->cloudinary = new Cloudinary(
            env('CLOUDINARY_URL')
        );
    }

    /**
     * Upload an image file and return its secure URL.
     * Used for profile avatars and payment proof screenshots.
     *
     * @param  string  $filePath   The local temp path of the uploaded file
     * @param  string  $folder     The Cloudinary folder to organize files in
     * @return array   ['url' => '...', 'public_id' => '...']
     */
    public function uploadImage(string $filePath, string $folder = 'general'): array
    {
        $result = $this->cloudinary->uploadApi()->upload($filePath, [
            'folder'         => 'sales-spy/' . $folder,
            'resource_type'  => 'image',
            'quality'        => 'auto',   // Cloudinary auto-optimizes quality
            'fetch_format'   => 'auto',   // Serves WebP to browsers that support it
        ]);

        return [
            'url'       => $result['secure_url'],
            'public_id' => $result['public_id'],
        ];
    }

    /**
     * Delete an image from Cloudinary by its public_id.
     * Used when a user replaces their avatar.
     *
     * @param  string  $publicId
     * @return bool
     */
    public function deleteImage(string $publicId): bool
    {
        $result = $this->cloudinary->uploadApi()->destroy($publicId);
        return $result['result'] === 'ok';
    }
}
