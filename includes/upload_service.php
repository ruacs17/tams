<?php
// Secure File Upload & Anti-Malware Inspection Service
// Teacher Attendance Monitoring System (TAMS)

define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/evidence/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10 MB

/**
 * Handle secure evidence file upload
 * Returns array with ['success' => bool, 'filePath' => string, 'error' => string]
 */
function handleEvidenceUpload(array $file): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'error' => 'Invalid file upload parameter.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload error code: ' . $file['error']];
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => 'File exceeds 10MB maximum limit.'];
    }

    $tmpPath = $file['tmp_name'];

    // Strict Extension Validation
    $originalName = $file['name'] ?? '';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];

    if (!in_array($ext, $allowedExtensions, true)) {
        return ['success' => false, 'error' => 'Disallowed file extension. Only JPG, PNG, and PDF files are allowed.'];
    }

    // Real MIME-type inspection using finfo_file
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    $validMimes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'pdf'  => 'application/pdf',
    ];

    if (!isset($validMimes[$ext]) || $mimeType !== $validMimes[$ext]) {
        return ['success' => false, 'error' => 'File MIME type does not match allowed extension (' . $mimeType . ').'];
    }

    // Ensure upload directory exists
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    // Cryptographic random filename to prevent path traversal and overwrite
    $newFileName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = UPLOAD_DIR . $newFileName;
    $relativeDbPath = 'uploads/evidence/' . $newFileName;

    // Image Payload Neutralization via GD library
    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $imgData = file_get_contents($tmpPath);
        if ($imgData === false) {
            return ['success' => false, 'error' => 'Failed to read uploaded image.'];
        }

        $imageResource = @imagecreatefromstring($imgData);
        if ($imageResource === false) {
            return ['success' => false, 'error' => 'Corrupted or invalid image file.'];
        }

        // Re-save image to strip EXIF and embedded scripts
        $saved = false;
        if ($ext === 'png') {
            // Preserve transparency for PNG
            imagealphablending($imageResource, false);
            imagesavealpha($imageResource, true);
            $saved = imagepng($imageResource, $destination, 8);
        } else {
            $saved = imagejpeg($imageResource, $destination, 90);
        }
        imagedestroy($imageResource);

        if (!$saved) {
            return ['success' => false, 'error' => 'Failed to sanitize and write image file.'];
        }
    } else {
        // PDF File: verify PDF header bytes '%PDF-'
        $header = file_get_contents($tmpPath, false, null, 0, 5);
        if ($header !== '%PDF-') {
            return ['success' => false, 'error' => 'Malformed PDF file header.'];
        }

        if (!move_uploaded_file($tmpPath, $destination)) {
            return ['success' => false, 'error' => 'Failed to move uploaded PDF file.'];
        }
    }

    return ['success' => true, 'filePath' => $relativeDbPath, 'fileName' => $newFileName];
}
