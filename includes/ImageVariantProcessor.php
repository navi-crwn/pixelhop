<?php
/**
 * PixelHop - ImageVariantProcessor
 *
 * Kumpulan fungsi pemrosesan gambar MURNI yang sebelumnya berupa fungsi
 * prosedural global di api/upload.php. Dipindahkan secara additive:
 * body fungsi disalin VERBATIM dari api/upload.php; hanya pengorganisasian
 * yang berubah (fungsi global menjadi method statis).
 *
 * Method di sini TIDAK bergantung pada request/upload state, sehingga bisa
 * diuji secara langsung tanpa bootstrap endpoint.
 *
 * File ini tidak menghasilkan output apa pun saat di-require.
 */

require_once __DIR__ . '/Logger.php';

final class ImageVariantProcessor
{
    /**
     * Generate unique short ID (for fallback or internal use)
     */
    public static function generateShortId($length = 6) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $id = '';
        for ($i = 0; $i < $length; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $id;
    }

    /**
     * Slugify filename to URL-safe string
     * "rumah baru.png" -> "rumah-baru"
     * 
     * Strategy:
     * - Short names (≤15 chars): use full name
     * - Long names (>15 chars): truncate to ~15 chars, try to cut at word boundary
     */
    public static function slugifyFilename($filename) {
        // Remove file extension
        $name = pathinfo($filename, PATHINFO_FILENAME);
        
        // Convert to lowercase
        $slug = mb_strtolower($name, 'UTF-8');
        
        // Replace common characters with dash
        $slug = str_replace(['_', '+', '(', ')', '[', ']', '{', '}', '@', '#', '$', '%', '&', '*', '!', '.'], '-', $slug);
        
        // Replace spaces and multiple dashes with single dash
        $slug = preg_replace('/[\s]+/', '-', $slug);
        
        // Remove any character that is not alphanumeric or dash
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
        
        // Remove multiple consecutive dashes
        $slug = preg_replace('/-+/', '-', $slug);
        
        // Trim dashes from beginning and end
        $slug = trim($slug, '-');
        
        // If slug is empty, return empty
        if (empty($slug)) {
            return '';
        }
        
        // For short names (≤15 chars), use full name
        // For longer names, truncate intelligently
        $maxLength = 15;
        
        if (strlen($slug) > $maxLength) {
            // Try to cut at a word boundary (dash)
            $truncated = substr($slug, 0, $maxLength);
            
            // Find last dash position
            $lastDash = strrpos($truncated, '-');
            
            // If there's a dash in the last 5 characters, cut there for cleaner URL
            if ($lastDash !== false && $lastDash >= ($maxLength - 5)) {
                $slug = substr($slug, 0, $lastDash);
            } else {
                // Otherwise just truncate
                $slug = rtrim($truncated, '-');
            }
        }
        
        return $slug;
    }

    /**
     * Get file extension from mime type
     */
    public static function getExtension($mimeType) {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        return $map[$mimeType] ?? 'jpg';
    }

    /**
     * Validate image file is readable by GD before processing
     * This catches corrupted files that pass MIME type check
     */
    public static function validateImageFile($filepath, $mimeType) {

        $imageInfo = @getimagesize($filepath);
        if ($imageInfo === false) {
            return false;
        }


        if ($imageInfo[0] <= 0 || $imageInfo[1] <= 0) {
            return false;
        }


        $testImage = null;
        switch ($mimeType) {
            case 'image/jpeg':
                $testImage = @imagecreatefromjpeg($filepath);
                break;
            case 'image/png':
                $testImage = @imagecreatefrompng($filepath);
                break;
            case 'image/gif':
                $testImage = @imagecreatefromgif($filepath);
                break;
            case 'image/webp':
                $testImage = @imagecreatefromwebp($filepath);
                break;
        }

        if ($testImage === false || $testImage === null) {

            if (extension_loaded('imagick')) {
                try {
                    $imagick = new Imagick($filepath);
                    $imagick->clear();
                    $imagick->destroy();
                    return 'imagick';
                } catch (Exception $e) {
                    return false;
                }
            }
            return false;
        }


        imagedestroy($testImage);
        return 'gd';
    }

    /**
     * Load image from file - with Imagick fallback
     */
    public static function loadImage($filepath, $mimeType, $useImagick = false) {
        if ($useImagick && extension_loaded('imagick')) {
            try {
                $imagick = new Imagick($filepath);

                $imagick->setImageFormat('png');
                $blob = $imagick->getImageBlob();
                $gdImage = imagecreatefromstring($blob);
                $imagick->clear();
                $imagick->destroy();
                return $gdImage;
            } catch (Exception $e) {
                Logger::error('upload', 'Imagick load failed: ' . $e->getMessage(), [
                    'exception' => get_class($e),
                ]);
                return false;
            }
        }

        switch ($mimeType) {
            case 'image/jpeg':
                return @imagecreatefromjpeg($filepath);
            case 'image/png':
                return @imagecreatefrompng($filepath);
            case 'image/gif':
                return @imagecreatefromgif($filepath);
            case 'image/webp':
                return @imagecreatefromwebp($filepath);
            default:
                return false;
        }
    }

    /**
     * Resize image maintaining aspect ratio
     */
    public static function resizeImage($source, $srcWidth, $srcHeight, $maxWidth, $maxHeight) {

        $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
        $newWidth = (int) ($srcWidth * $ratio);
        $newHeight = (int) ($srcHeight * $ratio);

        $dest = imagecreatetruecolor($newWidth, $newHeight);

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefilledrectangle($dest, 0, 0, $newWidth, $newHeight, $transparent);

        imagecopyresampled($dest, $source, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);

        return $dest;
    }

    /**
     * Save image to file
     */
    public static function saveImage($image, $filepath, $mimeType, $quality) {
        switch ($mimeType) {
            case 'image/jpeg':
                return imagejpeg($image, $filepath, $quality);
            case 'image/png':
                return imagepng($image, $filepath, 9 - (int)($quality / 11));
            case 'image/gif':
                return imagegif($image, $filepath);
            case 'image/webp':
                return imagewebp($image, $filepath, $quality);
            default:
                return false;
        }
    }
}
