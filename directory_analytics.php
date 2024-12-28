<?php
header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['path'])) {
    echo json_encode(['error' => 'No path provided']);
    exit;
}

$path = __DIR__ . '/' . $input['path'];

// Validate path
if (!is_dir($path)) {
    echo json_encode(['error' => 'Invalid directory path']);
    exit;
}

// Validate that the directory is within the allowed path
$realPath = realpath($path);
$realBaseDir = realpath(__DIR__);

if (strpos($realPath, $realBaseDir) !== 0) {
    echo json_encode(['error' => 'Invalid directory path']);
    exit;
}

try {
    $analytics = [
        'total_files' => 0,
        'total_dirs' => 0,
        'total_size' => 0,
        'file_types' => [],
    ];

    // Recursive directory iterator
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            $analytics['total_dirs']++;
        } else {
            $analytics['total_files']++;
            $analytics['total_size'] += $item->getSize();

            // Get file extension
            $extension = strtolower($item->getExtension());
            if (empty($extension)) {
                $extension = 'no extension';
            }

            // Count file types
            if (!isset($analytics['file_types'][$extension])) {
                $analytics['file_types'][$extension] = 0;
            }
            $analytics['file_types'][$extension]++;
        }
    }

    // Sort file types by count
    arsort($analytics['file_types']);

    // Limit to top 10 file types
    $analytics['file_types'] = array_slice($analytics['file_types'], 0, 10, true);

    echo json_encode($analytics);

} catch (Exception $e) {
    echo json_encode([
        'error' => 'Error analyzing directory: ' . $e->getMessage()
    ]);
}
