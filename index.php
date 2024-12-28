<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

// Add this near the top of the file, before any HTML output
if (isset($_GET["access"])) {
    $dirName = $_GET["access"];
    if (is_dir($dirName)) {
        logAccess($dirName);
        header("Location: " . $dirName);
        exit();
    }
}

// Handle ZIP download first, before any HTML output
if (isset($_POST["zip"])) {
    // Clean any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    $directory = $_POST["directory"];

    if (is_dir($directory)) {
        $zipFileName = basename($directory) . ".zip";
        $zipFilePath = sys_get_temp_dir() . "/" . $zipFileName;

        // Create the ZIP archive
        $zip = new ZipArchive();
        if (
            $zip->open(
                $zipFilePath,
                ZipArchive::CREATE | ZipArchive::OVERWRITE
            ) === true
        ) {
            // Get real path of the directory
            $realPath = realpath($directory);

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($realPath),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                // Skip dots
                if (
                    $file->getFilename() == "." ||
                    $file->getFilename() == ".."
                ) {
                    continue;
                }

                if (!$file->isDir()) {
                    // Get real path of file
                    $filePath = $file->getRealPath();
                    // Calculate relative path
                    $relativePath = substr($filePath, strlen($realPath) + 1);

                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();

            // Force download
            if (file_exists($zipFilePath)) {
                header("Content-Type: application/zip");
                header(
                    'Content-Disposition: attachment; filename="' .
                        $zipFileName .
                        '"'
                );
                header("Content-Length: " . filesize($zipFilePath));
                readfile($zipFilePath);
                unlink($zipFilePath); // Delete the temp file
                exit();
            }
        }
    }
    // If we get here, something went wrong
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// Handle AJAX requests first
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Don't handle ZIP requests here
    if (isset($_POST["zip"])) {
        return; // Let the ZIP handler below handle this
    }

    // Prevent any output before JSON response
    ob_clean();
    header("Content-Type: application/json");

    // Handle clear recent access
    if (isset($_POST["clearRecentAccess"])) {
        $file = __DIR__ . '/recently_accessed.json';
        if (file_exists($file)) {
            if (unlink($file)) {
                echo json_encode([
                    "success" => true,
                    "message" => "Recent access history cleared"
                ]);
            } else {
                echo json_encode([
                    "success" => false,
                    "message" => "Failed to clear history. Permission denied."
                ]);
            }
        } else {
            echo json_encode([
                "success" => true,
                "message" => "No history to clear"
            ]);
        }
        exit();
    }

    // Handle file upload
    if (isset($_FILES["file"]) && isset($_POST["uploadDir"])) {
        $uploadDir =
            $_POST["uploadDir"] === "uploads"
                ? __DIR__ . "/uploads/"
                : __DIR__ . "/" . $_POST["uploadDir"] . "/";

        // Validate directory path
        if (!is_dir($uploadDir)) {
            try {
                // Create directory with more permissive permissions
                if (!mkdir($uploadDir, 0777, true)) {
                    echo json_encode([
                        "success" => false,
                        "message" =>
                            "Failed to create upload directory. Permission denied.",
                    ]);
                    exit();
                }
                // Set proper permissions after creation
                chmod($uploadDir, 0777);
            } catch (Exception $e) {
                echo json_encode([
                    "success" => false,
                    "message" => "Permission error: " . $e->getMessage(),
                ]);
                exit();
            }
        }

        // Ensure directory is writable
        if (!is_writable($uploadDir)) {
            echo json_encode([
                "success" => false,
                "message" => "Upload directory is not writable",
            ]);
            exit();
        }

        // Validate that the directory is within the allowed path
        $realUploadDir = realpath($uploadDir);
        $realBaseDir = realpath(__DIR__);

        if (strpos($realUploadDir, $realBaseDir) !== 0) {
            echo json_encode([
                "success" => false,
                "message" => "Invalid directory path",
            ]);
            exit();
        }

        $fileName = basename($_FILES["file"]["name"]);
        $targetPath = $uploadDir . $fileName;

        try {
            if (move_uploaded_file($_FILES["file"]["tmp_name"], $targetPath)) {
                // Set proper permissions for the uploaded file
                chmod($targetPath, 0666);
                echo json_encode([
                    "success" => true,
                    "message" =>
                        "File uploaded successfully to " .
                        $_POST["uploadDir"] .
                        "!",
                ]);
            } else {
                echo json_encode([
                    "success" => false,
                    "message" => "File upload failed. Permission denied.",
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Upload error: " . $e->getMessage(),
            ]);
        }
        exit();
    }

    // Handle directory creation
    if (isset($_POST["dirName"])) {
        $newDir =
            __DIR__ .
            "/" .
            preg_replace("/[^a-zA-Z0-9_\-]/", "", $_POST["dirName"]);
        if (!is_dir($newDir)) {
            try {
                if (mkdir($newDir, 0777)) {
                    // Set proper permissions after creation
                    chmod($newDir, 0777);
                    echo json_encode([
                        "success" => true,
                        "message" => "Directory created successfully!",
                    ]);
                } else {
                    echo json_encode([
                        "success" => false,
                        "message" =>
                            "Failed to create directory. Permission denied.",
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode([
                    "success" => false,
                    "message" => "Permission error: " . $e->getMessage(),
                ]);
            }
        } else {
            echo json_encode([
                "success" => false,
                "message" => "Directory already exists.",
            ]);
        }
        exit();
    }

    // Add directory deletion handler here
    if (isset($_POST["deleteDir"])) {
        $dirToDelete = __DIR__ . "/" . preg_replace("/[^a-zA-Z0-9_\-]/", "", $_POST["deleteDir"]);
        
        // Validate that the directory exists and is within allowed path
        $realDeleteDir = realpath($dirToDelete);
        $realBaseDir = realpath(__DIR__);
        
        if (!$realDeleteDir || strpos($realDeleteDir, $realBaseDir) !== 0) {
            echo json_encode([
                "success" => false,
                "message" => "Invalid directory path"
            ]);
            exit();
        }

        try {
            // Recursive function to delete directory and its contents
            function deleteDirectory($dir) {
                if (!file_exists($dir)) return true;
                if (!is_dir($dir)) return unlink($dir);
                foreach (scandir($dir) as $item) {
                    if ($item == '.' || $item == '..') continue;
                    if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
                }
                return rmdir($dir);
            }

            if (deleteDirectory($dirToDelete)) {
                echo json_encode([
                    "success" => true,
                    "message" => "Directory deleted successfully!"
                ]);
            } else {
                echo json_encode([
                    "success" => false,
                    "message" => "Failed to delete directory"
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error: " . $e->getMessage()
            ]);
        }
        exit();
    }

    // Add this new handler
    if (isset($_POST["logAccess"]) && isset($_POST["directory"])) {
        $dirName = $_POST["directory"];
        logAccess($dirName);
        echo json_encode(["success" => true]);
        exit();
    }

    // Add this inside the POST request handler section, before the "Invalid request" response
    if (isset($_POST["oldName"]) && isset($_POST["newName"])) {
        $oldName = __DIR__ . "/" . preg_replace("/[^a-zA-Z0-9_\-]/", "", $_POST["oldName"]);
        $newName = __DIR__ . "/" . preg_replace("/[^a-zA-Z0-9_\-]/", "", $_POST["newName"]);
        
        // Validate paths
        $realOldPath = realpath($oldName);
        $realBaseDir = realpath(__DIR__);
        
        if (!$realOldPath || strpos($realOldPath, $realBaseDir) !== 0) {
            echo json_encode([
                "success" => false,
                "message" => "Invalid source directory path"
            ]);
            exit();
        }
        
        if (file_exists($newName)) {
            echo json_encode([
                "success" => false,
                "message" => "A directory with that name already exists"
            ]);
            exit();
        }
        
        try {
            if (rename($oldName, $newName)) {
                echo json_encode([
                    "success" => true,
                    "message" => "Directory renamed successfully!"
                ]);
            } else {
                echo json_encode([
                    "success" => false,
                    "message" => "Failed to rename directory"
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error: " . $e->getMessage()
            ]);
        }
        exit();
    }

    // If we get here, it's an invalid POST request
    echo json_encode(["success" => false, "message" => "Invalid request"]);
    exit();
}

function logAccess($dirName) {
    $file = __DIR__ . '/recently_accessed.json';
    $data = [];

    // Load existing data
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
    }

    // Add or update access info
    $data[$dirName] = [
        'name' => $dirName,
        'accessed' => date('Y-m-d H:i:s'),
        'new' => true // Mark as "new"
    ];

    // Keep only the last 5 accessed directories
    $data = array_slice($data, -5, 5, true);

    // Save data
    file_put_contents($file, json_encode($data));
}

function getRecentlyAccessed() {
    $file = __DIR__ . '/recently_accessed.json';
    $data = [];
    
    if (file_exists($file)) {
        $jsonContent = file_get_contents($file);
        $decoded = json_decode($jsonContent, true);
        
        // Check if json_decode returned valid data
        if (is_array($decoded)) {
            $data = $decoded;
            
            // Clear "new" flags for old entries
            foreach ($data as &$entry) {
                $entry['new'] = false;
            }
            
            file_put_contents($file, json_encode($data)); // Update file
        }
    }
    
    return $data; // Will return empty array if file doesn't exist or contains invalid JSON
}

$recentDirs = getRecentlyAccessed();

function healthCheck() {
    $checks = [
        'PHP Version' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'OK' : 'Update Required',
        'ZipArchive Extension' => class_exists('ZipArchive') ? 'OK' : 'Missing',
        'Writable Directory' => is_writable(__DIR__) ? 'OK' : 'Not Writable',
        'Recently Accessed File' => is_writable(__DIR__ . '/recently_accessed.json') ? 'OK' : 'Not Writable or Missing',
    ];

    // Highlight issues
    $issues = array_filter($checks, fn($status) => $status !== 'OK');

    return ['checks' => $checks, 'issues' => $issues];
}

$healthStatus = healthCheck();


function getDirectorySize($path)
{
    $size = 0;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path)
    );
    foreach ($items as $item) {
        if ($item->isFile()) {
            $size += $item->getSize();
        }
    }
    return $size;
}
function getFileIcon($fileName, $isFolder = false)
{
    if ($isFolder) {
        return "fas fa-folder text-yellow-500";
    }

    $extension = pathinfo($fileName, PATHINFO_EXTENSION);
    $icons = [
        "pdf" => "fas fa-file-pdf text-red-500",
        "jpg" => "fas fa-file-image text-yellow-500",
        "png" => "fas fa-file-image text-blue-500",
        "txt" => "fas fa-file-alt text-gray-500",
        "html" => "fas fa-code text-green-500",
        "php" => "fab fa-php text-purple-500",
        "js" => "fab fa-js text-yellow-400",
        "css" => "fab fa-css3 text-blue-400",
        // Add more file types as needed
    ];

    return $icons[$extension] ?? "fas fa-file text-gray-400"; // Default icon
}
function formatSize($size)
{
    $units = ["B", "KB", "MB", "GB", "TB"];
    $i = 0;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, 2) . " " . $units[$i];
}

function getLastModified($path)
{
    return date("Y-m-d H:i:s", filemtime($path));
}

function getFileCount($path)
{
    return iterator_count(
        new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS)
    );
}

function getDirectoryPreview($path)
{
    $items = scandir($path);
    $preview = [];
    foreach ($items as $item) {
        if ($item !== "." && $item !== "..") {
            $isDir = is_dir($path . DIRECTORY_SEPARATOR . $item);
            $icon = getFileIcon($item, $isDir); // Pass isDir parameter
            $preview[] = [
                "name" => $item,
                "type" => $isDir ? "folder" : "file",
                "icon" => $icon,
            ];
        }
    }
    return $preview;
}

// Get server information
$serverInfo = [
    "PHP Version" => phpversion(),
    "Server Software" => $_SERVER["SERVER_SOFTWARE"],
    "Document Root" => $_SERVER["DOCUMENT_ROOT"],
    "Server Port" => $_SERVER["SERVER_PORT"],
];

// Get all directories and prepare data for Alpine
$directories = array_filter(glob("*"), "is_dir");
usort($directories, "strcasecmp"); // Sort directories alphabetically

$directoryData = [];
foreach ($directories as $dir) {
    $directoryData[] = [
        "name" => $dir,
        "size" => formatSize(getDirectorySize($dir)),
        "modified" => getLastModified($dir),
        "files" => getFileCount($dir),
        "preview" => getDirectoryPreview($dir),
    ];
}

// Add this inside your POST request handlers section, before any HTML output
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Don't handle ZIP requests here
    if (isset($_POST["zip"])) {
        return; // Let the ZIP handler below handle this
    }

    // Prevent any output before JSON response
    ob_clean();
    header("Content-Type: application/json");

    // Handle clear recent access
    if (isset($_POST["clearRecentAccess"])) {
        $file = __DIR__ . '/recently_accessed.json';
        if (file_exists($file)) {
            if (unlink($file)) {
                echo json_encode([
                    "success" => true,
                    "message" => "Recent access history cleared"
                ]);
            } else {
                echo json_encode([
                    "success" => false,
                    "message" => "Failed to clear history. Permission denied."
                ]);
            }
        } else {
            echo json_encode([
                "success" => true,
                "message" => "No history to clear"
            ]);
        }
        exit();
    }

    // Handle file upload
    if (isset($_FILES["file"]) && isset($_POST["uploadDir"])) {
        // ... existing upload code ...
    }

    // ... rest of your POST handlers ...
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Local Projects Directory</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.9.0/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    // Tailwind Configuration
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    dark: {
                        bg: '#1a1a1a',
                        card: '#2d2d2d'
                    }
                }
            }
        }
    }
    </script>
    <style>
        body {
    padding-top: 64px; /* Adjust based on the top bar's height */
}

        .project-size {
            white-space: nowrap;
        }
        /* Smooth transitions for dark mode */
        body, .bg-white, .bg-gray-50, .shadow-md, .hover\:shadow-xl {
            transition: all 0.3s ease;
        }
        [x-cloak] { display: none !important; }
        .recent-access ul li {
        transition: transform 0.2s ease-in-out, background-color 0.2s ease-in-out;
    }
    .recent-access ul li:hover {
        transform: translateX(5px);
        background-color: rgba(0, 0, 0, 0.05);
    }

    .tooltip {
        position: relative;
    }
    /* Default tooltip (for project cards) */
    .tooltip:not(.tooltip-bottom):hover::after {
        content: attr(data-tooltip);
        position: absolute;
        top: -25px;
        left: 0;
        background: #333;
        color: #fff;
        font-size: 12px;
        padding: 5px 8px;
        border-radius: 4px;
        white-space: nowrap;
        z-index: 50;
    }
    
    /* Bottom tooltip (for top bar) */
    .tooltip-bottom:hover::after {
        content: attr(data-tooltip);
        position: absolute;
        bottom: -30px;
        left: 50%;
        transform: translateX(-50%);
        background: #333;
        color: #fff;
        font-size: 12px;
        padding: 5px 8px;
        border-radius: 4px;
        white-space: nowrap;
        z-index: 50;
    }
    </style>
</head>
<body class="bg-gray-100 min-h-screen font-sans transition-colors duration-200 dark:bg-gray-900">
    <div x-data="{ 
        ...directoryList(),
        showUploadModal: false,
        showCreateDirModal: false,
        showRenameModal: false,
        showAnalyticsModal: false,
        selectedDir: '',
        selectedAnalyticsDir: '',
        analytics: null,
        analyticsError: null,
        chart: null
    }">
        <div class="fixed top-0 left-0 w-full bg-white dark:bg-gray-900 dark:text-white py-3 shadow-lg z-50">
            <div class="container mx-auto flex justify-between items-center px-4">
                <h1 class="text-lg font-bold">PHP Projects</h1>
                <div class="flex space-x-4 items-center">
                    <button 
                        @click="showUploadModal = true" 
                        class="p-2 text-blue-500 hover:text-blue-600 transition-colors duration-200 tooltip tooltip-bottom"
                        data-tooltip="Upload File"
                    >
                        <i class="fas fa-upload text-xl"></i>
                    </button>
                    <button 
                        @click="showCreateDirModal = true" 
                        class="p-2 text-green-500 hover:text-green-600 transition-colors duration-200 tooltip tooltip-bottom"
                        data-tooltip="Create Directory"
                    >
                        <i class="fas fa-folder-plus text-xl"></i>
                    </button>
                    <button 
                        @click="showAnalyticsModal = true" 
                        class="p-2 text-purple-500 hover:text-purple-600 transition-colors duration-200 tooltip tooltip-bottom"
                        data-tooltip="Show Analytics"
                    >
                        <i class="fas fa-chart-pie text-xl"></i>
                    </button>
                    <button 
                        @click="toggleDarkMode()" 
                        class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors duration-200" 
                        title="Toggle Dark Mode"
                    >
                        <template x-if="darkMode">
                            <i class="fas fa-sun text-yellow-400 text-xl"></i>
                        </template>
                        <template x-if="!darkMode">
                            <i class="fas fa-moon text-gray-700 dark:text-gray-300 text-xl"></i>
                        </template>
                    </button>
                </div>
            </div>
        </div>

        <div class="pt-4"></div> <!-- Spacer for fixed bar -->
        <div class="container mx-auto px-4 py-8">

            <!-- Search and Filter -->
            <div class="mb-8 flex flex-col md:flex-row items-center justify-between">
                <div class="mb-4 md:mb-0 md:mr-4 w-full md:w-1/2">
                    <input
                        type="text"
                        x-model="search"
                        placeholder="Search projects..."
                        class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-300 dark:focus:ring-blue-500"
                    >
                </div>
                <div class="flex items-center space-x-4">
                    <select x-model="sortBy" class="px-4 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-300 dark:focus:ring-blue-500">
                        <option value="name">Sort by Name</option>
                        <option value="size">Sort by Size</option>
                        <option value="modified">Sort by Last Modified</option>
                    </select>
                    <select x-model="sortOrder" class="px-4 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-300 dark:focus:ring-blue-500">
                        <option value="asc">Ascending</option>
                        <option value="desc">Descending</option>
                    </select>
                </div>
            </div>
            <div id="recent-access-container" class="recent-access bg-white dark:bg-gray-700 p-4 rounded shadow-md mb-8">
                <div class="flex justify-between items-center mb-2">
                    <h2 class="text-lg font-bold dark:text-gray-100">Recently Accessed</h2>
                    <button 
                        @click="clearRecentAccess()" 
                        class="text-red-500 hover:text-red-600 p-1 rounded-md transition-colors duration-200 tooltip"
                        data-tooltip="Clear History"
                    >
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
                <?php if (empty($recentDirs)): ?>
                    <p class="text-gray-600 dark:text-gray-400">No recently accessed directories</p>
                <?php else: ?>
                    <ul class="space-y-2">
                        <?php foreach ($recentDirs as $dir): ?>
                            <li class="flex items-center justify-between p-2 hover:bg-gray-50 dark:hover:bg-gray-600 rounded">
                                <div class="flex items-center space-x-2">
                                    <i class="fas fa-folder text-yellow-500"></i>
                                    <a href="?access=<?php echo htmlspecialchars($dir['name']); ?>" 
                                       class="text-blue-500 hover:underline">
                                        <?php echo htmlspecialchars($dir['name']); ?>
                                    </a>
                                    <?php if (isset($dir['new']) && $dir['new']): ?>
                                        <span class="px-2 py-0.5 text-xs bg-green-500 text-white rounded-full">New</span>
                                    <?php endif; ?>
                                </div>
                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo date('M j, g:i A', strtotime($dir['accessed'])); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Projects Grid -->
            <main class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <template x-for="dir in sortedAndFilteredDirectories" :key="dir.name">
                    <div class="bg-white rounded-lg shadow-md overflow-hidden hover:shadow-xl transition-shadow duration-300 dark:bg-gray-700 dark:shadow-lg">
                        <div class="p-5">
                            <div class="flex items-center justify-between mb-3">
                                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100" x-text="dir.name"></h2>
                                <span class="inline-flex items-center px-3 py-0.5 rounded-full text-sm font-medium bg-blue-100 text-blue-800 project-size dark:bg-blue-800 dark:text-blue-100">
                                    <i class="fas fa-folder mr-1"></i>
                                    <span x-text="dir.size"></span>
                                </span>
                            </div>
                            <p class="text-sm text-gray-600 mb-3 dark:text-gray-400">
                                Last modified: <span x-text="dir.modified"></span>
                            </p>
                            <p class="text-sm text-gray-600 mb-4 dark:text-gray-400">
                                Files: <span x-text="dir.files"></span>
                            </p>
                            <div x-data="{ isOpen: false }" class="mb-4">
                                <button 
                                    @click="isOpen = !isOpen" 
                                    class="flex items-center justify-between w-full px-3 py-2 text-sm text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-750"
                                >
                                    <span>Show Contents</span>
                                    <i class="fas" :class="isOpen ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                </button>
                                
                                <ul 
                                    x-show="isOpen" 
                                    @click.away="isOpen = false"
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0 transform scale-95"
                                    x-transition:enter-end="opacity-100 transform scale-100"
                                    x-transition:leave="transition ease-in duration-100"
                                    x-transition:leave-start="opacity-100 transform scale-100"
                                    x-transition:leave-end="opacity-0 transform scale-95"
                                    class="mt-2 max-h-60 overflow-y-auto bg-white dark:bg-gray-800 rounded-md shadow-lg border border-gray-200 dark:border-gray-700"
                                >
                                    <template x-for="item in dir.preview" :key="item.name">
                                        <li class="px-3 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center space-x-2">
                                            <i :class="item.icon"></i>
                                            <span x-text="item.name" class="text-sm text-gray-700 dark:text-gray-300"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                            <div class="flex flex-wrap gap-2 justify-between items-center">
                                <a 
                                    :href="dir.name" 
                                    @click="logDirectoryAccess(dir.name)"
                                    class="inline-flex items-center text-blue-500 hover:text-blue-600 p-2 rounded-md transition-colors duration-300 text-sm tooltip"
                                    data-tooltip="Open Directory"
                                >
                                    <i class="fas fa-folder-open text-lg"></i>
                                </a>
                                
                                <a 
                                    :href="'vscode://file/<?php echo $_SERVER["DOCUMENT_ROOT"]; ?>/' + dir.name" 
                                    class="inline-flex items-center text-gray-500 hover:text-gray-600 p-2 rounded-md transition-colors duration-300 text-sm tooltip"
                                    data-tooltip="Open in VS Code"
                                >
                                    <i class="fas fa-code text-lg"></i>
                                </a>
                                
                                <button 
                                    @click="selectedDir = dir.name; showRenameModal = true"
                                    class="inline-flex items-center text-yellow-500 hover:text-yellow-600 p-2 rounded-md transition-colors duration-300 text-sm tooltip"
                                    data-tooltip="Rename Directory"
                                >
                                    <i class="fas fa-edit text-lg"></i>
                                </button>
                                
                                <button 
                                    @click="if(confirm('Are you sure you want to delete this directory and all its contents?')) {
                                        const formData = new FormData();
                                        formData.append('deleteDir', dir.name);
                                        fetch('index.php', {
                                            method: 'POST',
                                            body: formData
                                        })
                                        .then(response => response.json())
                                        .then(data => {
                                            if(data.success) {
                                                alert(data.message);
                                                window.location.reload();
                                            } else {
                                                alert(data.message);
                                            }
                                        })
                                        .catch(error => alert('Error deleting directory: ' + error));
                                    }"
                                    class="inline-flex items-center text-red-500 hover:text-red-600 p-2 rounded-md transition-colors duration-300 text-sm tooltip"
                                    data-tooltip="Delete Directory"
                                >
                                    <i class="fas fa-trash-alt text-lg"></i>
                                </button>
                                
                                <form method="post" action="index.php" class="inline-block">
                                    <input type="hidden" name="directory" :value="dir.name">
                                    <button 
                                        type="submit" 
                                        name="zip" 
                                        class="inline-flex items-center text-green-500 hover:text-green-600 p-2 rounded-md transition-colors duration-300 text-sm tooltip relative"
                                        data-tooltip="Download as ZIP"
                                    >
                                        <i class="fas fa-file-zipper text-lg"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </template>
            </main>
                        <!-- Header -->
            <div class="bg-white mt-12 rounded-lg shadow-md p-6 mb-6 dark:bg-gray-800">
                <div class="flex flex-wrap items-start gap-6">
                    <!-- Health Check Section - Improved styling -->
                    <div class="flex-1 min-w-[300px] bg-white dark:bg-gray-900 rounded-lg shadow-sm p-4 border border-gray-100 dark:border-gray-700">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">System Health Check</h2>
                        <ul class="space-y-2">
                            <?php foreach ($healthStatus['checks'] as $check => $status): ?>
                                <li class="flex items-center justify-between">
                                    <span class="text-gray-700 dark:text-gray-300"><?php echo $check; ?></span>
                                    <span class="px-2 py-1 rounded-full text-sm font-medium <?php echo $status === 'OK' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300'; ?>">
                                        <?php echo $status; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (!empty($healthStatus['issues'])): ?>
                            <div class="mt-4 p-3 bg-red-50 dark:bg-red-900/50 border border-red-200 dark:border-red-800 rounded-md">
                                <p class="text-sm text-red-700 dark:text-red-300 font-medium">
                                    Issues detected. Please resolve them to ensure smooth functionality.
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="mt-4 p-3 bg-green-50 dark:bg-green-900/50 border border-green-200 dark:border-green-800 rounded-md">
                                <p class="text-sm text-green-700 dark:text-green-300 font-medium">
                                    All systems are functional!
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Server Info Pills - Improved layout -->
                    <div class="flex-1 min-w-[200px] grid grid-cols-2 gap-2">
                        <?php foreach ($serverInfo as $key => $value): ?>
                            <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-900 px-3 py-2 rounded-md border border-gray-100 dark:border-gray-700">
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-400"><?php echo $key; ?></span>
                                <span class="text-sm text-gray-800 dark:text-gray-200"><?php echo $value; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
                          
            <!-- Footer -->
            <footer class="mt-12 text-center text-gray-600 dark:text-gray-400">
                <p>Powered by PHP <?php echo phpversion(); ?></p>
            </footer>
        </div>

        <!-- Modal for File Upload -->
        <div 
            x-show="showUploadModal" 
            x-cloak 
            @click.away="showUploadModal = false; document.getElementById('uploadForm').reset()"
            class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
        >
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-1/3 shadow-lg">
                <h2 class="text-lg font-bold mb-4 dark:text-white">Upload File</h2>
                <form id="uploadForm" x-on:submit.prevent="uploadFile" enctype="multipart/form-data">
                    <!-- Add directory selection dropdown -->
                    <div class="mb-4">
                        <label for="uploadDir" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Select Directory
                        </label>
                        <select 
                            id="uploadDir" 
                            name="uploadDir" 
                            required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        >
                            <option value="">Choose a directory...</option>
                            <template x-for="dir in directories" :key="dir.name">
                                <option :value="dir.name" x-text="dir.name"></option>
                            </template>
                            <!-- <option value="uploads">uploads</option> -->
                        </select>
                    </div>
                    
                    <input type="file" id="file" name="file" required class="block w-full mb-4 dark:text-white">
                    <div class="flex justify-end space-x-2">
                        <button 
                            type="button" 
                            @click="showUploadModal = false; document.getElementById('uploadForm').reset()" 
                            class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg"
                        >
                            Cancel
                        </button>
                        <button 
                            type="submit" 
                            class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg"
                        >
                            Upload
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal for Creating Directory -->
        <div 
            x-show="showCreateDirModal" 
            x-cloak 
            @click.away="showCreateDirModal = false; document.getElementById('createDirForm').reset()"
            class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
        >
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-1/3 shadow-lg">
                <h2 class="text-lg font-bold mb-4 dark:text-white">Create Directory</h2>
                <form id="createDirForm" x-on:submit.prevent="createDirectory">
                    <input 
                        type="text" 
                        id="dirName" 
                        name="dirName" 
                        placeholder="Directory Name" 
                        class="block w-full mb-4 px-3 py-2 border border-gray-300 rounded-md dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                    >
                    <div class="flex justify-end space-x-2">
                        <button 
                            type="button" 
                            @click="showCreateDirModal = false; document.getElementById('createDirForm').reset()" 
                            class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg"
                        >
                            Cancel
                        </button>
                        <button 
                            type="submit" 
                            class="px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg"
                        >
                            Create
                        </button>
                    </div>
                </form>
            </div>
        </div>
                
        <!-- Modal for Renaming Directory -->
        <div 
            x-show="showRenameModal" 
            x-cloak 
            @click.away="showRenameModal = false; document.getElementById('renameForm').reset()"
            class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
        >
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-1/3 shadow-lg">
                <h2 class="text-lg font-bold mb-4 dark:text-white">Rename Directory</h2>
                <form id="renameForm" x-on:submit.prevent="renameDirectory">
                    <input type="hidden" id="oldName" name="oldName" x-model="selectedDir">
                    <div class="mb-4">
                        <label for="newName" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            New Name
                        </label>
                        <input 
                            type="text" 
                            id="newName" 
                            name="newName" 
                            :placeholder="selectedDir"
                            required
                            class="block w-full px-3 py-2 border border-gray-300 rounded-md dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        >
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button 
                            type="button" 
                            @click="showRenameModal = false; document.getElementById('renameForm').reset()" 
                            class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg"
                        >
                            Cancel
                        </button>
                        <button 
                            type="submit" 
                            class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg"
                        >
                            Rename
                        </button>
                    </div>
                </form>
            </div>
        </div>
                
        <!-- Modal for Analytics -->
        <div 
            x-show="showAnalyticsModal" 
            x-cloak 
            @click.away="showAnalyticsModal = false"
            class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4"
        >
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 w-full max-w-2xl max-h-[90vh] overflow-y-auto shadow-lg">
                <h2 class="text-lg font-bold mb-4 dark:text-white">Directory Analytics</h2>
                
                <!-- Input for Directory Path -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select Directory:</label>
                    <select 
                        x-model="selectedAnalyticsDir"
                        @change="fetchAnalytics()"
                        class="mt-1 block w-full px-3 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm 
                               focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 
                               text-gray-700 dark:text-gray-200 cursor-pointer
                               appearance-none"
                        style="background-image: url('data:image/svg+xml;charset=US-ASCII,<svg width=\"20\" height=\"20\" fill=\"none\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"M7 7l3 3 3-3\" stroke=\"%236B7280\" stroke-width=\"1.5\" stroke-linecap=\"round\" stroke-linejoin=\"round\"/></svg>');
                               background-repeat: no-repeat;
                               background-position: right 0.5rem center;
                               padding-right: 2.5rem;"
                    >
                        <option value="">Choose a directory...</option>
                        <template x-for="dir in directories" :key="dir.name">
                            <option :value="dir.name" x-text="dir.name"></option>
                        </template>
                    </select>
                </div>

                <!-- Analytics Data -->
                <div x-show="analytics" class="dark:text-gray-200 space-y-2">
                    <p><strong>Total Files:</strong> <span x-text="analytics.total_files"></span></p>
                    <p><strong>Total Directories:</strong> <span x-text="analytics.total_dirs"></span></p>
                    <p><strong>Total Size:</strong> <span x-text="formatSize(analytics.total_size)"></span></p>

                    <!-- File Type Distribution -->
                    <h4 class="mt-4 font-semibold">File Types:</h4>
                    <ul class="grid grid-cols-2 gap-2 text-sm">
                        <template x-for="[type, count] of Object.entries(analytics.file_types)">
                            <li class="flex justify-between items-center p-2 bg-gray-50 dark:bg-gray-700 rounded">
                                <strong x-text="type"></strong>: <span x-text="count"></span>
                            </li>
                        </template>
                    </ul>
                </div>

                <!-- Visualization -->
                <div x-show="analytics" class="mt-4 h-64">
                    <canvas id="fileTypeChart"></canvas>
                </div>

                <!-- Error Message -->
                <p x-show="analyticsError" class="text-red-500 mt-4" x-text="analyticsError"></p>

                <!-- Close Button -->
                <div class="mt-6 flex justify-end">
                    <button 
                        @click="showAnalyticsModal = false; resetAnalytics()" 
                        class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>

        <script>
            // Check for dark mode preference on load
            if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark')
            } else {
                document.documentElement.classList.remove('dark')
            }

            document.addEventListener('DOMContentLoaded', () => {
                const accessedDirs = JSON.parse(localStorage.getItem('recentDirs') || '[]');
                
                function addDirectory(dirName) {
                    const timestamp = new Date().toISOString();
                    accessedDirs.unshift({ name: dirName, accessed: timestamp });
                    localStorage.setItem('recentDirs', JSON.stringify(accessedDirs.slice(0, 5)));
                }

                function displayRecentlyAccessed() {
                    const container = document.querySelector('#recently-accessed-list');
                    if (!container) return;

                    container.innerHTML = accessedDirs.map(dir => `
                        <li class="flex items-center space-x-2">
                            <a href="?access=${dir.name}" class="text-blue-500 hover:underline">${dir.name}</a>
                            <span class="text-sm text-gray-600 dark:text-gray-400">(${dir.accessed})</span>
                        </li>
                    `).join('');
                }

                // Example: Add directory on some event
                document.querySelectorAll('[data-dir-link]').forEach(link => {
                    link.addEventListener('click', () => {
                        const dirName = link.getAttribute('data-dir-name');
                        addDirectory(dirName);
                        displayRecentlyAccessed();
                    });
                });

                displayRecentlyAccessed();
            });

            document.addEventListener('alpine:init', () => {
                Alpine.data('directoryList', () => ({
                    directories: <?php echo json_encode($directoryData); ?>,
                    search: '',
                    sortBy: 'name',
                    sortOrder: 'asc',
                    darkMode: localStorage.theme === 'dark',
                    
                    init() {
                        // Watch for system dark mode changes
                        window.matchMedia('(prefers-color-scheme: dark)')
                            .addEventListener('change', e => {
                                if (!localStorage.theme) {
                                    this.darkMode = e.matches;
                                    this.updateDarkMode();
                                }
                            });
                    },
                    
                    toggleDarkMode() {
                        this.darkMode = !this.darkMode;
                        this.updateDarkMode();
                    },
                    
                    updateDarkMode() {
                        if (this.darkMode) {
                            document.documentElement.classList.add('dark');
                            localStorage.theme = 'dark';
                        } else {
                            document.documentElement.classList.remove('dark');
                            localStorage.theme = 'light';
                        }
                    },
                    
                    // Your existing sorting and filtering methods remain the same
                    get sortedAndFilteredDirectories() {
                        return this.directories
                            .filter(dir => dir.name.toLowerCase().includes(this.search.toLowerCase()))
                            .sort((a, b) => {
                                let comparison = 0;
                                if (this.sortBy === 'name') comparison = a.name.localeCompare(b.name);
                                else if (this.sortBy === 'size') comparison = this.compareSizes(a.size, b.size);
                                else if (this.sortBy === 'modified') comparison = new Date(a.modified) - new Date(b.modified);
                                return this.sortOrder === 'asc' ? comparison : comparison * -1;
                            });
                    },

                    compareSizes(sizeA, sizeB) {
                        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                        const getBaseSize = (size) => {
                            const parts = size.split(' ');
                            const value = parseFloat(parts[0]);
                            const unit = parts[1];
                            return value * Math.pow(1024, units.indexOf(unit));
                        };
                        return getBaseSize(sizeA) - getBaseSize(sizeB);
                    },
                    uploadFile() {
                        const formData = new FormData(document.getElementById('uploadForm'));
                        
                        // Validate directory selection
                        const selectedDir = formData.get('uploadDir');
                        if (!selectedDir) {
                            alert('Please select a directory');
                            return;
                        }
                        
                        fetch('index.php', { 
                            method: 'POST', 
                            body: formData 
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.message);
                                window.location.reload(); // Refresh the page
                                this.showUploadModal = false;
                                document.getElementById('uploadForm').reset();
                            } else {
                                alert(data.message);
                            }
                        })
                        .catch(error => {
                            alert('Error uploading file: ' + error);
                        });
                    },

                    createDirectory() {
                        const formData = new FormData(document.getElementById('createDirForm'));
                        fetch('index.php', { 
                            method: 'POST', 
                            body: formData 
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.message);
                                window.location.reload(); // Refresh the page
                                this.showCreateDirModal = false;
                                document.getElementById('createDirForm').reset();
                            } else {
                                alert(data.message);
                            }
                        });
                    },

                    logDirectoryAccess(dirName) {
                        fetch('index.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `logAccess=true&directory=${encodeURIComponent(dirName)}`
                        });
                    },

                    renameDirectory() {
                        const formData = new FormData(document.getElementById('renameForm'));
                        fetch('index.php', { 
                            method: 'POST', 
                            body: formData 
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.message);
                                window.location.reload();
                                this.showRenameModal = false;
                                document.getElementById('renameForm').reset();
                            } else {
                                alert(data.message);
                            }
                        })
                        .catch(error => {
                            alert('Error renaming directory: ' + error);
                        });
                    },

                    fetchAnalytics() {
                        if (!this.selectedAnalyticsDir) {
                            this.analyticsError = "Please select a directory.";
                            return;
                        }

                        fetch('directory_analytics.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ path: this.selectedAnalyticsDir })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.error) {
                                this.analyticsError = data.error;
                                return;
                            }
                            this.analytics = data;
                            this.analyticsError = null;
                            this.$nextTick(() => this.renderChart());
                        })
                        .catch(error => {
                            this.analyticsError = "An error occurred while fetching analytics.";
                        });
                    },

                    resetAnalytics() {
                        this.analytics = null;
                        this.analyticsError = null;
                        this.selectedAnalyticsDir = '';
                        if (this.chart) {
                            this.chart.destroy();
                            this.chart = null;
                        }
                    },

                    renderChart() {
                        if (this.chart) {
                            this.chart.destroy();
                        }

                        const ctx = document.getElementById('fileTypeChart').getContext('2d');
                        this.chart = new Chart(ctx, {
                            type: 'pie',
                            data: {
                                labels: Object.keys(this.analytics.file_types),
                                datasets: [{
                                    label: 'File Types',
                                    data: Object.values(this.analytics.file_types),
                                    backgroundColor: ['#4A90E2', '#50E3C2', '#F5A623', '#D0021B', '#BD10E0'],
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: {
                                        position: 'bottom',
                                    },
                                },
                            },
                        });
                    },

                    clearRecentAccess() {
                        if (confirm('Are you sure you want to clear the recent access history?')) {
                            fetch('index.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: 'clearRecentAccess=true'
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    window.location.reload();
                                } else {
                                    alert('Error clearing history');
                                }
                            })
                            .catch(error => {
                                alert('Error clearing history: ' + error);
                            });
                        }
                    }
                }));
            });
        </script>
    </div>
</body>
</html>
