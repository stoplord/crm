<?php


// ==========================================
// 1. SANITIZATION & OUTPUT ESCAPING
// ==========================================


function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    $data = trim($data);
    $data = stripslashes($data);
    return $data;
}


function escapeOutput($data) {
    if ($data === null) return '';
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// ==========================================
// 2. BACKEND DATA VALIDATION
// ==========================================


function validateIndianMobile($mobile) {
    return preg_match('/^[6-9]\d{9}$/', $mobile) === 1;
}


function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}


function validateAadhaar($aadhaar) {
    return preg_match('/^\d{12}$/', $aadhaar) === 1;
}


function validatePAN($pan) {
    return preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/i', $pan) === 1;
}


function validatePrice($price) {
    return is_numeric($price) && $price >= 0;
}

// ==========================================
// 3. SECURE FILE UPLOADS
// ==========================================


function uploadSecureDocument($fileArray, $destinationFolder, $maxSize = 5242880) {
    // 1. Check for basic upload errors
    if (!isset($fileArray['error']) || is_array($fileArray['error'])) {
        return ['error' => 'Invalid parameters.'];
    }
    if ($fileArray['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'File upload error code: ' . $fileArray['error']];
    }

    // 2. Validate file size
    if ($fileArray['size'] > $maxSize) {
        return ['error' => 'File size exceeded limit of ' . ($maxSize / 1048576) . 'MB.'];
    }

    // 3. Strict MIME Type validation using finfo (Never trust $_FILES['type'])
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($fileArray['tmp_name']);
    
    $allowedMimeTypes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp'
    ];

    $ext = array_search($mime, $allowedMimeTypes, true);
    if ($ext === false) {
        return ['error' => 'Invalid file format. Only JPG, PNG, and WEBP are allowed.'];
    }

    // 4. Generate random secure filename (Discard original filename)
    $safeFilename = bin2hex(random_bytes(16)) . '.' . $ext;
    
    // Ensure destination directory has a trailing slash
    $destinationFolder = rtrim($destinationFolder, '/') . '/';
    $targetFilePath = $destinationFolder . $safeFilename;

    // 5. Move the uploaded file
    if (!move_uploaded_file($fileArray['tmp_name'], $targetFilePath)) {
        return ['error' => 'Failed to move uploaded file.'];
    }

    // Return just the newly generated filename to store in the DB
    return $safeFilename;
}

// ==========================================
// 4. DATE, TIME & FORMATTING
// ==========================================


function formatDate($date) {
    if (empty($date)) return '';
    return date('d M Y', strtotime($date));
}


function formatDateTime($datetime) {
    if (empty($datetime)) return '';
    return date('d M Y, h:i A', strtotime($datetime));
}


function formatWorkingHours($totalSeconds) {
    $hours = floor($totalSeconds / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    $seconds = $totalSeconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

// ==========================================
// 5. BUSINESS LOGIC HELPERS
// ==========================================


function getWorkingTimeStatus($totalSeconds, $targetSeconds = 21600) {
    if ($totalSeconds >= $targetSeconds) {
        return 'Excellent'; // 6+ hours
    } elseif ($totalSeconds >= ($targetSeconds - 3600)) {
        return 'Good'; // 5 to <6 hours
    } elseif ($totalSeconds >= ($targetSeconds - 10800)) {
        return 'Below Average'; // 3 to <5 hours
    } else {
        return 'Low'; // <3 hours
    }
}


function getTemperatureBadge($temp) {
    if (strtoupper($temp) === 'HOT') {
        return '<span class="badge bg-danger">🔥 HOT</span>';
    }
    return '<span class="badge bg-info text-dark">❄️ COLD</span>';
}


function getDealStatusBadge($status) {
    switch (strtolower($status)) {
        case 'open':
            return '<span class="badge bg-primary">Open</span>';
        case 'in progress':
            return '<span class="badge bg-warning text-dark">In Progress</span>';
        case 'converted':
            return '<span class="badge bg-success">Converted</span>';
        case 'rejected':
            return '<span class="badge bg-danger">Rejected</span>';
        case 'on hold':
            return '<span class="badge bg-secondary">On Hold</span>';
        default:
            return '<span class="badge bg-light text-dark">' . escapeOutput($status) . '</span>';
    }
}
?>