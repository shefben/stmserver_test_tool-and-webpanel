<?php
/**
 * API endpoint for uploading images for test notes
 * Accepts image upload, creates thumbnail, stores in uploads directory
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Start session for web auth
session_start();

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Check authentication (session for web UI)
$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = 'No file uploaded';
    if (isset($_FILES['image'])) {
        switch ($_FILES['image']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg = 'File is too large';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMsg = 'File was only partially uploaded';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMsg = 'No file was uploaded';
                break;
        }
    }
    http_response_code(400);
    echo json_encode(['error' => $errorMsg]);
    exit;
}

$file = $_FILES['image'];

// Validate file type (only images)
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Only JPEG, PNG, GIF, and WebP images are allowed']);
    exit;
}

// Validate file size (max 5MB)
$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'File is too large. Maximum size is 5MB.']);
    exit;
}

// Create uploads directory if it doesn't exist
$uploadsDir = __DIR__ . '/../uploads/images';
$thumbsDir = __DIR__ . '/../uploads/thumbnails';

if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}
if (!is_dir($thumbsDir)) {
    mkdir($thumbsDir, 0755, true);
}

// Generate unique filename
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!$extension) {
    // Determine extension from mime type
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    $extension = $extensions[$mimeType] ?? 'jpg';
}

$uniqueId = uniqid() . '_' . bin2hex(random_bytes(4));
$filename = $uniqueId . '.' . $extension;
$fullPath = $uploadsDir . '/' . $filename;
$thumbPath = $thumbsDir . '/' . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save uploaded file']);
    exit;
}

// Create thumbnail
$thumbWidth = 150;
$thumbHeight = 150;

if (!createThumbnail($fullPath, $thumbPath, $thumbWidth, $thumbHeight, $mimeType)) {
    // If thumbnail fails, just copy the original as thumb
    copy($fullPath, $thumbPath);
}

// Build URLs
$baseUrl = getBaseUrl();
$imageUrl = $baseUrl . '/uploads/images/' . $filename;
$thumbnailUrl = $baseUrl . '/uploads/thumbnails/' . $filename;

echo json_encode([
    'success' => true,
    'url' => $imageUrl,
    'thumbnail_url' => $thumbnailUrl,
    'filename' => $filename,
    'size' => $file['size']
]);

/**
 * Create a thumbnail from an image
 */
function createThumbnail($sourcePath, $destPath, $maxWidth, $maxHeight, $mimeType) {
    // Get original dimensions
    $imageInfo = getimagesize($sourcePath);
    if (!$imageInfo) {
        return false;
    }

    $origWidth = $imageInfo[0];
    $origHeight = $imageInfo[1];

    // Calculate new dimensions maintaining aspect ratio
    $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);

    // Don't upscale
    if ($ratio >= 1) {
        return copy($sourcePath, $destPath);
    }

    $newWidth = (int)($origWidth * $ratio);
    $newHeight = (int)($origHeight * $ratio);

    // Create image resource based on type
    switch ($mimeType) {
        case 'image/jpeg':
            $source = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $source = imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $source = imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $source = imagecreatefromwebp($sourcePath);
            break;
        default:
            return false;
    }

    if (!$source) {
        return false;
    }

    // Create thumbnail image
    $thumb = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency for PNG and GIF
    if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
        imagefill($thumb, 0, 0, $transparent);
    }

    // Resize
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

    // Save thumbnail
    $result = false;
    switch ($mimeType) {
        case 'image/jpeg':
            $result = imagejpeg($thumb, $destPath, 85);
            break;
        case 'image/png':
            $result = imagepng($thumb, $destPath, 8);
            break;
        case 'image/gif':
            $result = imagegif($thumb, $destPath);
            break;
        case 'image/webp':
            $result = imagewebp($thumb, $destPath, 85);
            break;
    }

    // Clean up
    imagedestroy($source);
    imagedestroy($thumb);

    return $result;
}
