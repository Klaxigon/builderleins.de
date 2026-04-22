<?php
session_start();

$schematicsDbPath = __DIR__ . '/schematics.db';
try {
    $sdb = new SQLite3($schematicsDbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $sdb->exec("CREATE TABLE IF NOT EXISTS schematic_icons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        folder TEXT NOT NULL DEFAULT '',
        item_name TEXT NOT NULL,
        item_type TEXT NOT NULL DEFAULT 'schematic',
        icon TEXT,
        drive_link TEXT,
        copy_count INTEGER DEFAULT 0,
        assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(folder, item_name, item_type)
    )");
} catch (Exception $e) {
    die("Schematics-DB Fehler: " . $e->getMessage());
}

try {
    $db = new SQLite3(__DIR__ . '/users.db', SQLITE3_OPEN_READWRITE);
} catch (Exception $e) {
    die("Users-DB Fehler: " . $e->getMessage());
}

if (isset($_POST['change_password']) && isset($_SESSION['user'])) {
    $currentPw = $_POST['current_password'] ?? '';
    $newPw = $_POST['new_password'] ?? '';
    $confirmPw = $_POST['confirm_password'] ?? '';

    if ($newPw === $confirmPw && $newPw !== '') {
        $stmt = $db->prepare("SELECT password FROM users WHERE username = :user");
        $stmt->bindValue(':user', $_SESSION['user'], SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row && password_verify($currentPw, $row['password'])) {
            $newHash = password_hash($newPw, PASSWORD_DEFAULT);
            $update = $db->prepare("UPDATE users SET password = :hash WHERE username = :user");
            $update->bindValue(':hash', $newHash, SQLITE3_TEXT);
            $update->bindValue(':user', $_SESSION['user'], SQLITE3_TEXT);
            if ($update->execute()) {
                $_SESSION['uploadMsg'] = "Passwort erfolgreich geändert!";
            } else {
                $_SESSION['uploadMsg'] = "Fehler beim Speichern des neuen Passworts.";
            }
        } else {
            $_SESSION['uploadMsg'] = "Aktuelles Passwort ist falsch.";
        }
    } else {
        $_SESSION['uploadMsg'] = "Neue Passwörter stimmen nicht überein oder sind leer.";
    }
    header("Location: index.php" . ($currentFolder ? "?folder=" . urlencode($currentFolder) : ""));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && !isset($_SESSION['user'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $stmt = $db->prepare("SELECT password FROM users WHERE username = :user");
    $stmt->bindValue(':user', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if ($row && password_verify($password, $row['password'])) {
        $_SESSION['user'] = $username;
        header("Location: index.php");
        exit;
    } else {
        $loginError = "Falscher Benutzername oder Passwort.";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

$uploadMsg = $_SESSION['uploadMsg'] ?? '';
unset($_SESSION['uploadMsg']);

$uploadDir = __DIR__ . '/uploads/';
$currentFolder = isset($_GET['folder']) ? basename($_GET['folder']) : '';
$basePath = $currentFolder ? $uploadDir . $currentFolder . '/' : $uploadDir;

if ($currentFolder && !is_dir($basePath)) {
    $currentFolder = '';
    $basePath = $uploadDir;
}
if (!is_dir($basePath)) mkdir($basePath, 0755, true);

if (isset($_POST['add_drive_link']) && isset($_SESSION['user'])) {
    $item = trim($_POST['item'] ?? '');
    $link = trim($_POST['drive_link'] ?? '');
    $folderKey = $currentFolder ?: '';

    if ($item) {
        if ($link !== '') {
            $stmt = $sdb->prepare("UPDATE schematic_icons SET drive_link = :link, copy_count = 0
                                   WHERE folder = :f AND item_name = :n AND item_type = 'schematic'");
            $stmt->bindValue(':f', $folderKey, SQLITE3_TEXT);
            $stmt->bindValue(':n', $item, SQLITE3_TEXT);
            $stmt->bindValue(':link', $link, SQLITE3_TEXT);
            $stmt->execute();

            if ($sdb->changes() > 0) {
                $_SESSION['uploadMsg'] = "Drive-Link für '$item' aktualisiert (Counter zurückgesetzt)!";
            } else {
                $stmt = $sdb->prepare("INSERT INTO schematic_icons
                                       (folder, item_name, item_type, drive_link, icon, copy_count)
                                       VALUES (:f, :n, 'schematic', :link, NULL, 0)");
                $stmt->bindValue(':f', $folderKey, SQLITE3_TEXT);
                $stmt->bindValue(':n', $item, SQLITE3_TEXT);
                $stmt->bindValue(':link', $link, SQLITE3_TEXT);
                if ($stmt->execute()) {
                    $_SESSION['uploadMsg'] = "Drive-Link für '$item' gespeichert (Counter = 0)!";
                } else {
                    $_SESSION['uploadMsg'] = "FEHLER beim Speichern: " . $sdb->lastErrorMsg();
                }
            }
        } else {
            $stmt = $sdb->prepare("UPDATE schematic_icons SET drive_link = NULL
                                   WHERE folder = :f AND item_name = :n AND item_type = 'schematic'");
            $stmt->bindValue(':f', $folderKey, SQLITE3_TEXT);
            $stmt->bindValue(':n', $item, SQLITE3_TEXT);
            $stmt->execute();
            $_SESSION['uploadMsg'] = "Drive-Link für '$item' entfernt.";
        }
    }
    header("Location: index.php" . ($currentFolder ? "?folder=" . urlencode($currentFolder) : ""));
    exit;
}

if (isset($_POST['copy_link']) && isset($_SESSION['user'])) {
    $item = trim($_POST['item'] ?? '');
    $folderKey = $currentFolder ?: '';
    if ($item) {
        $stmt = $sdb->prepare("UPDATE schematic_icons SET copy_count = copy_count + 1
                               WHERE folder = :f AND item_name = :n AND item_type = 'schematic'");
        $stmt->bindValue(':f', $folderKey, SQLITE3_TEXT);
        $stmt->bindValue(':n', $item, SQLITE3_TEXT);
        $stmt->execute();
    }
    exit;
}

if (isset($_POST['rename']) && isset($_SESSION['user'])) {
    $oldName = trim($_POST['old_name'] ?? '');
    $newBase = trim($_POST['new_name'] ?? '');
    $type = $_POST['type'] ?? '';
    if ($oldName && $newBase) {
        $oldPath = $basePath . $oldName;
        $ext = ($type !== 'folder') ? pathinfo($oldName, PATHINFO_EXTENSION) : '';
        $newName = preg_replace('/[^a-zA-Z0-9_ -]/', '', $newBase) . ($ext ? '.' . $ext : '');
        $newPath = $basePath . $newName;

        if (file_exists($oldPath) && !file_exists($newPath)) {
            if (rename($oldPath, $newPath)) {
                if ($type === 'schematic') {
                    $folderKey = $currentFolder ?: '';
                    $stmt = $sdb->prepare("UPDATE schematic_icons SET item_name = :new
                                           WHERE folder = :folder AND item_name = :old AND item_type = 'schematic'");
                    $stmt->bindValue(':new', $newName, SQLITE3_TEXT);
                    $stmt->bindValue(':folder', $folderKey, SQLITE3_TEXT);
                    $stmt->bindValue(':old', $oldName, SQLITE3_TEXT);
                    $stmt->execute();
                }
                $_SESSION['uploadMsg'] = "'$oldName' wurde zu '$newName' umbenannt.";
            } else {
                $_SESSION['uploadMsg'] = "Umbenennen fehlgeschlagen.";
            }
        } else {
            $_SESSION['uploadMsg'] = "Datei/Ordner existiert bereits oder wurde nicht gefunden.";
        }
    }
    header("Location: index.php" . ($currentFolder ? "?folder=" . urlencode($currentFolder) : ""));
    exit;
}

if (isset($_POST['delete']) && isset($_SESSION['user'])) {
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? '';
    if ($name) {
        $path = $basePath . $name;
        if (file_exists($path)) {
            if ($type === 'folder') {
                function rrmdir($dir) {
                    if (is_dir($dir)) {
                        foreach (scandir($dir) as $obj) {
                            if ($obj != "." && $obj != "..") {
                                is_dir($dir . "/" . $obj) ? rrmdir($dir . "/" . $obj) : unlink($dir . "/" . $obj);
                            }
                        }
                        rmdir($dir);
                    }
                }
                rrmdir($path);
            } else {
                unlink($path);
                $folderKey = $currentFolder ?: '';
                $stmt = $sdb->prepare("DELETE FROM schematic_icons WHERE folder = :f AND item_name = :n");
                $stmt->bindValue(':f', $folderKey, SQLITE3_TEXT);
                $stmt->bindValue(':n', $name, SQLITE3_TEXT);
                $stmt->execute();
            }
            $_SESSION['uploadMsg'] = "'$name' wurde gelöscht.";
        } else {
            $_SESSION['uploadMsg'] = "Datei/Ordner nicht gefunden.";
        }
    }
    header("Location: index.php" . ($currentFolder ? "?folder=" . urlencode($currentFolder) : ""));
    exit;
}

if (isset($_POST['assign_icon']) && isset($_SESSION['user'])) {
    $item      = trim($_POST['item'] ?? '');
    $type      = trim($_POST['type'] ?? '');
    $icon      = trim($_POST['icon'] ?? '');
    $folderKey = $currentFolder ?: '';

    if ($item && $type && $icon) {
      
        $check = $sdb->prepare("SELECT 1 FROM schematic_icons 
                                WHERE folder = :f AND item_name = :n AND item_type = :t");
        $check->bindValue(':f', $folderKey, SQLITE3_TEXT);
        $check->bindValue(':n', $item, SQLITE3_TEXT);
        $check->bindValue(':t', $type, SQLITE3_TEXT);
        $exists = $check->execute()->fetchArray();

        if ($exists) {

            $stmt = $sdb->prepare("UPDATE schematic_icons 
                                   SET icon = :icon 
                                   WHERE folder = :f AND item_name = :n AND item_type = :t");
        } else {

            $stmt = $sdb->prepare("INSERT INTO schematic_icons 
                                   (folder, item_name, item_type, icon) 
                                   VALUES (:f, :n, :t, :icon)");
        }

        $stmt->bindValue(':f', $folderKey, SQLITE3_TEXT);
        $stmt->bindValue(':n', $item, SQLITE3_TEXT);
        $stmt->bindValue(':t', $type, SQLITE3_TEXT);
        $stmt->bindValue(':icon', $icon, SQLITE3_TEXT);

        if ($stmt->execute()) {
            $_SESSION['uploadMsg'] = "Icon für '$item' erfolgreich zugewiesen!";
        } else {
            $_SESSION['uploadMsg'] = "Fehler beim Icon-Zuweisen: " . $sdb->lastErrorMsg();
        }
    }
    header("Location: index.php" . ($currentFolder ? "?folder=" . urlencode($currentFolder) : ""));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_folder']) && isset($_SESSION['user'])) {
    $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['foldername'] ?? ''));
    if ($folderName) {
        $newPath = $basePath . $folderName;
        if (!is_dir($newPath)) {
            if (mkdir($newPath, 0755, true)) {
                $_SESSION['uploadMsg'] = "Ordner '$folderName' erfolgreich erstellt!";
            } else {
                $_SESSION['uploadMsg'] = "Ordner konnte nicht erstellt werden.";
            }
        } else {
            $_SESSION['uploadMsg'] = "Ordner existiert bereits.";
        }
    }
    header("Location: index.php" . ($currentFolder ? "?folder=" . urlencode($currentFolder) : ""));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && isset($_SESSION['user'])) {
    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['schem', 'schematic', 'litematic', 'jpg', 'jpeg', 'png', 'zip'];
    if (!in_array($ext, $allowed)) {
        $_SESSION['uploadMsg'] = "Nur .zip, .schem, .schematic, .litematic und Bilder erlaubt.";
    } elseif ($file['error'] === UPLOAD_ERR_OK) {
        $targetFile = $basePath . $file['name'];
        if (file_exists($targetFile)) {
            $info = pathinfo($file['name']);
            $targetFile = $basePath . $info['filename'] . '_' . uniqid() . '.' . $info['extension'];
        }
        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
            $_SESSION['uploadMsg'] = "Datei erfolgreich hochgeladen!";
        } else {
            $_SESSION['uploadMsg'] = "Fehler beim Hochladen der Datei.";
        }
    }
    header("Location: index.php" . ($currentFolder ? "?folder=" . urlencode($currentFolder) : ""));
    exit;
}

function getItemData($sdb, $folder, $item, $type) {
    $stmt = $sdb->prepare("SELECT icon, drive_link, copy_count FROM schematic_icons
                           WHERE folder = :f AND item_name = :n AND item_type = :t LIMIT 1");
    $stmt->bindValue(':f', $folder ?: '', SQLITE3_TEXT);
    $stmt->bindValue(':n', $item, SQLITE3_TEXT);
    $stmt->bindValue(':t', $type, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: ['icon' => null, 'drive_link' => null, 'copy_count' => 0];
}

$folders = [];
$schematics = [];
$images = [];
$items = array_diff(scandir($basePath), ['.', '..']);

foreach ($items as $item) {
    $fullPath = $basePath . $item;
    $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
    if (is_dir($fullPath)) $folders[] = $item;
    elseif (in_array($ext, ['schem', 'schematic', 'litematic', 'zip'])) $schematics[] = $item;
    elseif (in_array($ext, ['jpg','jpeg','png'])) $images[] = $item;
}

$availableImages = [];
foreach ($images as $img) {
    $prefix = $currentFolder ? $currentFolder . '/' : '';
    $availableImages[] = [
        'name' => $img,
        'src' => 'uploads/' . $prefix . $img
    ];
}

$chartData = [];
$showChart = false;

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/(android|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile)/i', $userAgent);

if (!$isMobile && $currentFolder && !empty($schematics)) {
    foreach ($schematics as $file) {
        $data = getItemData($sdb, $currentFolder, $file, 'schematic');
        $chartData[] = ['name' => $file, 'copies' => (int)$data['copy_count']];
    }
    usort($chartData, fn($a, $b) => $b['copies'] <=> $a['copies']);
    $showChart = true;
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Builderleins Schematics</title>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="container">
<?php if (!isset($_SESSION['user'])): ?>
    <div class="login-container">
        <h2>Login</h2>
        <?php if (isset($loginError)): ?>
            <div class="error"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Passwort" required>
            <button type="submit">Anmelden</button>
        </form>
    </div>
<?php else: ?>
    <div class="header">
        <h2><?= htmlspecialchars($currentFolder ?: 'Minecraft Schematics') ?></h2>
        <div style="display:flex; align-items:center; gap:15px;">
            <button id="settings-btn"
                    style="background:none; border:none; font-size:26px; color:#38bdf8; cursor:pointer; padding:4px 8px; border-radius:8px; transition:0.2s;"
                    title="Passwort ändern">
                <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="35px" fill="#ffffff"><path d="M80-200v-80h800v80H80Zm46-242-52-30 34-60H40v-60h68l-34-58 52-30 34 58 34-58 52 30-34 58h68v60h-68l34 60-52 30-34-60-34 60Zm320 0-52-30 34-60h-68v-60h68l-34-58 52-30 34 58 34-58 52 30-34 58h68v60h-68l34 60-52 30-34-60-34 60Zm320 0-52-30 34-60h-68v-60h68l-34-58 52-30 34 58 34-58 52 30-34 58h68v60h-68l34 60-52 30-34-60-34 60Z"/></svg>
            </button>
            <a class="logout" href="?logout">Logout (<?= htmlspecialchars($_SESSION['user']) ?>)</a>
        </div>
    </div>

    <?php if ($uploadMsg): ?>
        <div class="upload-msg"><?= htmlspecialchars($uploadMsg) ?></div>
    <?php endif; ?>

    <?php if ($showChart): ?>
        <div style="position:fixed; left:20px; top:150px; width:300px; z-index:5;">
            <h3 style="color:#fff; margin-bottom:10px;">Kopien pro Schematic</h3>
            <div style="background:#273241; border-radius:12px; padding:20px; box-shadow:0 6px 16px rgba(0,0,0,0.25); height:310px;">
                <canvas id="copyChart"></canvas>
            </div>
        </div>
    <?php endif; ?>

    <div class="actions">
        <form method="post" style="display:flex; gap:10px; align-items:center;">
            <input type="text" name="foldername" placeholder="Neuer Ordnername" class="folder-input">
            <button type="submit" name="create_folder" class="folder-create-btn">+ Ordner erstellen</button>
        </form>
        <button id="upload-btn" class="upload-box">
            <span style="font-size:24px;">+</span>
            <span>Schematic oder Bild hochladen</span>
        </button>
    </div>

    <form method="post" enctype="multipart/form-data" id="upload-form" style="display:none;">
        <input type="file" name="file" id="file-input" accept=".schem,.schematic,.litematic,.jpg,.jpeg,.png,.zip">
    </form>

    <div class="files-grid">
        <?php if (empty($folders) && empty($schematics) && empty($images)): ?>
            <p class="no-files">Dieser Ordner ist leer.</p>
        <?php endif; ?>

        <?php foreach ($folders as $f):
            $data = getItemData($sdb, $currentFolder ?: '', $f, 'folder');
            $previewImg = $data['icon'] && file_exists($basePath . $data['icon']) ? $data['icon'] : null;
        ?>
            <div class="file-card folder" data-type="folder" data-name="<?= htmlspecialchars($f) ?>">
                <div class="preview-container">
                    <?php if ($previewImg): ?>
                        <img src="uploads/<?= $currentFolder ? htmlspecialchars($currentFolder . '/' . $previewImg) : htmlspecialchars($previewImg) ?>" alt="Folder Icon" loading="lazy">
                    <?php else: ?>
                        <div class="folder-icon"></div>
                    <?php endif; ?>
                </div>
                <div class="file-name"><?= htmlspecialchars($f) ?></div>
            </div>
        <?php endforeach; ?>

        <?php foreach ($schematics as $file):
            $data = getItemData($sdb, $currentFolder ?: '', $file, 'schematic');
            $base = pathinfo($file, PATHINFO_FILENAME);

            $previewImg = $data['icon'] ?? null;
            if ($previewImg && !file_exists($basePath . $previewImg)) {
                $previewImg = null;
            }

            if (!$previewImg) {
                foreach ($images as $img) {
                    if (pathinfo($img, PATHINFO_FILENAME) === $base) {
                        $previewImg = $img;
                        break;
                    }
                }
            }
        ?>
            <div class="file-card"
                 data-type="schematic"
                 data-name="<?= htmlspecialchars($file) ?>"
                 data-drive-link="<?= htmlspecialchars($data['drive_link'] ?? '') ?>">
                <div class="preview-container">
                    <?php if ($previewImg): ?>
                        <img src="uploads/<?= $currentFolder ? htmlspecialchars($currentFolder . '/' . $previewImg) : htmlspecialchars($previewImg) ?>" alt="Preview" loading="lazy">
                    <?php else: ?>
                        <div class="schematic-preview"></div>
                    <?php endif; ?>
                    <?php if (empty($data['drive_link'])): ?>
                        <div class="drive-indicator">⚠</div>
                    <?php endif; ?>
                </div>
                <div class="file-name"><?= htmlspecialchars($file) ?></div>
                <?php if ($data['drive_link']): ?>
                    <div class="drive-link-area">
                        <button class="copy-drive-btn"
                                data-link="<?= htmlspecialchars($data['drive_link']) ?>"
                                data-name="<?= htmlspecialchars($file) ?>">
                            Link kopieren (<?= $data['copy_count'] ?>×)
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<div id="password-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:12000; align-items:center; justify-content:center; padding:15px;">
    <div style="background:#181c24; border-radius:16px; width:100%; max-width:420px; padding:35px; box-shadow:0 10px 40px rgba(0,0,0,0.5);">
        <h3 style="color:#38bdf8; text-align:center; margin-bottom:25px;">Passwort ändern</h3>

        <form method="post">
            <input type="password" name="current_password" placeholder="Aktuelles Passwort" required
                   style="width:100%; padding:14px 16px; margin:10px 0; background:#0f1115; border:1px solid #334155; border-radius:8px; color:#e5e7eb; font-size:16px;">

            <input type="password" name="new_password" placeholder="Neues Passwort" required
                   style="width:100%; padding:14px 16px; margin:10px 0; background:#0f1115; border:1px solid #334155; border-radius:8px; color:#e5e7eb; font-size:16px;">

            <input type="password" name="confirm_password" placeholder="Neues Passwort bestätigen" required
                   style="width:100%; padding:14px 16px; margin:10px 0; background:#0f1115; border:1px solid #334155; border-radius:8px; color:#e5e7eb; font-size:16px;">

            <button type="submit" name="change_password"
                    style="width:100%; padding:14px; margin-top:20px; background:#38bdf8; color:#0f172a; border:none; border-radius:8px; font-weight:600; font-size:16px; cursor:pointer;">
                Passwort speichern
            </button>
        </form>

        <div style="text-align:center; margin-top:20px;">
            <button onclick="closePasswordModal()"
                    style="padding:10px 25px; background:#ef4444; color:white; border:none; border-radius:8px; cursor:pointer; font-size:15px;">
                Abbrechen
            </button>
        </div>
    </div>
</div>

<div id="context-menu" class="context-menu">
    <button id="ctx-rename">Umbenennen</button>
    <button id="ctx-icon">Icon auswählen</button>
    <button id="ctx-drive">Drive Link verwalten</button>
    <button id="ctx-delete" class="danger">Löschen</button>
</div>

<div id="icon-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.9); z-index:11000; align-items:center; justify-content:center;">
    <div style="background:#181c24; border-radius:16px; width:90%; max-width:820px; max-height:88vh; overflow:auto; padding:25px;">
        <h3 style="color:#38bdf8; margin-bottom:20px;">Icon auswählen für: <span id="modal-item-name" style="color:#60a5fa;"></span></h3>

        <div id="icon-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(130px, 1fr)); gap:16px;"></div>

        <div style="text-align:center; margin-top:25px;">
            <button onclick="closeIconModal()" style="padding:10px 25px; background:#ef4444; color:white; border:none; border-radius:8px; cursor:pointer; font-size:15px;">
                Schließen
            </button>
        </div>
    </div>
</div>

<script>
window.availableImages = <?php echo json_encode($availableImages); ?>;

document.getElementById('settings-btn').addEventListener('click', () => {
    document.getElementById('password-modal').style.display = 'flex';
});

function closePasswordModal() {
    document.getElementById('password-modal').style.display = 'none';
}

document.querySelectorAll('.file-card.folder').forEach(card => {
    card.addEventListener('dblclick', function () {
        window.location.href = `index.php?folder=${encodeURIComponent(this.dataset.name)}`;
    });
});

const contextMenu = document.getElementById('context-menu');
let selectedItem = null;

document.querySelectorAll('.file-card').forEach(card => {
    card.addEventListener('contextmenu', function (e) {
        e.preventDefault();
        selectedItem = this;

        contextMenu.style.display = 'block';
        contextMenu.style.left = (e.pageX + 10) + 'px';
        contextMenu.style.top = (e.pageY + 10) + 'px';

        const hasDrive = this.dataset.driveLink && this.dataset.driveLink.trim() !== '';
        document.getElementById('ctx-drive').textContent =
            hasDrive ? 'Drive Link ändern / löschen' : 'Drive Link hinzufügen';
    });
});

document.addEventListener('click', () => {
    contextMenu.style.display = 'none';
});

document.getElementById('ctx-icon').addEventListener('click', function () {
    if (!selectedItem) return;

    const name = selectedItem.dataset.name;
    const type = selectedItem.dataset.type;

    document.getElementById('modal-item-name').textContent = name;

    const grid = document.getElementById('icon-grid');
    grid.innerHTML = '';

    window.availableImages.forEach(img => {
        const div = document.createElement('div');
        div.style.textAlign = 'center';
        div.style.cursor = 'pointer';
        div.style.padding = '8px';
        div.style.borderRadius = '10px';
        div.style.transition = '0.2s';

        div.innerHTML = `
            <img src="${img.src}"
                 style="width:100%; height:120px; object-fit:contain; border-radius:8px; border:2px solid #334155;">
            <div style="margin-top:8px; font-size:13px; color:#e5e7eb; word-break:break-all;">${img.name}</div>
        `;

        div.onclick = function () {
            assignIcon(img.name, name, type);
        };

        grid.appendChild(div);
    });

    if (!window.availableImages || window.availableImages.length === 0) {
        grid.innerHTML = '<p style="grid-column:1/-1; text-align:center; color:#64748b; padding:40px;">Keine Bilder vorhanden.</p>';
    }

    document.getElementById('icon-modal').style.display = 'flex';
});

function assignIcon(iconFile, itemName, itemType) {
    const formData = new URLSearchParams();
    formData.append('assign_icon', '1');
    formData.append('item', itemName);
    formData.append('type', itemType);
    formData.append('icon', iconFile);

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    }).then(() => location.reload());
}

function closeIconModal() {
    document.getElementById('icon-modal').style.display = 'none';
}

document.getElementById('ctx-drive').addEventListener('click', function () {
    if (!selectedItem || selectedItem.dataset.type !== 'schematic') return;

    const name = selectedItem.dataset.name;
    const currentLink = selectedItem.dataset.driveLink || "";

    const link = prompt(`Drive-Link für "${name}" (leer = löschen):`, currentLink);

    if (link !== null) {
        const formData = new URLSearchParams();
        formData.append('add_drive_link', '1');
        formData.append('item', name);
        formData.append('drive_link', link.trim());

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        }).then(() => location.reload());
    }

    contextMenu.style.display = 'none';
});

document.getElementById('ctx-rename').addEventListener('click', function () {
    if (!selectedItem) return;

    const oldName = selectedItem.dataset.name;
    const type = selectedItem.dataset.type;

    const newName = prompt("Neuer Name:", oldName.replace(/\.(zip|schem|schematic|litematic)$/i, ''));

    if (newName && newName !== oldName) {
        const formData = new URLSearchParams();
        formData.append('rename', '1');
        formData.append('old_name', oldName);
        formData.append('new_name', newName);
        formData.append('type', type);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        }).then(() => location.reload());
    }

    contextMenu.style.display = 'none';
});

document.getElementById('ctx-delete').addEventListener('click', function () {
    if (!selectedItem) return;

    const name = selectedItem.dataset.name;
    const type = selectedItem.dataset.type;

    if (confirm(`"${name}" wirklich löschen?`)) {
        const formData = new URLSearchParams();
        formData.append('delete', '1');
        formData.append('name', name);
        formData.append('type', type);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        }).then(() => location.reload());
    }

    contextMenu.style.display = 'none';
});

document.querySelectorAll('.copy-drive-btn').forEach(btn => {
    btn.addEventListener('click', function (e) {
        e.stopImmediatePropagation();

        const link = this.dataset.link;
        const name = this.dataset.name;
        navigator.clipboard.writeText(link).then(() => {
            const formData = new URLSearchParams();
            formData.append('copy_link', '1');
            formData.append('item', name);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            });

            this.textContent = 'Kopiert!';
            setTimeout(() => location.reload(), 800);
        });
    });
});

document.getElementById('upload-btn').addEventListener('click', () => {
    document.getElementById('file-input').click();
});

document.getElementById('file-input').addEventListener('change', () => {
    document.getElementById('upload-form').submit();
});

<?php if ($showChart): ?>
const chartData = <?php echo json_encode($chartData); ?>;

const ctx = document.getElementById('copyChart');

if (ctx) {
    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, 'rgba(255, 99, 132, 0.7)');
    gradient.addColorStop(1, 'rgba(255, 99, 132, 0.05)');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.map(item => item.name),
            datasets: [{
                label: 'Kopien',
                data: chartData.map(item => item.copies),
                tension: 0.45,
                fill: true,
                backgroundColor: gradient,
                borderColor: '#ff6b81',
                borderWidth: 3,
                pointRadius: 4,
                pointHoverRadius: 7,
                segment: {
                    borderColor: ctx => '#ff6b81'
                }
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: '#1f2530',
                    titleColor: '#fff',
                    bodyColor: '#60a5fa',
                    borderColor: '#38bdf8',
                    borderWidth: 2,
                    displayColors: false,
                    callbacks: {
                        title: (tooltipItems) => tooltipItems[0].label,
                        label: (context) => context.raw + ' Kopien'
                    }
                }
            },
            scales: {
                x: {
                    ticks: { display: false },
                    grid: { color: 'rgba(255,255,255,0.08)', drawBorder: false }
                },
                y: {
                    ticks: {
                        color: "#aaa",
                        callback: function(value) {
                            return Number.isInteger(value) ? value : '';
                        }
                    },
                    beginAtZero: true,
                    precision: 0,
                    grid: { color: 'rgba(255,255,255,0.08)' }
                }
            }
        }
    });
}
<?php endif; ?>
</script>
</body>
</html>