<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

/* ==========================================================
   1. SECURITY & CONFIGURATION
========================================================== */
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();

/* ==========================================================
   LOGGING FUNCTION
========================================================== */
define('LOG_FILE', __DIR__ . '/upload_debug.log'); // Pfad zur Log-Datei

function logData(array $data): void {
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] " . print_r($data, true) . "\n--------------------------\n";
    file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}


$config = require __DIR__ . '/inc/config.php';

// Konstanten
const MAX_UPLOAD_MB = 80;
const STORAGE_DIR   = __DIR__ . '/storage/';

// BASE_URL & Admin
$BASE_URL = rtrim($config['base_url-video'], '/');
$ADMIN_PASS = $config['admin_pass'] ?? null;

if (!$ADMIN_PASS) {
    die('Admin Passwort nicht konfiguriert! Bitte in config.php setzen.');
}

/* ==========================================================
   2. DATABASE CONNECTION
========================================================== */
try {
    $db = $config['db'];
    $dsn = "mysql:host={$db['host']};dbname={$db['dbname']};charset={$db['charset']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    die('<div style="font-family:sans-serif;text-align:center;padding:50px;">
        <h1>System Error</h1><p>Database connection failed.</p></div>');
}

/* ==========================================================
   3. HELPER FUNCTIONS
========================================================== */
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}
function randomToken(int $length = 32): string { return bin2hex(random_bytes($length / 2)); }

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = randomToken(64);
}

/* ==========================================================
   4. BACKEND LOGIC
========================================================== */

// --- Auto Cleanup ---
if (random_int(1, 20) === 1) {
    $stmt = $pdo->query("SELECT file FROM clips WHERE expires_at < NOW() AND type != 'external'");
    foreach ($stmt as $row) {
        if ($row['file'] && is_file(STORAGE_DIR . $row['file'])) {
            @unlink(STORAGE_DIR . $row['file']);
        }
    }
    $pdo->exec("DELETE FROM clips WHERE expires_at < NOW()");
}

// --- Admin Login / Logout ---
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_logged_in']);
    header('Location: portal.php');
    exit;
}
if (isset($_POST['admin_login'])) {
    if ($ADMIN_PASS && password_verify($_POST['password'] ?? '', $ADMIN_PASS)) {
        $_SESSION['admin_logged_in'] = true;
    }
    header('Location: portal.php#admin');
    exit;
}

// --- Admin Delete ---
if (isset($_GET['delete'], $_GET['csrf']) && !empty($_SESSION['admin_logged_in'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_GET['csrf'])) die('CSRF Error');
    
    $stmt = $pdo->prepare("SELECT file, type FROM clips WHERE token = ?");
    $stmt->execute([$_GET['delete']]);
    $clip = $stmt->fetch();
    
    if ($clip) {
        if ($clip['type'] !== 'external' && $clip['file']) {
            @unlink(STORAGE_DIR . $clip['file']);
        }
        $pdo->prepare("DELETE FROM clips WHERE token = ?")->execute([$_GET['delete']]);
    }
    header('Location: portal.php#admin');
    exit;
}

/* ==========================================================
   4. D. AJAX UPLOAD HANDLING
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Alles loggen
    logData([
        'POST' => $_POST,
        'FILES' => $_FILES,
        'SESSION' => $_SESSION
    ]);

    // CSRF prüfen
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf'] ?? '')) {
        jsonResponse(['error' => 'Invalid Security Token'], 403);
    }

    $responses = [];

    // --- Passwort entsperren ---
    if (isset($_POST['unlock']) && !empty($_GET['t'])) {
        $stmt = $pdo->prepare("SELECT * FROM clips WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$_GET['t']]);
        $clip = $stmt->fetch();

 if ($clip && $clip['is_private']) {
    if (password_verify($_POST['clip_password'] ?? '', $clip['password_hash'])) {
        $_SESSION['unlocked_' . $clip['token']] = true;
        // Weiterleitung statt JSON
        header("Location: portal.php?t=" . $clip['token']);
        exit;
    } else {
        jsonResponse(['error' => 'Falsches Passwort'], 403);
    }
        } else {
            jsonResponse(['error' => 'Clip nicht gefunden oder nicht geschützt'], 404);
        }
    }

    $profileMap = ['short' => 1, 'three' => 72,'standart' => 48, 'long' => 168];
    $hours = $profileMap[$_POST['profile'] ?? 'standard'] ?? 48;
    $expires = date('Y-m-d H:i:s', time() + ($hours * 3600));

    // Passwortschutz nur aktiv, wenn Checkbox gesetzt UND Passwort eingegeben
    $isPrivate = false;
    $hash = null;
    if (!empty($_POST['private']) && $_POST['private'] === '1') {
        $password = trim($_POST['password'] ?? '');
        if ($password !== '') {
            $isPrivate = true;
            $hash = password_hash($password, PASSWORD_DEFAULT);
        }
    }

    // --- Chunked Upload ---
    if (isset($_POST['upload_chunk'])) {
        $fileToken = preg_replace('/[^a-z0-9]/i', '', $_POST['file_token']);
        $chunkIndex = (int)$_POST['chunk_index'];
        $totalChunks = (int)$_POST['total_chunks'];
        $originalName = $_POST['file_name'] ?? 'file';
        
        $tempDir = STORAGE_DIR . 'chunks/' . $fileToken;
        if (!is_dir($tempDir)) mkdir($tempDir, 0775, true);

        $chunkPath = $tempDir . '/' . $chunkIndex;
        if (move_uploaded_file($_FILES['file_chunk']['tmp_name'], $chunkPath)) {
            $files = glob("$tempDir/*");
            if (count($files) === $totalChunks) {
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $newName = randomToken(16) . '.' . $ext;
                $finalPath = STORAGE_DIR . $newName;

                $out = fopen($finalPath, "wb");
                for ($i = 0; $i < $totalChunks; $i++) {
                    $chunkFile = $tempDir . '/' . $i;
                    if (file_exists($chunkFile)) {
                        $in = fopen($chunkFile, "rb");
                        stream_copy_to_stream($in, $out);
                        fclose($in);
                        unlink($chunkFile);
                    }
                }
                fclose($out);
                @rmdir($tempDir);

                $mime = mime_content_type($finalPath);
                $type = str_starts_with($mime, 'video') ? 'video' : (str_starts_with($mime, 'audio') ? 'audio' : (str_starts_with($mime, 'image') ? 'image' : 'file'));

                $token = randomToken(16);
                $pdo->prepare("INSERT INTO clips (token, type, file, expires_at, is_private, password_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$token, $type, $newName, $expires, $isPrivate, $hash]);

                jsonResponse([
                    'status' => 'completed',
                    'data' => [
                        'name' => $originalName,
                        'link' => "{$BASE_URL}/portal.php?t={$token}",
                        'token' => $token,
                        'type' => $type
                    ]
                ]);
            }
            jsonResponse(['status' => 'chunk_saved', 'index' => $chunkIndex]);
        }
        jsonResponse(['error' => 'Chunk upload failed'], 500);
    }

    // --- URL Upload ---
    if (isset($_POST['upload']) && !empty($_POST['urls'])) {
        $lines = explode("\n", $_POST['urls']);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line || !filter_var($line, FILTER_VALIDATE_URL)) continue;

            $token = randomToken(16);
            $pdo->prepare("INSERT INTO clips (token, type, external, expires_at, is_private, password_hash, created_at) VALUES (?, 'external', ?, ?, ?, ?, NOW())")
                ->execute([$token, $line, $expires, $isPrivate, $hash]);

            $responses[] = [
                'name' => 'Externer Link',
                'link' => "{$BASE_URL}/portal.php?t={$token}",
                'token' => $token,
                'type' => 'link'
            ];
        }
        if ($responses) jsonResponse(['status' => 'success', 'data' => $responses]);
    }

    jsonResponse(['error' => 'Keine gültigen Daten empfangen.'], 400);
}


/* ==========================================================
   5. VIEW LOGIC
========================================================== */
$clip = null;
$locked = false;

if (!empty($_GET['t'])) {
    $stmt = $pdo->prepare("SELECT * FROM clips WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$_GET['t']]);
    $clip = $stmt->fetch();

    if ($clip) {
        if ($clip['is_private']) {
            if (isset($_POST['unlock'])) {
                if (password_verify($_POST['clip_password'] ?? '', $clip['password_hash'])) {
                    $_SESSION['unlocked_' . $clip['token']] = true;
                } else {
                    $errorMsg = "Falsches Passwort";
                }
            }
            $locked = empty($_SESSION['unlocked_' . $clip['token']]);
        }

        // Externe Links direkt weiterleiten, falls nicht gesperrt
        if (!$locked && $clip['type'] === 'external') {
            $pdo->prepare("UPDATE clips SET views = views + 1 WHERE id = ?")->execute([$clip['id']]);
            header("Location: " . $clip['external']);
            exit;
        }

        if (!$locked) {
            $pdo->prepare("UPDATE clips SET views = views + 1 WHERE id = ?")->execute([$clip['id']]);
        }
    }
}

// Historien
$history = $pdo->query("SELECT * FROM clips WHERE is_private = 0 ORDER BY created_at DESC LIMIT 10")->fetchAll();
$adminClips = !empty($_SESSION['admin_logged_in']) ? $pdo->query("SELECT * FROM clips ORDER BY created_at DESC LIMIT 50")->fetchAll() : [];
?>

<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NRW RP Cloud | Sicher. Einfach. Schnell</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            /* Cloudflare-inspired Palette */
            --cf-orange: #3B82F6;
            --cf-orange-hover: #2563EB;
            --bg-dark: #1D1D1D;
            --bg-card: #2C2C2C;
            --text-main: #FFFFFF;
            --text-muted: #9A9A9A;
            --border: #404040;
            --danger: #EF4444;
            --success: #10B981;
        }

        * { box-sizing: border-box; outline: none; -webkit-tap-highlight-color: transparent; }
        
        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        a { text-decoration: none; color: inherit; transition: 0.2s; }
        
        /* Layout */
        .container {
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
            width: 100%;
        }

        /* Header / Nav */
        .nav-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }
        .logo { font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; gap: 10px; }
        .logo i { color: var(--cf-orange); }

        /* Tabs */
        .tabs {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 5px;
            background: var(--bg-card);
            padding: 5px;
            border-radius: 12px;
            margin-bottom: 25px;
        }
        .tab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        .tab-btn:hover { color: var(--text-main); background: rgba(255,255,255,0.05); }
        .tab-btn.active { background: var(--border); color: var(--cf-orange); }

        /* Cards */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 30px;
            display: none;
            animation: fadeIn 0.3s ease;
        }
        .card.active { display: block; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        h2 { margin-top: 0; font-weight: 700; display: flex; align-items: center; gap: 10px; font-size: 1.25rem; }
        
        /* Forms & Inputs */
        .input-group { margin-bottom: 15px; }
        label { display: block; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 8px; font-weight: 600; }
        
        input, select, textarea {
            width: 100%;
            background: rgba(0,0,0,0.2);
            border: 1px solid var(--border);
            padding: 12px 15px;
            border-radius: 8px;
            color: var(--text-main);
            font-family: inherit;
            transition: 0.2s;
        }
        input:focus, textarea:focus { border-color: var(--cf-orange); }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: 0.2s;
            gap: 8px;
            width: 100%;
        }
        .btn-primary { background: var(--cf-orange); color: white; }
        .btn-primary:hover { background: var(--cf-orange-hover); }
        .btn-secondary { background: var(--border); color: white; }
        .btn-danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
        
        /* Drag & Drop Zone */
        .dropzone {
            border: 2px dashed var(--border);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: 0.2s;
            background: rgba(255,255,255,0.01);
        }
        .dropzone:hover, .dropzone.dragover { border-color: var(--cf-orange); background: rgba(246, 130, 31, 0.05); }
        .dropzone i { font-size: 3rem; color: var(--text-muted); margin-bottom: 15px; }

        /* File List */
        .file-preview-list { margin-top: 20px; display: grid; gap: 10px; }
        .file-item {
            display: flex; justify-content: space-between; align-items: center;
            background: rgba(0,0,0,0.3); padding: 12px; border-radius: 8px; border-left: 3px solid var(--cf-orange);
        }

        /* Table */
        .data-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .data-table th { text-align: left; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); padding: 10px; border-bottom: 1px solid var(--border); }
        .data-table td { padding: 12px 10px; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.9rem; }
        .data-table tr:last-child td { border-bottom: none; }

        /* Toast Notification */
        .toast-container { position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; }
        .toast {
            background: var(--bg-card); color: white; padding: 15px 20px; border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.5); border-left: 4px solid var(--cf-orange);
            display: flex; align-items: center; gap: 10px; min-width: 250px;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }

        /* Helpers */
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
        .badge.private { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .badge.public { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        
        .qr-box { background: white; padding: 10px; display: inline-block; border-radius: 8px; margin-top: 15px; }
    </style>
</head>
<body>

<div class="container">
    <div class="nav-header">
        <div class="logo">
            <i class="fa-solid fa-cloud"></i> NRW RP Cloud
        </div>
		<a href="https://team.nrw-roleplay.de" class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-slate-400 hover:text-white bg-slate-800 border border-slate-700 rounded-md transition duration-200">
    <i class="fa-solid fa-chevron-left mr-2"></i> Zum Team-Dashboard
</a>
        <div style="font-size: 0.8rem; color: var(--text-muted);">
            Sicher. Einfach. Schnell
        </div>
    </div>

    <div class="tabs">
        <button class="tab-btn active" onclick="showTab('upload', this)">
            <i class="fa-solid fa-cloud-arrow-up"></i> Upload
        </button>
        <button class="tab-btn" id="btn-view" onclick="showTab('view', this)">
            <i class="fa-solid fa-play-circle"></i> Player
        </button>
        <button class="tab-btn" onclick="showTab('history', this)">
            <i class="fa-solid fa-clock-rotate-left"></i> Verlauf
        </button>
        <button class="tab-btn" onclick="showTab('admin', this)">
            <i class="fa-solid fa-shield-halved"></i> Admin
        </button>
    </div>

<div id="upload" class="card active">
    <h2><i class="fa-solid fa-file-import"></i> Datei & Link Upload</h2>
    
    <div class="dropzone" id="dropzone">
        <i class="fa-solid fa-cloud-arrow-up"></i>
        <h3 style="margin: 0; color: var(--text-main);">Dateien hier ablegen</h3>
        <p style="margin: 5px 0 0 0; font-size: 0.85rem; color: var(--text-muted);">
            oder klicken zum Auswählen (Max. <?= MAX_UPLOAD_MB ?> MB)
        </p>
        <input type="file" id="fileInput" multiple hidden>
    </div>

    <div id="fileQueue" class="file-preview-list"></div>

    <div class="input-group" style="margin-top: 20px;">
        <label>Externe Links (Optional, einer pro Zeile)</label>
        <textarea id="urlInput" rows="3" placeholder="https://example.com/wichtiges-dokument"></textarea>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px;">
        <div>
            <label>Gültigkeitsdauer</label>
            <select id="profile">
                <option value="short">1 Stunde (Express)</option>
                <option value="standard" selected>2 Tage</option>
				<option value="three" selected>3 Tage (Standart)</option>
                <option value="long">7 Tage (Langzeit)</option>
            </select>
        </div>

        <div>
            <label>Sicherheit</label>
            <div style="display: flex; align-items: center; gap: 10px; height: 42px;">
                <div style="display: flex; align-items: center; gap: 10px; opacity: 0.6; cursor: not-allowed;">
                    <input type="checkbox" id="privateToggle" style="width: 20px; height: 20px; margin: 0;">

                    <span style="font-size: 0.9rem;">Passwortschutz aktivieren</span>
                </div>
                
                <div class="info-wrapper" style="position: relative; display: inline-block;">
                    <i class="fa-solid fa-circle-info" 
                       style="color: var(--cf-orange); cursor: help; font-size: 1.1rem;"
                       onmouseover="document.getElementById('info-tooltip').style.display='block'"
                       onmouseout="document.getElementById('info-tooltip').style.display='none'">
                    </i>
                    <div id="info-tooltip" style="
                        display: none;
                        position: absolute;
                        bottom: 130%;
                        left: 50%;
                        transform: translateX(-50%);
                        background: var(--bg-card);
                        border: 1px solid var(--border);
                        color: var(--text-main);
                        padding: 10px;
                        border-radius: 8px;
                        font-size: 0.75rem;
                        width: 200px;
                        z-index: 100;
                        box-shadow: 0 5px 15px rgba(0,0,0,0.5);
                        text-align: center;
                    ">
                        <div style="font-weight: bold; margin-bottom: 5px; color: var(--cf-orange);">Funktion deaktiviert</div>
                       Hinweis: Der Passwortschutz ist derzeit aufgrund von Wartungsarbeiten am Verschlüsselungssystem nicht vollständig nutzbar. Es kann zu Fehlern kommen, aber grundsätzlich funktioniert der Upload/Clip-Zugriff weiterhin.

                        <div style="position: absolute; top: 100%; left: 50%; margin-left: -5px; border-width: 5px; border-style: solid; border-color: var(--border) transparent transparent transparent;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div> <div id="passwordField" style="display: none; margin-top: 15px;">
        <input type="password" id="passwordInput" placeholder="Zugriffspasswort festlegen...">
    </div>

    <button class="btn btn-primary" onclick="startUpload()" style="margin-top: 20px;">
        <i class="fa-solid fa-rocket"></i> Jetzt Hochladen
    </button>
</div> 

<div id="view" class="card">
    <?php if(!$clip): ?>
        <div style="text-align: center; padding: 40px 0;">
            <i class="fa-solid fa-magnifying-glass" style="font-size: 3rem; color: var(--border); margin-bottom: 20px;"></i>
            <h3>Inhalt finden</h3>
            <p style="color: var(--text-muted); max-width: 400px; margin: 0 auto 20px auto;">
                Gib den Token oder den vollständigen Link von team.nrw-roleplay.de ein.
            </p>

            <div style="display: flex; gap: 10px; max-width: 450px; margin: auto;">
                <input type="text" id="tokenSearch" placeholder="Token oder Link hier einfügen..." 
                       style="flex-grow: 1;"
                       onkeypress="if(event.key === 'Enter') document.getElementById('searchBtn').click()">
                
                <button id="searchBtn" class="btn btn-secondary" style="width: auto;"
                    onclick="
                        (function(){
                            let input = document.getElementById('tokenSearch').value.trim();
                            if (!input) return;

                            let token = input;

                            // 1. Extraktion aus URL-Parametern (z.B. ?t=ABC)
                            if (input.includes('?')) {
                                try {
                                    const queryString = input.split('?')[1];
                                    const urlParams = new URLSearchParams(queryString);
                                    if (urlParams.has('t')) {
                                        token = urlParams.get('t');
                                    }
                                } catch(e) { console.error(e); }
                            } 
                            // 2. Extraktion aus Pfaden (z.B. domain.de/ABC)
                            else if (input.includes('/')) {
                                token = input.split('/').pop();
                            }

                            // Weiterleitung zum Viewer
                            if (token) {
                                location.href = '?t=' + encodeURIComponent(token);
                            }
                        })()
                    ">
                    Go
                </button>
            </div>
        </div>
    <?php else: ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <a href="portal.php" class="btn btn-secondary" style="width: auto; padding: 5px 10px;">
                    <i class="fa-solid fa-arrow-left"></i> Zurück
                </a>
                <h2 style="margin: 0;"><i class="fa-solid fa-eye"></i> Clip Ansicht</h2>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Views</div>
                <div style="font-size: 1.2rem; font-weight: 700; color: var(--cf-orange);"><?= $clip['views'] ?></div>
            </div>
        </div>

        <?php if($locked): ?>
            <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--danger); padding: 40px; border-radius: 12px; text-align: center;">
                <i class="fa-solid fa-lock" style="font-size: 3rem; color: var(--danger); margin-bottom: 20px;"></i>
                <h3>Geschützter Inhalt</h3>

<form method="post">
    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?>">
    
    <input type="password" name="clip_password" placeholder="Passwort eingeben..." required style="max-width: 300px; margin: 15px auto;">
    <button name="unlock" class="btn btn-primary" style="max-width: 300px; margin: 10px auto;">Entsperren</button>
    
    <?php if(isset($errorMsg)): ?>
        <p style="color: var(--danger); margin-top: 10px;"><?= $errorMsg ?></p>
    <?php endif; ?>
</form>

            </div>
        <?php else: ?>
            <div style="background: black; border-radius: 12px; overflow: hidden; margin-bottom: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
                <?php if($clip['type'] === 'video'): ?>
                    <video controls autoplay style="width: 100%; display: block;"><source src="storage/<?=h($clip['file'])?>"></video>
                <?php elseif($clip['type'] === 'audio'): ?>
                    <div style="padding: 50px 20px; text-align: center;">
                        <i class="fa-solid fa-music" style="font-size: 4rem; color: var(--border); margin-bottom: 20px; display: block;"></i>
                        <audio controls style="width: 100%;"><source src="storage/<?=h($clip['file'])?>"></audio>
                    </div>
                <?php elseif($clip['type'] === 'image'): ?>
                    <img src="storage/<?=h($clip['file'])?>" style="width: 100%; display: block;">
                <?php else: ?>
                    <div style="padding: 60px; text-align: center;">
                        <i class="fa-solid fa-file-lines" style="font-size: 4rem; color: var(--text-muted);"></i>
                        <h3 style="margin: 20px 0;">Datei verfügbar</h3>
                        <a href="storage/<?=h($clip['file'])?>" download class="btn btn-primary" style="display: inline-flex; width: auto;">
                            <i class="fa-solid fa-download"></i> Herunterladen
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div style="background: rgba(255,255,255,0.05); padding: 20px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                <div>
                    <small style="color: var(--text-muted);">LINK ZUM TEILEN</small>
                    <div style="display: flex; gap: 10px; margin-top: 5px;">
                        <input type="text" value="<?= $BASE_URL ?>/portal.php?t=<?= $clip['token'] ?>" id="shareUrl" readonly style="width: 250px;">
                        <button onclick="copyToClip('shareUrl')" class="btn btn-secondary" style="width: auto;"><i class="fa-regular fa-copy"></i></button>
                    </div>
                </div>
                <div style="text-align: center;">
                    <small style="color: var(--text-muted);">SCAN ME</small><br>
                    <div id="qrcode" class="qr-box" style="padding: 5px; background: white; border-radius: 4px;"></div>
                </div>
            </div>

            <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
            <script>
                new QRCode(document.getElementById("qrcode"), {
                    text: "<?= $BASE_URL ?>/portal.php?t=<?= $clip['token'] ?>",
                    width: 64, height: 64, colorDark : "#000000", colorLight : "#ffffff"
                });
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>

    <div id="history" class="card">
        <h2><i class="fa-solid fa-globe"></i> Öffentlicher Verlauf</h2>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead><tr><th>Typ</th><th>Name / Link</th><th>Ablauf</th><th>Aktion</th></tr></thead>
                <tbody>
                <?php foreach($history as $h): ?>
                    <tr>
                        <td>
                            <?php 
                                $icon = match($h['type']) { 'video'=>'video', 'audio'=>'music', 'image'=>'image', 'external'=>'link', default=>'file' };
                            ?>
                            <i class="fa-solid fa-<?= $icon ?>" style="color: var(--text-muted);"></i>
                        </td>
                        <td>
                            <div style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?= h($h['external'] ?? $h['file']) ?>
                            </div>
                        </td>
                        <td style="color: var(--text-muted);"><?= date('d.m. H:i', strtotime($h['expires_at'])) ?></td>
                        <td style="text-align: right;">
                            <a href="?t=<?= $h['token'] ?>" class="btn btn-secondary" style="padding: 5px 10px; width: auto; font-size: 0.8rem;">
                                Öffnen
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="admin" class="card">
        <h2><i class="fa-solid fa-server"></i> Administration</h2>
        
        <?php if(empty($_SESSION['admin_logged_in'])): ?>
            <form method="post" style="max-width: 300px; margin: 40px auto; text-align: center;">
                <p>Bitte authentifizieren.</p>
                <input type="password" name="password" placeholder="Admin Passwort" required style="margin-bottom: 10px;">
                <button name="admin_login" class="btn btn-primary">Login</button>
            </form>
        <?php else: ?>
            <div style="display: flex; justify-content: space-between; margin-bottom: 20px; background: rgba(16, 185, 129, 0.1); padding: 15px; border-radius: 8px;">
                <span style="color: var(--success); font-weight: bold;"><i class="fa-solid fa-check-circle"></i> Angemeldet</span>
                <a href="?logout=1" style="color: var(--danger); font-weight: 600;">Logout</a>
            </div>
            
            <table class="data-table">
                <thead><tr><th>Token</th><th>Inhalt</th><th>Status</th><th>Del</th></tr></thead>
                <tbody>
                <?php foreach($adminClips as $ac): ?>
                    <tr>
                        <td style="font-family: monospace; color: var(--cf-orange);"><?= substr($ac['token'], 0, 8) ?>...</td>
                        <td>
                            <div style="max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?= h($ac['external'] ?? $ac['file']) ?>
                            </div>
                        </td>
                        <td><?= $ac['is_private'] ? '<span class="badge private">Privat</span>' : '<span class="badge public">Public</span>' ?></td>
                        <td style="text-align: right;">
                            <a href="?delete=<?= $ac['token'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>" 
                               onclick="return confirm('Wirklich löschen?')" 
                               style="color: var(--danger);">
                               <i class="fa-solid fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div> <div class="toast-container" id="toastContainer"></div>

<script>
const CSRF_TOKEN = "<?= $_SESSION['csrf_token'] ?>";
let fileQueue = [];

// --- Tabs Logic ---
function showTab(id, btn) {
    document.querySelectorAll('.card').forEach(c => c.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    history.replaceState(null, null, '#' + id);
}

// --- Init ---
window.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash.replace('#', '');
    if (hash) {
        const btn = document.querySelector(`.tab-btn[onclick*="'${hash}'"]`);
        if (btn) showTab(hash, btn);
    } else if (new URLSearchParams(window.location.search).has('t')) {
        showTab('view', document.getElementById('btn-view'));
    }

    document.getElementById('privateToggle').addEventListener('change', (e) => {
        document.getElementById('passwordField').style.display = e.target.checked ? 'block' : 'none';
    });

    const dz = document.getElementById('dropzone');
    const inp = document.getElementById('fileInput');

    dz.addEventListener('click', () => inp.click());
    dz.addEventListener('dragover', (e) => { e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
    dz.addEventListener('drop', (e) => {
        e.preventDefault(); dz.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });
    inp.addEventListener('change', () => handleFiles(inp.files));
});

// --- Queue Management ---
function handleFiles(files) {
    for (let f of files) fileQueue.push(f);
    renderQueue();
}

function renderQueue() {
    const container = document.getElementById('fileQueue');
    container.innerHTML = fileQueue.map((f, i) => `
        <div class="file-item">
            <span><i class="fa-solid fa-file"></i> ${f.name}</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer; color:var(--danger)" onclick="removeFromQueue(${i})"></i>
        </div>
    `).join('');
}

function removeFromQueue(index) {
    fileQueue.splice(index, 1);
    renderQueue();
}

// --- Toast Notification ---
function showToast(msg, type = 'info') {
    const box = document.createElement('div');
    box.className = 'toast';
    let icon = type === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-check';
    box.innerHTML = `<i class="fa-solid ${icon}"></i> <span>${msg}</span>`;
    document.getElementById('toastContainer').appendChild(box);
    setTimeout(() => box.remove(), 4000);
}

// --- Copy to Clipboard ---
function copyToClip(elemId) {
    const el = document.getElementById(elemId);
    el.select();
    navigator.clipboard.writeText(el.value).then(() => showToast('Link kopiert!'));
}

// --- Upload Logic ---
async function startUpload() {

// --- NEU: 20 MB Limit Prüfung ---
    const MAX_SIZE_BYTE = 80 * 1024 * 1024; // 20 MB in Bytes
    for (let file of fileQueue) {
        if (file.size > MAX_SIZE_BYTE) {
            showToast(`Datei "${file.name}" ist zu groß (max. 80 MB erlaubt)`, 'error');
            return; // Upload abbrechen
        }
    }

    const urls = document.getElementById('urlInput').value.trim();
    if (fileQueue.length === 0 && urls === "") {
        showToast('Bitte Datei oder Link hinzufügen', 'error');
        return;
    }

    const btn = document.querySelector('#upload .btn-primary');
    const originalText = btn.innerHTML;
    btn.disabled = true;

    const CHUNK_SIZE = 1.5 * 1024 * 1024; // 1.5 MB
    const responses = [];

    try {
        // --- 1. Dateien Upload ---
        for (let file of fileQueue) {
            const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
            const fileToken = Math.random().toString(36).substring(2, 15);

            for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                const start = chunkIndex * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, file.size);
                const chunk = file.slice(start, end);

                const fd = new FormData();
                fd.append('upload_chunk', '1');
                fd.append('file_chunk', chunk);
                fd.append('file_name', file.name);
                fd.append('file_token', fileToken);
                fd.append('chunk_index', chunkIndex);
                fd.append('total_chunks', totalChunks);
                fd.append('profile', document.getElementById('profile').value);
                fd.append('private', document.getElementById('privateToggle').checked ? '1' : '0');
                fd.append('password', document.getElementById('passwordInput').value);
                fd.append('csrf', CSRF_TOKEN);

                btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> ${file.name} (${Math.round((chunkIndex / totalChunks) * 100)}%)`;

                let res;
                try {
                    const req = await fetch('', { method: 'POST', body: fd });
                    if (!req.ok) throw new Error(`HTTP Error: ${req.status}`);
                    res = await req.json();
                } catch (e) {
                    throw new Error(`Upload von "${file.name}" fehlgeschlagen: ${e.message}`);
                }

                if (res.error) throw new Error(res.error);
                if (res.status === 'completed') responses.push(res.data);
            }
        }

        // --- 2. Externe Links ---
        if (urls !== "") {
            const fdLinks = new FormData();
            fdLinks.append('upload', '1');
            fdLinks.append('urls', urls);
            fdLinks.append('profile', document.getElementById('profile').value);
            fdLinks.append('private', document.getElementById('privateToggle').checked ? '1' : '0');
            fdLinks.append('password', document.getElementById('passwordInput').value);
            fdLinks.append('csrf', CSRF_TOKEN);

            btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Links werden hochgeladen...`;

            try {
                const req = await fetch('', { method: 'POST', body: fdLinks });
                if (!req.ok) throw new Error(`HTTP Error: ${req.status}`);
                const res = await req.json();

                if (res.error) throw new Error(res.error);
                if (res.data) responses.push(...res.data);
            } catch (e) {
                showToast(`Link-Upload fehlgeschlagen: ${e.message}`, 'error');
            }
        }

        // --- 3. UI Update ---
        fileQueue = [];
        document.getElementById('urlInput').value = '';
        renderQueue();
        showToast('Upload erfolgreich!');

        const resultHtml = responses.map(item => `
            <div class="file-item" style="margin-top:10px; border-color: var(--success);">
                <div>
                    <strong>${item.name}</strong><br>
                    <a href="${item.link}" target="_blank" style="color:var(--cf-orange); font-size:0.85rem;">${item.link}</a>
                </div>
                <button class="btn btn-secondary" onclick="navigator.clipboard.writeText('${item.link}');showToast('Kopiert!')" style="width:auto; padding:5px 10px;">
                    <i class="fa-regular fa-copy"></i>
                </button>
            </div>
        `).join('');
        document.getElementById('fileQueue').innerHTML = `<h3 style="color:var(--success)">Fertig!</h3>` + resultHtml;

    } catch (err) {
        showToast(err.message || 'Unbekannter Serverfehler', 'error');
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}
</script>


</body>
</html>
