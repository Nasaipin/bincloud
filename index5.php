<?php
session_start();

// --- DATABASE CONFIGURATION ---
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'bincloud_music';

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create tables if they don't exist
function initializeDatabase($conn) {
    // Check if tables exist, if not create them
    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $conn->query("
        CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) UNIQUE NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Updated songs table with thumbnail column
    $conn->query("
        CREATE TABLE IF NOT EXISTS songs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            artist VARCHAR(200) NOT NULL,
            category_id INT,
            file_path VARCHAR(500),
            thumbnail_path VARCHAR(500),
            file_size INT,
            duration VARCHAR(10),
            plays INT DEFAULT 0,
            uploaded_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
            FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    
    // Insert default admin if not exists
    $check_admin = $conn->query("SELECT * FROM users WHERE username = 'bincloud'");
    if ($check_admin->num_rows == 0) {
        $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
        $conn->query("INSERT INTO users (username, password, email) VALUES ('bincloud', '$hashed_password', 'admin@bincloud.com')");
    }
    
    // Insert default categories if not exists
    $check_cats = $conn->query("SELECT * FROM categories");
    if ($check_cats->num_rows == 0) {
        $categories = [
            ['Afrobeats', 'African pop music with rhythmic beats'],
            ['Hip-Hop', 'Rap and urban music'],
            ['Gospel', 'Religious and inspirational music'],
            ['Highlife', 'Traditional West African music'],
            ['R&B', 'Rhythm and blues'],
            ['Dancehall', 'Jamaican dance music'],
            ['Reggae', 'Jamaican roots music'],
            ['Pop', 'Popular mainstream music']
        ];
        
        $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
        foreach ($categories as $cat) {
            $stmt->bind_param("ss", $cat[0], $cat[1]);
            $stmt->execute();
        }
        $stmt->close();
    }
    
    // Insert sample songs if none exist
    $check_songs = $conn->query("SELECT * FROM songs");
    if ($check_songs->num_rows == 0) {
        // Get category IDs
        $cat_ids = [];
        $cat_result = $conn->query("SELECT id, name FROM categories");
        while($row = $cat_result->fetch_assoc()) {
            $cat_ids[$row['name']] = $row['id'];
        }
        
        // Sample songs data
        $sample_songs = [
            ['Rush', 'Ayra Starr', 'Afrobeats', '3:45', 12500],
            ['City Boys', 'Burna Boy', 'Afrobeats', '2:33', 15200],
            ['Me & U', 'Tems', 'R&B', '3:12', 8900],
            ['Holy Ghost', 'Tope Alabi', 'Gospel', '5:20', 6700],
            ['Blessings', 'Victor Thompson', 'Gospel', '4:28', 5400],
            ['Stamina', 'Kizz Daniel', 'Afrobeats', '2:55', 7800],
            ['Lonely At The Top', 'Asake', 'Hip-Hop', '3:20', 9200],
            ['Ngozi', 'Crayon', 'Afrobeats', '3:45', 6300],
            ['Bad To Me', 'Wizkid', 'Afrobeats', '3:15', 11000],
            ['Who Is Your Guy', 'Spyro', 'Afrobeats', '3:20', 4800],
            ['Sittin\' On Top Of The World', 'Burna Boy', 'Hip-Hop', '3:40', 7600],
            ['Peace Be Unto You', 'Judikay', 'Gospel', '4:15', 3200],
            ['Monalisa', 'Lojay', 'Afrobeats', '3:20', 14500],
            ['KU LO SA', 'Oxlade', 'Afrobeats', '2:55', 8300],
            ['Last Last', 'Burna Boy', 'Hip-Hop', '2:52', 18900]
        ];
        
        // Use a placeholder file path for sample songs
        $upload_dir = 'uploads/';
        $thumbnail_dir = 'thumbnails/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        if (!file_exists($thumbnail_dir)) {
            mkdir($thumbnail_dir, 0777, true);
        }
        
        $stmt = $conn->prepare("INSERT INTO songs (title, artist, category_id, file_path, thumbnail_path, duration, plays) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($sample_songs as $song) {
            $cat_id = $cat_ids[$song[2]];
            $file_path = $upload_dir . 'sample_' . time() . '_' . uniqid() . '.mp3';
            $thumbnail_path = $thumbnail_dir . 'default_thumbnail.jpg';
            
            // Create a small dummy file for sample songs
            file_put_contents($file_path, 'Sample audio content');
            
            $stmt->bind_param("ssisssi", $song[0], $song[1], $cat_id, $file_path, $thumbnail_path, $song[3], $song[4]);
            $stmt->execute();
        }
        $stmt->close();
    }
}

// Initialize database
initializeDatabase($conn);

// --- FIXED DOWNLOAD HANDLER ---
if (isset($_GET['download'])) {
    $file_id = (int)$_GET['download'];
    
    // Get file info from database
    $stmt = $conn->prepare("SELECT title, artist, file_path FROM songs WHERE id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $song = $result->fetch_assoc();
        
        // Check if file exists
        if (file_exists($song['file_path'])) {
            // Update play count
            $conn->query("UPDATE songs SET plays = plays + 1 WHERE id = $file_id");
            
            // Clean filename
            $file_name = $song['title'] . ' - ' . $song['artist'] . '.mp3';
            $file_name = preg_replace('/[^a-zA-Z0-9\-\_\.]/', '_', $file_name);
            
            // Set headers for download
            header('Content-Description: File Transfer');
            header('Content-Type: audio/mpeg');
            header('Content-Disposition: attachment; filename="' . $file_name . '"');
            header('Content-Length: ' . filesize($song['file_path']));
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Expires: 0');
            
            // Clear output buffer
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Output file
            readfile($song['file_path']);
            exit;
        } else {
            // File doesn't exist, show error page
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <title>Download Error - BINcloud</title>
                <script src="https://cdn.tailwindcss.com"></script>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
                <style>
                    body { background: #020617; color: #f8fafc; font-family: 'Inter', sans-serif; }
                </style>
            </head>
            <body class="flex items-center justify-center min-h-screen">
                <div class="text-center p-8 glass rounded-2xl max-w-md">
                    <i class="fas fa-exclamation-circle text-6xl text-red-500 mb-4"></i>
                    <h1 class="text-2xl font-bold mb-2">Download Failed</h1>
                    <p class="text-slate-400 mb-6">The audio file could not be found. It may have been moved or deleted.</p>
                    <a href="index.php" class="inline-block bg-blue-600 hover:bg-blue-700 px-6 py-3 rounded-lg font-bold transition">
                        <i class="fas fa-home mr-2"></i> Return Home
                    </a>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    } else {
        // Song ID not found in database
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Download Error - BINcloud</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        </head>
        <body class="flex items-center justify-center min-h-screen" style="background: #020617; color: #f8fafc;">
            <div class="text-center p-8 glass rounded-2xl max-w-md">
                <i class="fas fa-music text-6xl text-blue-500 mb-4"></i>
                <h1 class="text-2xl font-bold mb-2">Song Not Found</h1>
                <p class="text-slate-400 mb-6">The requested song could not be found in our database.</p>
                <a href="index.php" class="inline-block bg-blue-600 hover:bg-blue-700 px-6 py-3 rounded-lg font-bold transition">
                    <i class="fas fa-home mr-2"></i> Return Home
                </a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    $stmt->close();
}

// --- ADMIN AUTHENTICATION ---
$login_error = '';
$reset_message = '';

// --- LOGIN LOGIC ---
if (isset($_POST['login'])) {
    $username = $conn->real_escape_string($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password'])) {
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header("Location: " . $_SERVER['PHP_SELF'] . "?page=admin");
            exit();
        } else {
            $login_error = "Invalid password!";
        }
    } else {
        $login_error = "Username not found!";
    }
    $stmt->close();
}

// --- PASSWORD RESET LOGIC ---
if (isset($_POST['reset_password'])) {
    $username = $conn->real_escape_string($_POST['reset_username'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Check if username exists
    $stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        if (password_verify($current_password, $user['password'])) {
            if ($new_password === $confirm_password) {
                if (strlen($new_password) >= 6) {
                    $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $update_stmt->bind_param("si", $hashed_new_password, $user['id']);
                    
                    if ($update_stmt->execute()) {
                        $reset_message = "Password reset successfully! You can now login with your new password.";
                        // Clear the form
                        $_POST = array();
                    } else {
                        $reset_message = "Error resetting password. Please try again.";
                    }
                    $update_stmt->close();
                } else {
                    $reset_message = "New password must be at least 6 characters long.";
                }
            } else {
                $reset_message = "New passwords do not match.";
            }
        } else {
            $reset_message = "Current password is incorrect.";
        }
    } else {
        $reset_message = "Username not found.";
    }
    $stmt->close();
}

// --- LOGOUT LOGIC ---
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// --- UPLOAD MUSIC LOGIC (Protected) ---
$upload_message = '';
$upload_error = '';

if (isset($_POST['upload']) && isset($_SESSION['admin_id'])) {
    $title = $conn->real_escape_string($_POST['title']);
    $artist = $conn->real_escape_string($_POST['artist']);
    $category_id = (int)$_POST['category_id'];
    $duration = $conn->real_escape_string($_POST['duration']);
    
    // Create directories if they don't exist
    $upload_dir = 'uploads/';
    $thumbnail_dir = 'thumbnails/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    if (!file_exists($thumbnail_dir)) {
        mkdir($thumbnail_dir, 0777, true);
    }
    
    $target_path = '';
    $thumbnail_path = '';
    
    // Handle audio file upload
    if (isset($_FILES['music_file']) && $_FILES['music_file']['error'] == 0) {
        $allowed_audio_types = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/x-m4a'];
        $file_type = $_FILES['music_file']['type'];
        $file_size = $_FILES['music_file']['size'];
        $max_size = 50 * 1024 * 1024; // 50MB max
        
        if ($file_size > $max_size) {
            $upload_error = "File too large. Maximum size is 50MB.";
        } elseif (!in_array($file_type, $allowed_audio_types) && !str_contains($file_type, 'audio')) {
            $upload_error = "Invalid file type. Only audio files are allowed.";
        } else {
            // Generate unique filename
            $file_extension = pathinfo($_FILES['music_file']['name'], PATHINFO_EXTENSION);
            $file_name = time() . '_' . uniqid() . '.' . $file_extension;
            $target_path = $upload_dir . $file_name;
            
            if (!move_uploaded_file($_FILES['music_file']['tmp_name'], $target_path)) {
                $upload_error = "Failed to upload audio file.";
            }
        }
    } else {
        $upload_error = "Please select an audio file to upload.";
    }
    
    // Handle thumbnail upload if no audio errors
    if (empty($upload_error) && isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] == 0) {
        $allowed_image_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $image_type = $_FILES['thumbnail']['type'];
        $image_size = $_FILES['thumbnail']['size'];
        $max_image_size = 5 * 1024 * 1024; // 5MB max
        
        if ($image_size > $max_image_size) {
            $upload_error = "Thumbnail too large. Maximum size is 5MB.";
        } elseif (!in_array($image_type, $allowed_image_types)) {
            $upload_error = "Invalid image type. Only JPG, PNG, GIF, and WEBP are allowed.";
        } else {
            $image_extension = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
            $image_name = time() . '_' . uniqid() . '.' . $image_extension;
            $thumbnail_path = $thumbnail_dir . $image_name;
            
            if (!move_uploaded_file($_FILES['thumbnail']['tmp_name'], $thumbnail_path)) {
                $upload_error = "Failed to upload thumbnail.";
            }
        }
    } else if (empty($upload_error)) {
        // Set default thumbnail if none uploaded
        $thumbnail_path = $thumbnail_dir . 'default_thumbnail.jpg';
        if (!file_exists($thumbnail_path)) {
            // Create a simple default thumbnail if it doesn't exist
            $default_image = imagecreate(300, 300);
            $bg_color = imagecolorallocate($default_image, 59, 130, 246);
            $text_color = imagecolorallocate($default_image, 255, 255, 255);
            imagestring($default_image, 5, 110, 140, "BINcloud", $text_color);
            imagejpeg($default_image, $thumbnail_path);
            imagedestroy($default_image);
        }
    }
    
    // Insert into database if no errors
    if (empty($upload_error) && !empty($target_path)) {
        $stmt = $conn->prepare("
            INSERT INTO songs (title, artist, category_id, file_path, thumbnail_path, file_size, duration, uploaded_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssissisi", $title, $artist, $category_id, $target_path, $thumbnail_path, $file_size, $duration, $_SESSION['admin_id']);
        
        if ($stmt->execute()) {
            $upload_message = "Song uploaded successfully!";
        } else {
            $upload_error = "Database error: " . $conn->error;
            // Delete uploaded files if database insert fails
            if (file_exists($target_path)) unlink($target_path);
            if (file_exists($thumbnail_path) && strpos($thumbnail_path, 'default') === false) unlink($thumbnail_path);
        }
        $stmt->close();
    } else if (!empty($target_path) && file_exists($target_path)) {
        // Clean up audio file if there was an error
        unlink($target_path);
    }
}

// --- DELETE SONG LOGIC (Protected) ---
if (isset($_GET['delete_id']) && isset($_SESSION['admin_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    
    // Get file paths first
    $stmt = $conn->prepare("SELECT file_path, thumbnail_path FROM songs WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($song = $result->fetch_assoc()) {
        // Delete physical files if they exist and aren't default thumbnails
        if (file_exists($song['file_path'])) {
            unlink($song['file_path']);
        }
        if (file_exists($song['thumbnail_path']) && strpos($song['thumbnail_path'], 'default') === false) {
            unlink($song['thumbnail_path']);
        }
        
        // Delete from database
        $stmt = $conn->prepare("DELETE FROM songs WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?page=admin&deleted=1");
    exit();
}

// Get current page
$page = isset($_GET['page']) ? $_GET['page'] : 'home';

// Get category filter
$selected_cat = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;

// Fetch categories
$categories_result = $conn->query("SELECT * FROM categories ORDER BY name");

// Fetch songs based on filters (updated to include thumbnail_path)
$song_query = "SELECT songs.*, categories.name as cat_name, users.username as uploaded_by_name 
               FROM songs 
               LEFT JOIN categories ON songs.category_id = categories.id 
               LEFT JOIN users ON songs.uploaded_by = users.id";

if ($selected_cat > 0) {
    $song_query .= " WHERE songs.category_id = $selected_cat";
}

$song_query .= " ORDER BY songs.created_at DESC";
$songs_result = $conn->query($song_query);

// Get statistics
$stats = [
    'total_songs' => $conn->query("SELECT COUNT(*) as count FROM songs")->fetch_assoc()['count'],
    'total_plays' => $conn->query("SELECT SUM(plays) as total FROM songs")->fetch_assoc()['total'] ?: 0,
    'total_artists' => $conn->query("SELECT COUNT(DISTINCT artist) as count FROM songs")->fetch_assoc()['count'],
    'total_categories' => $conn->query("SELECT COUNT(*) as count FROM categories")->fetch_assoc()['count']
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BINcloud Music | Premium Music Player</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .glass { 
            background: rgba(255, 255, 255, 0.03); 
            backdrop-filter: blur(15px); 
            border: 1px solid rgba(255,255,255,0.05); 
        }
        .hero-gradient { 
            background: linear-gradient(135deg, #1e40af 0%, #7e22ce 100%); 
        }
        body { 
            background-color: #020617; 
            color: #f8fafc; 
            font-family: 'Inter', sans-serif; 
            padding-bottom: 100px;
        }
        
        /* Admin Text Button - No button effects */
        .admin-text {
            cursor: default;
            transition: none;
            user-select: none;
        }
        
        .admin-text:hover {
            cursor: default;
            transform: none;
            opacity: 1;
        }
        
        .nav-link {
            position: relative;
            transition: all 0.3s ease;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 2px;
            background: #3b82f6;
            transition: width 0.3s ease;
        }
        
        .nav-link:hover::after,
        .nav-link.active::after {
            width: 70%;
        }
        
        .nav-link.active {
            color: #3b82f6;
        }
        
        /* Music Player Bar */
        .music-player-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(10, 10, 20, 0.98);
            backdrop-filter: blur(20px);
            border-top: 1px solid rgba(255,255,255,0.1);
            padding: 16px 24px;
            z-index: 100;
            transform: translateY(100%);
            transition: transform 0.3s ease;
            box-shadow: 0 -10px 30px rgba(0,0,0,0.5);
        }
        
        .music-player-bar.active {
            transform: translateY(0);
        }
        
        /* Progress Bar Container */
        .progress-container {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        
        .progress-bar-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .progress-bar {
            flex: 1;
            height: 6px;
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
            cursor: pointer;
            position: relative;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            border-radius: 3px;
            position: relative;
            width: 0%;
        }
        
        .progress-handle {
            width: 14px;
            height: 14px;
            background: white;
            border-radius: 50%;
            position: absolute;
            right: -7px;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0;
            transition: opacity 0.2s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        
        .progress-bar:hover .progress-handle {
            opacity: 1;
        }
        
        .time-display {
            font-family: monospace;
            font-size: 0.875rem;
            color: #94a3b8;
            min-width: 45px;
        }
        
        /* Player Controls */
        .player-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .song-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        
        .song-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #1e40af, #7e22ce);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-size: cover;
            background-position: center;
        }
        
        .song-avatar i {
            font-size: 24px;
            color: rgba(255,255,255,0.5);
        }
        
        .song-details h4 {
            font-weight: bold;
            font-size: 0.95rem;
            margin-bottom: 2px;
        }
        
        .song-details p {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        
        .control-buttons {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .control-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            transition: all 0.2s ease;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .control-btn:hover {
            background: rgba(255,255,255,0.15);
            transform: scale(1.05);
        }
        
        .control-btn.play-pause {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            width: 52px;
            height: 52px;
        }
        
        .control-btn.play-pause:hover {
            transform: scale(1.1);
            box-shadow: 0 0 20px rgba(59,130,246,0.5);
        }
        
        .control-btn.download-btn {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }
        
        .volume-control {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: 16px;
        }
        
        .volume-slider {
            width: 80px;
            height: 4px;
            background: rgba(255,255,255,0.1);
            border-radius: 2px;
            cursor: pointer;
            position: relative;
        }
        
        .volume-fill {
            height: 100%;
            background: #3b82f6;
            border-radius: 2px;
            width: 70%;
        }
        
        /* Music Card */
        .music-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 16px;
            padding: 16px;
        }
        
        .music-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }
        
        .music-card.playing {
            border: 2px solid #3b82f6;
            background: rgba(59, 130, 246, 0.1);
        }
        
        .action-buttons {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 10px;
            z-index: 20;
        }
        
        .action-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.5);
        }
        
        .action-btn:hover {
            transform: scale(1.15);
        }
        
        .action-btn.play-btn {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }
        
        .action-btn.download-btn {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }
        
        .action-btn.delete-btn {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        .card-content {
            position: relative;
            z-index: 1;
            margin-top: 12px;
        }
        
        .album-art {
            width: 100%;
            aspect-ratio: 1;
            background: linear-gradient(135deg, #1e40af 0%, #7e22ce 100%);
            border-radius: 12px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            background-size: cover;
            background-position: center;
        }
        
        .album-art i {
            font-size: 48px;
            color: rgba(255,255,255,0.3);
            transition: all 0.3s ease;
        }
        
        .music-card:hover .album-art i {
            color: rgba(255,255,255,0.6);
            transform: scale(1.1);
        }
        
        .playing-indicator {
            position: absolute;
            top: 70px;
            left: 20px;
            background: #3b82f6;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 6px;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 15;
            box-shadow: 0 4px 12px rgba(59,130,246,0.3);
        }
        
        .music-card.playing .playing-indicator {
            opacity: 1;
        }
        
        .playing-indicator i {
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .stat-card {
            background: linear-gradient(135deg, rgba(59,130,246,0.1) 0%, rgba(168,85,247,0.1) 100%);
            border: 1px solid rgba(255,255,255,0.05);
        }
        
        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05);
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
        }
        
        .upload-area {
            border: 2px dashed rgba(59,130,246,0.3);
            transition: all 0.3s ease;
        }
        
        .upload-area:hover {
            border-color: #3b82f6;
            background: rgba(59,130,246,0.05);
        }
        
        .thumbnail-preview {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .download-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 12px 24px;
            border-radius: 50px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(120%);
            transition: transform 0.3s ease;
            font-weight: 500;
        }
        
        .download-notification.show {
            transform: translateX(0);
        }
        
        /* Modal Tabs */
        .modal-tab {
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 2px solid transparent;
        }
        
        .modal-tab.active {
            color: #3b82f6;
            border-bottom-color: #3b82f6;
        }
        
        @media (max-width: 768px) {
            .volume-control {
                display: none;
            }
            
            .control-btn {
                width: 40px;
                height: 40px;
            }
            
            .control-btn.play-pause {
                width: 48px;
                height: 48px;
            }
            
            .song-info {
                min-width: 120px;
            }
        }
        
        @media (max-width: 640px) {
            .action-btn {
                width: 35px;
                height: 35px;
            }
            
            .card-content {
                margin-top: 8px;
            }
            
            .playing-indicator {
                top: 60px;
                left: 15px;
                font-size: 10px;
                padding: 3px 10px;
            }
        }
    </style>
</head>
<body class="overflow-x-hidden antialiased">

    <!-- Download Notification -->
    <div id="downloadNotification" class="download-notification">
        <i class="fas fa-check-circle"></i>
        <span id="downloadMessage">Download started!</span>
        <span class="close-btn ml-3 cursor-pointer" onclick="hideNotification()">&times;</span>
    </div>

    <!-- Mobile Menu Toggle -->
    <button id="mobileMenuBtn" class="lg:hidden fixed top-4 left-4 z-50 bg-slate-900/90 backdrop-blur-sm p-3 rounded-full shadow-lg">
        <i class="fas fa-bars text-xl"></i>
    </button>

    <!-- Navigation Bar -->
    <nav class="fixed top-0 left-0 right-0 z-40 bg-slate-900/95 backdrop-blur-md border-b border-white/10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-8">
                    <h1 class="text-2xl font-black text-blue-500">BINcloud</h1>
                    
                    <div class="hidden lg:flex items-center space-x-6">
                        <a href="?page=home" class="nav-link text-sm font-medium flex items-center gap-2 <?= $page == 'home' ? 'active' : '' ?>">
                            <i class="fas fa-home"></i> Home
                        </a>
                        <a href="?page=trending" class="nav-link text-sm font-medium flex items-center gap-2 <?= $page == 'trending' ? 'active' : '' ?>">
                            <i class="fas fa-fire"></i> Trending
                        </a>
                        <a href="?page=new" class="nav-link text-sm font-medium flex items-center gap-2 <?= $page == 'new' ? 'active' : '' ?>">
                            <i class="fas fa-compact-disc"></i> New Releases
                        </a>
                        <a href="?page=charts" class="nav-link text-sm font-medium flex items-center gap-2 <?= $page == 'charts' ? 'active' : '' ?>">
                            <i class="fas fa-chart-line"></i> Charts
                        </a>
                        <?php if(isset($_SESSION['admin_id'])): ?>
                        <a href="?page=admin" class="nav-link text-sm font-medium flex items-center gap-2 <?= $page == 'admin' ? 'active' : '' ?>">
                            <i class="fas fa-cog"></i> Admin Panel
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <div class="hidden md:block relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="text" id="activeSearch" placeholder="Search songs, artists..." 
                               class="bg-slate-800/50 border border-white/10 rounded-full pl-10 pr-4 py-2 w-64 focus:w-80 transition-all focus:ring-2 focus:ring-blue-500 outline-none text-sm">
                    </div>
                    
                    <?php if(isset($_SESSION['admin_id'])): ?>
                        <div class="flex items-center gap-3">
                            <span class="text-sm text-slate-400 hidden xl:inline">Hi, <?= htmlspecialchars($_SESSION['username']) ?></span>
                            <a href="?logout=1" class="text-slate-400 hover:text-red-400 transition p-2" title="Logout">
                                <i class="fas fa-sign-out-alt"></i>
                            </a>
                        </div>
                    <?php else: ?>
                        <!-- Admin Text Button - No button effects, just text -->
                        <span id="adminLoginBtn" class="text-slate-500 text-sm font-medium admin-text">
                            bincloud
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="md:hidden p-4 border-t border-white/10">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                <input type="text" id="mobileSearch" placeholder="Search songs, artists..." 
                       class="w-full bg-slate-800/50 border border-white/10 rounded-full pl-10 pr-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none text-sm">
            </div>
        </div>
    </nav>

    <!-- Sidebar -->
    <aside class="fixed left-0 top-16 h-[calc(100vh-4rem)] w-64 glass p-6 z-30 hidden lg:block overflow-y-auto custom-scrollbar">
        <nav class="space-y-6">
            <div>
                <p class="text-xs uppercase text-slate-500 font-bold mb-4">Menu</p>
                <a href="?page=home" class="flex items-center gap-3 <?= $page == 'home' ? 'text-blue-400' : 'opacity-60' ?> hover:opacity-100 hover:bg-white/10 p-2 rounded-lg transition">
                    <i class="fas fa-home w-5"></i> Explore
                </a>
                <a href="?page=trending" class="flex items-center gap-3 mt-2 <?= $page == 'trending' ? 'text-blue-400' : 'opacity-60' ?> hover:opacity-100 hover:bg-white/10 p-2 rounded-lg transition">
                    <i class="fas fa-chart-line w-5"></i> Trending
                </a>
            </div>
            
            <div>
                <p class="text-xs uppercase text-slate-500 font-bold mb-4">Categories</p>
                <div class="space-y-2">
                    <?php 
                    $categories_result->data_seek(0);
                    while($c = $categories_result->fetch_assoc()): 
                    ?>
                        <a href="?cat=<?= $c['id'] ?>" class="block text-sm <?= ($selected_cat == $c['id']) ? 'text-blue-400' : 'opacity-60' ?> hover:text-blue-400 hover:bg-white/10 p-2 rounded-lg transition truncate">
                            <?= htmlspecialchars($c['name']) ?>
                        </a>
                    <?php endwhile; ?>
                </div>
            </div>
        </nav>
    </aside>

    <!-- Mobile Menu -->
    <div id="menuOverlay" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-40 hidden lg:hidden"></div>
    <div id="mobileSidebar" class="fixed left-0 top-0 h-full w-64 glass p-6 z-50 transform transition-transform duration-300 -translate-x-full lg:hidden overflow-y-auto">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-2xl font-black text-blue-500">BINcloud</h1>
            <button id="closeMobileMenu" class="text-2xl">&times;</button>
        </div>
        <nav class="space-y-6">
            <div>
                <p class="text-xs uppercase text-slate-500 font-bold mb-4">Menu</p>
                <a href="?page=home" class="flex items-center gap-3 text-blue-400 hover:bg-white/10 p-3 rounded-lg transition">Explore</a>
                <a href="?page=trending" class="flex items-center gap-3 mt-2 opacity-60 hover:opacity-100 hover:bg-white/10 p-3 rounded-lg transition">Trending</a>
            </div>
            <div>
                <p class="text-xs uppercase text-slate-500 font-bold mb-4">Categories</p>
                <?php 
                $categories_result->data_seek(0);
                while($c = $categories_result->fetch_assoc()): 
                ?>
                    <a href="?cat=<?= $c['id'] ?>" class="block text-sm opacity-60 hover:text-blue-400 hover:bg-white/10 p-3 rounded-lg transition"><?= $c['name'] ?></a>
                <?php endwhile; ?>
            </div>
        </nav>
    </div>

    <main class="lg:ml-64 min-h-screen pt-32 lg:pt-24 pb-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <?php if($page == 'admin' && isset($_SESSION['admin_id'])): ?>
                <!-- Admin Panel -->
                <div class="mb-8">
                    <h2 class="text-2xl font-bold mb-6">Admin Dashboard</h2>
                    
                    <!-- Stats -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                        <div class="stat-card rounded-xl p-6">
                            <i class="fas fa-music text-3xl text-blue-400 mb-3"></i>
                            <h3 class="text-2xl font-bold"><?= $stats['total_songs'] ?></h3>
                            <p class="text-slate-400">Total Songs</p>
                        </div>
                        <div class="stat-card rounded-xl p-6">
                            <i class="fas fa-headphones text-3xl text-purple-400 mb-3"></i>
                            <h3 class="text-2xl font-bold"><?= number_format($stats['total_plays']) ?></h3>
                            <p class="text-slate-400">Total Plays</p>
                        </div>
                        <div class="stat-card rounded-xl p-6">
                            <i class="fas fa-microphone text-3xl text-pink-400 mb-3"></i>
                            <h3 class="text-2xl font-bold"><?= $stats['total_artists'] ?></h3>
                            <p class="text-slate-400">Artists</p>
                        </div>
                        <div class="stat-card rounded-xl p-6">
                            <i class="fas fa-tags text-3xl text-green-400 mb-3"></i>
                            <h3 class="text-2xl font-bold"><?= $stats['total_categories'] ?></h3>
                            <p class="text-slate-400">Categories</p>
                        </div>
                    </div>
                    
                    <!-- Messages -->
                    <?php if($upload_message): ?>
                        <div class="bg-green-500/20 border border-green-500/50 text-green-200 px-4 py-3 rounded-lg mb-4">
                            <i class="fas fa-check-circle mr-2"></i> <?= $upload_message ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if($upload_error): ?>
                        <div class="bg-red-500/20 border border-red-500/50 text-red-200 px-4 py-3 rounded-lg mb-4">
                            <i class="fas fa-exclamation-circle mr-2"></i> <?= $upload_error ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if(isset($_GET['deleted'])): ?>
                        <div class="bg-yellow-500/20 border border-yellow-500/50 text-yellow-200 px-4 py-3 rounded-lg mb-4">
                            <i class="fas fa-trash mr-2"></i> Song deleted successfully
                        </div>
                    <?php endif; ?>
                    
                    <!-- Upload Form with Thumbnail -->
                    <div class="glass rounded-2xl p-6 mb-8">
                        <h3 class="text-xl font-bold mb-4">Upload New Music</h3>
                        <form method="POST" enctype="multipart/form-data" class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-slate-400 mb-1">Song Title *</label>
                                    <input type="text" name="title" required 
                                           class="w-full bg-slate-900/50 border border-white/10 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none">
                                </div>
                                <div>
                                    <label class="block text-sm text-slate-400 mb-1">Artist Name *</label>
                                    <input type="text" name="artist" required 
                                           class="w-full bg-slate-900/50 border border-white/10 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none">
                                </div>
                                <div>
                                    <label class="block text-sm text-slate-400 mb-1">Category *</label>
                                    <select name="category_id" required class="w-full bg-slate-900/50 border border-white/10 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none">
                                        <option value="">Select Category</option>
                                        <?php 
                                        $categories_result->data_seek(0);
                                        while($c = $categories_result->fetch_assoc()): 
                                        ?>
                                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm text-slate-400 mb-1">Duration (e.g., 3:45)</label>
                                    <input type="text" name="duration" placeholder="3:30" 
                                           class="w-full bg-slate-900/50 border border-white/10 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm text-slate-400 mb-1">Audio File * (MP3, max 50MB)</label>
                                    <div class="upload-area rounded-lg p-8 text-center">
                                        <input type="file" name="music_file" accept="audio/*" required 
                                               class="w-full text-sm text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-700">
                                        <p class="text-xs text-slate-500 mt-2">Supported formats: MP3, WAV, OGG (Max 50MB)</p>
                                    </div>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm text-slate-400 mb-1">Thumbnail Image (JPG, PNG, GIF, WEBP - max 5MB)</label>
                                    <div class="upload-area rounded-lg p-6 text-center">
                                        <input type="file" name="thumbnail" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" 
                                               class="w-full text-sm text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-purple-600 file:text-white hover:file:bg-purple-700"
                                               onchange="previewThumbnail(this)">
                                        <div id="thumbnailPreview" class="mt-3 hidden">
                                            <img id="previewImage" class="thumbnail-preview mx-auto" alt="Thumbnail preview">
                                        </div>
                                        <p class="text-xs text-slate-500 mt-2">Recommended size: 300x300 pixels. Leave empty for default thumbnail.</p>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="upload" 
                                    class="bg-blue-600 hover:bg-blue-700 px-8 py-3 rounded-lg font-bold transition">
                                <i class="fas fa-upload mr-2"></i> Upload Music
                            </button>
                        </form>
                    </div>
                    
                    <!-- Music Library -->
                    <h3 class="text-xl font-bold mb-4">Music Library</h3>
                </div>
            <?php endif; ?>

            <!-- Hero Section -->
            <?php if($page == 'home' && $selected_cat == 0): ?>
            <section class="hero-gradient min-h-[200px] sm:h-56 rounded-2xl sm:rounded-3xl p-6 sm:p-8 flex flex-col justify-end mb-8 relative overflow-hidden">
                <div class="relative z-10">
                    <span class="bg-white/20 px-3 py-1 rounded-full text-xs font-bold uppercase inline-block mb-2">Featured Artist</span>
                    <h2 class="hero-title text-3xl sm:text-4xl lg:text-5xl font-black mt-2 leading-tight">New Releases 2026</h2>
                    <p class="mt-2 opacity-80 text-sm sm:text-base">Stream and download the latest hits for free.</p>
                </div>
                <i class="fas fa-headphones absolute -right-10 -bottom-10 text-[150px] sm:text-[200px] opacity-10 rotate-12 hidden sm:block"></i>
            </section>
            <?php endif; ?>

            <!-- Category Title -->
            <?php if($selected_cat > 0): 
                $categories_result->data_seek(0);
                $cat_name = '';
                while($c = $categories_result->fetch_assoc()) {
                    if($c['id'] == $selected_cat) {
                        $cat_name = $c['name'];
                        break;
                    }
                }
            ?>
            <div class="mb-4">
                <h3 class="text-lg sm:text-xl font-bold"><?= htmlspecialchars($cat_name) ?> Songs</h3>
                <a href="?page=home" class="text-sm text-blue-400 hover:underline">&larr; Back to all</a>
            </div>
            <?php endif; ?>

            <!-- Page Title -->
            <?php if($page != 'home' && $selected_cat == 0): ?>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg sm:text-xl font-bold">
                    <?php 
                    if($page == 'trending') echo 'Trending Now';
                    elseif($page == 'new') echo 'New Releases';
                    elseif($page == 'charts') echo 'Top Charts';
                    elseif($page == 'admin' && isset($_SESSION['admin_id'])) echo 'Music Library';
                    else echo 'Recent Uploads';
                    ?>
                </h3>
            </div>
            <?php endif; ?>
            
            <!-- Music Grid -->
            <div id="musicGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-6">
                <?php 
                if($songs_result->num_rows > 0):
                    $count = 0;
                    while($s = $songs_result->fetch_assoc()): 
                        if($page == 'home' && $selected_cat == 0 && $count >= 8) break;
                        if($page == 'trending' && $s['plays'] < 5000) continue;
                        if($page == 'new' && $count >= 10) break;
                        if($page == 'charts' && $count >= 10) break;
                        $count++;
                        $thumbnail_style = !empty($s['thumbnail_path']) && file_exists($s['thumbnail_path']) 
                            ? 'background-image: url(' . htmlspecialchars($s['thumbnail_path']) . '); background-size: cover;' 
                            : '';
                ?>
                    <div class="music-card" data-song-id="<?= $s['id'] ?>" data-file-path="<?= htmlspecialchars($s['file_path']) ?>">
                        <!-- Playing Indicator -->
                        <div class="playing-indicator">
                            <i class="fas fa-volume-up"></i>
                            <span>Now Playing</span>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button onclick="playSong(<?= htmlspecialchars(json_encode($s)) ?>)" 
                                    class="action-btn play-btn" title="Play">
                                <i class="fas fa-play"></i>
                            </button>
                            <a href="?download=<?= $s['id'] ?>" 
                               class="action-btn download-btn" title="Download">
                                <i class="fas fa-download"></i>
                            </a>
                            <?php if(isset($_SESSION['admin_id']) && $page == 'admin'): ?>
                            <a href="?delete_id=<?= $s['id'] ?>&page=admin" 
                               onclick="return confirm('Delete this song?')"
                               class="action-btn delete-btn" title="Delete">
                                <i class="fas fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Album Art with Thumbnail -->
                        <div class="album-art" style="<?= $thumbnail_style ?>">
                            <?php if(empty($thumbnail_style)): ?>
                            <i class="fas fa-music"></i>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Song Info -->
                        <div class="card-content">
                            <h4 class="font-bold truncate text-lg"><?= htmlspecialchars($s['title']) ?></h4>
                            <p class="text-sm text-slate-400 truncate mb-2"><?= htmlspecialchars($s['artist']) ?></p>
                            <div class="flex items-center justify-between text-xs text-slate-500">
                                <span class="bg-white/10 px-2 py-1 rounded-full"><?= htmlspecialchars($s['cat_name'] ?? 'Uncategorized') ?></span>
                                <div class="flex items-center gap-3">
                                    <span><i class="far fa-clock mr-1"></i> <?= $s['duration'] ?? '3:30' ?></span>
                                    <span><i class="fas fa-play mr-1"></i> <?= number_format($s['plays']) ?></span>
                                </div>
                            </div>
                            <?php if($page == 'admin'): ?>
                            <p class="text-xs text-slate-600 mt-2">Uploaded by: <?= htmlspecialchars($s['uploaded_by_name'] ?? 'Unknown') ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php 
                    endwhile;
                else:
                ?>
                    <div class="col-span-full text-center py-12">
                        <i class="fas fa-music text-5xl text-slate-600 mb-4"></i>
                        <p class="text-slate-400">No songs found</p>
                        <?php if(isset($_SESSION['admin_id'])): ?>
                        <p class="text-sm text-slate-500 mt-2">Upload some music to get started!</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Mobile Bottom Navigation -->
    <nav class="lg:hidden fixed bottom-0 left-0 right-0 bg-slate-900/95 backdrop-blur-md border-t border-white/10 p-2 z-40" style="margin-bottom: 80px;">
        <div class="flex justify-around items-center">
            <a href="?page=home" class="flex flex-col items-center p-2 <?= $page == 'home' ? 'text-blue-400' : 'text-slate-400' ?>">
                <i class="fas fa-home text-xl"></i>
                <span class="text-xs mt-1">Home</span>
            </a>
            <a href="?page=trending" class="flex flex-col items-center p-2 <?= $page == 'trending' ? 'text-blue-400' : 'text-slate-400' ?>">
                <i class="fas fa-fire text-xl"></i>
                <span class="text-xs mt-1">Trending</span>
            </a>
            <a href="?page=new" class="flex flex-col items-center p-2 <?= $page == 'new' ? 'text-blue-400' : 'text-slate-400' ?>">
                <i class="fas fa-compact-disc text-xl"></i>
                <span class="text-xs mt-1">New</span>
            </a>
            <a href="?page=charts" class="flex flex-col items-center p-2 <?= $page == 'charts' ? 'text-blue-400' : 'text-slate-400' ?>">
                <i class="fas fa-chart-line text-xl"></i>
                <span class="text-xs mt-1">Charts</span>
            </a>
        </div>
    </nav>

    <!-- Music Player Bar -->
    <div id="musicPlayer" class="music-player-bar">
        <div class="max-w-7xl mx-auto">
            <!-- Progress Bar with Download Button -->
            <div class="progress-container">
                <span class="time-display" id="currentTime">0:00</span>
                <div class="progress-bar-wrapper">
                    <div class="progress-bar" id="progressBar" onclick="seekTo(event)">
                        <div class="progress-fill" id="progressFill">
                            <div class="progress-handle"></div>
                        </div>
                    </div>
                    <button onclick="downloadCurrentSong()" class="control-btn download-btn" title="Download Current Song" style="width: 36px; height: 36px;">
                        <i class="fas fa-download"></i>
                    </button>
                </div>
                <span class="time-display" id="totalTime">0:00</span>
            </div>
            
            <!-- Player Controls -->
            <div class="player-controls">
                <!-- Song Info with Thumbnail -->
                <div class="song-info">
                    <div class="song-avatar" id="playerAvatar">
                        <i class="fas fa-music" id="playerIcon"></i>
                    </div>
                    <div class="song-details">
                        <h4 id="currentSongTitle">Select a song</h4>
                        <p id="currentSongArtist">Click play to start</p>
                    </div>
                </div>
                
                <!-- Control Buttons -->
                <div class="control-buttons">
                    <button onclick="previousSong()" class="control-btn" title="Previous">
                        <i class="fas fa-backward"></i>
                    </button>
                    
                    <button onclick="togglePlay()" class="control-btn play-pause" id="playPauseBtn" title="Play/Pause">
                        <i class="fas fa-play" id="playPauseIcon"></i>
                    </button>
                    
                    <button onclick="nextSong()" class="control-btn" title="Next">
                        <i class="fas fa-forward"></i>
                    </button>
                    
                    <!-- Volume Control (Desktop only) -->
                    <div class="volume-control">
                        <i class="fas fa-volume-up text-slate-400"></i>
                        <div class="volume-slider" onclick="setVolume(event)">
                            <div class="volume-fill" id="volumeFill" style="width: 70%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Audio Element -->
    <audio id="audioPlayer" preload="metadata"></audio>

    <!-- Login Modal with Tabs -->
    <div id="loginModal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="glass rounded-2xl p-6 sm:p-8 max-w-md w-full">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold">Admin Access</h2>
                <button onclick="closeLoginModal()" class="text-2xl">&times;</button>
            </div>
            
            <!-- Tabs -->
            <div class="flex border-b border-white/10 mb-6">
                <button id="loginTabBtn" class="modal-tab flex-1 py-2 text-center font-medium active" onclick="switchTab('login')">
                    Login
                </button>
                <button id="resetTabBtn" class="modal-tab flex-1 py-2 text-center font-medium" onclick="switchTab('reset')">
                    Reset Password
                </button>
            </div>
            
            <!-- Login Form -->
            <div id="loginForm" class="tab-content">
                <?php if($login_error): ?>
                <div class="bg-red-500/20 border border-red-500/50 text-red-200 px-4 py-3 rounded-lg mb-4">
                    <i class="fas fa-exclamation-circle mr-2"></i> <?= $login_error ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="text-sm text-slate-400 mb-1">Username</label>
                        <input type="text" name="username" placeholder="bincloud" required 
                               class="w-full bg-slate-900/50 border border-white/10 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="text-sm text-slate-400 mb-1">Password</label>
                        <input type="password" name="password" required 
                               class="w-full bg-slate-900/50 border border-white/10 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <button type="submit" name="login" 
                            class="w-full bg-blue-600 hover:bg-blue-700 py-3 rounded-lg font-bold transition mt-4">
                        <i class="fas fa-lock mr-2"></i> Login
                    </button>
                </form>
            </div>
            
            <!-- Reset Password Form -->
            <div id="resetForm" class="tab-content hidden">
                <?php if($reset_message): ?>
                <div class="<?= strpos($reset_message, 'successfully') !== false ? 'bg-green-500/20 border-green-500/50 text-green-200' : 'bg-red-500/20 border-red-500/50 text-red-200' ?> px-4 py-3 rounded-lg mb-4">
                    <i class="fas <?= strpos($reset_message, 'successfully') !== false ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i> <?= $reset_message ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="text-sm text-slate-400 mb-1">Username</label>
                        <input type="text" name="reset_username" placeholder="bincloud" required 
                               class="w-full bg-slate-900/50 border border-white/10 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="text-sm text-slate-400 mb-1">Current Password</label>
                        <input type="password" name="current_password" required 
                               class="w-full bg-slate-900/50 border border-white/10 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="text-sm text-slate-400 mb-1">New Password</label>
                        <input type="password" name="new_password" required minlength="6"
                               class="w-full bg-slate-900/50 border border-white/10 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none">
                        <p class="text-xs text-slate-500 mt-1">Minimum 6 characters</p>
                    </div>
                    <div>
                        <label class="text-sm text-slate-400 mb-1">Confirm New Password</label>
                        <input type="password" name="confirm_password" required
                               class="w-full bg-slate-900/50 border border-white/10 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <button type="submit" name="reset_password" 
                            class="w-full bg-purple-600 hover:bg-purple-700 py-3 rounded-lg font-bold transition mt-4">
                        <i class="fas fa-key mr-2"></i> Reset Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Thumbnail preview function
        function previewThumbnail(input) {
            const previewDiv = document.getElementById('thumbnailPreview');
            const previewImage = document.getElementById('previewImage');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    previewDiv.classList.remove('hidden');
                }
                
                reader.readAsDataURL(input.files[0]);
            } else {
                previewDiv.classList.add('hidden');
            }
        }
        
        // Music Player Variables
        const audio = document.getElementById('audioPlayer');
        const playPauseBtn = document.getElementById('playPauseBtn');
        const playPauseIcon = document.getElementById('playPauseIcon');
        const progressFill = document.getElementById('progressFill');
        const progressBar = document.getElementById('progressBar');
        const currentTimeEl = document.getElementById('currentTime');
        const totalTimeEl = document.getElementById('totalTime');
        const currentSongTitle = document.getElementById('currentSongTitle');
        const currentSongArtist = document.getElementById('currentSongArtist');
        const playerIcon = document.getElementById('playerIcon');
        const playerAvatar = document.getElementById('playerAvatar');
        const musicPlayer = document.getElementById('musicPlayer');
        const volumeFill = document.getElementById('volumeFill');
        const downloadNotification = document.getElementById('downloadNotification');
        const downloadMessage = document.getElementById('downloadMessage');
        
        let playlist = [];
        let currentSongIndex = 0;
        let isPlaying = false;
        
        <?php
        // Convert PHP songs to JavaScript array
        $songs_result->data_seek(0);
        $js_songs = [];
        while($s = $songs_result->fetch_assoc()) {
            $js_songs[] = [
                'id' => $s['id'],
                'title' => $s['title'],
                'artist' => $s['artist'],
                'file_path' => $s['file_path'],
                'thumbnail_path' => $s['thumbnail_path'] ?? '',
                'duration' => $s['duration'] ?? '3:30',
                'cat_name' => $s['cat_name'] ?? 'Uncategorized'
            ];
        }
        ?>
        
        playlist = <?= json_encode($js_songs) ?>;
        
        // Format time (seconds to MM:SS)
        function formatTime(seconds) {
            if (isNaN(seconds)) return '0:00';
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return mins + ':' + (secs < 10 ? '0' + secs : secs);
        }
        
        // Update player avatar with thumbnail
        function updatePlayerAvatar(song) {
            if (song.thumbnail_path && song.thumbnail_path !== '' && song.thumbnail_path !== 'thumbnails/default_thumbnail.jpg') {
                // Check if thumbnail exists by trying to load it
                const img = new Image();
                img.onload = function() {
                    playerAvatar.style.backgroundImage = `url('${song.thumbnail_path}')`;
                    playerAvatar.style.backgroundSize = 'cover';
                    playerAvatar.style.backgroundPosition = 'center';
                    playerIcon.style.display = 'none';
                };
                img.onerror = function() {
                    playerAvatar.style.backgroundImage = '';
                    playerIcon.style.display = 'flex';
                };
                img.src = song.thumbnail_path;
            } else {
                playerAvatar.style.backgroundImage = '';
                playerIcon.style.display = 'flex';
            }
        }
        
        // Play song
        function playSong(song) {
            const index = playlist.findIndex(s => s.id === song.id);
            if (index !== -1) currentSongIndex = index;
            
            currentSongTitle.textContent = song.title;
            currentSongArtist.textContent = song.artist;
            updatePlayerAvatar(song);
            
            // Use file path from server
            audio.src = song.file_path;
            audio.load();
            audio.play().then(() => {
                isPlaying = true;
                playPauseIcon.className = 'fas fa-pause';
                musicPlayer.classList.add('active');
                
                // Remove playing class from all cards
                document.querySelectorAll('.music-card').forEach(card => {
                    card.classList.remove('playing');
                });
                
                // Add playing class to current card
                const currentCard = document.querySelector(`.music-card[data-song-id="${song.id}"]`);
                if (currentCard) currentCard.classList.add('playing');
            }).catch(error => {
                console.log('Playback failed:', error);
                showNotification('Unable to play audio', 'error');
            });
        }
        
        // Show notification
        function showNotification(message, type = 'success') {
            downloadMessage.textContent = message;
            downloadNotification.style.background = type === 'success' 
                ? 'linear-gradient(135deg, #10b981, #059669)'
                : 'linear-gradient(135deg, #ef4444, #dc2626)';
            downloadNotification.classList.add('show');
            setTimeout(hideNotification, 3000);
        }
        
        // Hide notification
        function hideNotification() {
            downloadNotification.classList.remove('show');
        }
        
        // Toggle play/pause
        function togglePlay() {
            if (!audio.src && playlist.length > 0) {
                playSong(playlist[0]);
                return;
            }
            
            if (audio.paused) {
                audio.play();
                playPauseIcon.className = 'fas fa-pause';
                isPlaying = true;
            } else {
                audio.pause();
                playPauseIcon.className = 'fas fa-play';
                isPlaying = false;
            }
        }
        
        // Next song
        function nextSong() {
            if (playlist.length === 0) return;
            currentSongIndex = (currentSongIndex + 1) % playlist.length;
            playSong(playlist[currentSongIndex]);
        }
        
        // Previous song
        function previousSong() {
            if (playlist.length === 0) return;
            currentSongIndex = (currentSongIndex - 1 + playlist.length) % playlist.length;
            playSong(playlist[currentSongIndex]);
        }
        
        // Seek to position
        function seekTo(event) {
            if (!audio.src) return;
            const rect = progressBar.getBoundingClientRect();
            const pos = (event.clientX - rect.left) / rect.width;
            audio.currentTime = pos * audio.duration;
        }
        
        // Set volume
        function setVolume(event) {
            const rect = event.currentTarget.getBoundingClientRect();
            const pos = (event.clientX - rect.left) / rect.width;
            audio.volume = Math.max(0, Math.min(1, pos));
            volumeFill.style.width = (audio.volume * 100) + '%';
        }
        
        // Download current song
        function downloadCurrentSong() {
            if (!audio.src) {
                showNotification('No song selected', 'error');
                return;
            }
            
            const currentSong = playlist[currentSongIndex];
            if (currentSong) {
                window.location.href = '?download=' + currentSong.id;
                showNotification('Downloading: ' + currentSong.title);
            }
        }
        
        // Update progress bar
        audio.addEventListener('timeupdate', () => {
            if (audio.duration) {
                const progress = (audio.currentTime / audio.duration) * 100;
                progressFill.style.width = progress + '%';
                currentTimeEl.textContent = formatTime(audio.currentTime);
            }
        });
        
        // Update total time when metadata loaded
        audio.addEventListener('loadedmetadata', () => {
            totalTimeEl.textContent = formatTime(audio.duration);
        });
        
        // Auto play next when song ends
        audio.addEventListener('ended', () => {
            nextSong();
        });
        
        // Keyboard controls
        document.addEventListener('keydown', (e) => {
            if (e.code === 'Space' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                togglePlay();
            }
        });
        
        // Modal Functions
        function openLoginModal() {
            document.getElementById('loginModal').classList.remove('hidden');
            switchTab('login');
        }
        
        function closeLoginModal() {
            document.getElementById('loginModal').classList.add('hidden');
            // Clear any error messages when closing
            <?php if($login_error || $reset_message): ?>
            window.location.href = window.location.pathname;
            <?php endif; ?>
        }
        
        function switchTab(tab) {
            const loginForm = document.getElementById('loginForm');
            const resetForm = document.getElementById('resetForm');
            const loginTab = document.getElementById('loginTabBtn');
            const resetTab = document.getElementById('resetTabBtn');
            
            if (tab === 'login') {
                loginForm.classList.remove('hidden');
                resetForm.classList.add('hidden');
                loginTab.classList.add('active');
                resetTab.classList.remove('active');
            } else {
                loginForm.classList.add('hidden');
                resetForm.classList.remove('hidden');
                loginTab.classList.remove('active');
                resetTab.classList.add('active');
            }
        }
        
        // Admin Text Button Click Handler
        const adminLoginBtn = document.getElementById('adminLoginBtn');
        if (adminLoginBtn) {
            adminLoginBtn.addEventListener('click', openLoginModal);
        }
        
        // Mobile menu
        const mobileSidebar = document.getElementById('mobileSidebar');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const closeMobileMenu = document.getElementById('closeMobileMenu');
        const menuOverlay = document.getElementById('menuOverlay');
        
        function openMobileMenu() {
            mobileSidebar.classList.remove('-translate-x-full');
            menuOverlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        
        function closeMobileMenuFunc() {
            mobileSidebar.classList.add('-translate-x-full');
            menuOverlay.classList.add('hidden');
            document.body.style.overflow = '';
        }
        
        mobileMenuBtn?.addEventListener('click', openMobileMenu);
        closeMobileMenu?.addEventListener('click', closeMobileMenuFunc);
        menuOverlay?.addEventListener('click', closeMobileMenuFunc);
        
        // Search
        const searchInputs = ['activeSearch', 'mobileSearch'];
        searchInputs.forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', function(e) {
                    const term = e.target.value.toLowerCase();
                    document.querySelectorAll('#musicGrid > div').forEach(card => {
                        const title = card.querySelector('h4')?.textContent.toLowerCase() || '';
                        const artist = card.querySelector('p')?.textContent.toLowerCase() || '';
                        card.style.display = (title.includes(term) || artist.includes(term)) ? 'block' : 'none';
                    });
                });
            }
        });
        
        <?php if($login_error): ?>
        openLoginModal();
        <?php endif; ?>
        
        <?php if($reset_message && strpos($reset_message, 'successfully') !== false): ?>
        openLoginModal();
        switchTab('login');
        <?php endif; ?>
    </script>
</body>
</html>
<?php $conn->close(); ?>