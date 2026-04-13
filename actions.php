<?php
/**
 * actions.php — AJAX handler for Jejak Kalori
 * All requests: POST multipart/form-data with `action` field.
 *
 * actions:
 *   analyze      – upload image → FastAPI → store in DB
 *   recalculate  – text name  → FastAPI → update DB row
 *   delete       – remove meal by id
 *   set_goal     – update daily calorie goal
 *   clear        – delete all meals
 */

define('DB_PATH',         __DIR__ . '/data/food_tracker.db');
define('API_ANALYZE',     'http://127.0.0.1:8282/analyze');
define('API_RECALCULATE', 'http://127.0.0.1:8282/recalculate');
define('MAX_IMG_SIDE',    1024);   // px
define('IMG_QUALITY',     70);     // JPEG quality

header('Content-Type: application/json');

// ── helpers ───────────────────────────────────────────────────────────────────

function getDb(): SQLite3 {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $db = new SQLite3(DB_PATH);
    $db->exec("
        CREATE TABLE IF NOT EXISTS meals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            file_name TEXT, food_name TEXT,
            calories INTEGER, protein INTEGER, carbs INTEGER, fat INTEGER,
            ingredients TEXT, image_blob BLOB
        );
        CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);
        INSERT OR IGNORE INTO settings (key,value) VALUES ('daily_calorie_goal','2000');
    ");
    return $db;
}

function ok(array $data = []): never {
    echo json_encode(['ok' => true] + $data);
    exit;
}

function fail(string $msg, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

/**
 * Resize image to max MAX_IMG_SIDE on the longest side and return JPEG bytes.
 */
function compressImage(string $tmpPath, string $mimeType): string|false {
    $src = match ($mimeType) {
        'image/png'  => imagecreatefrompng($tmpPath),
        'image/gif'  => imagecreatefromgif($tmpPath),
        default      => imagecreatefromjpeg($tmpPath),
    };
    if (!$src) return false;

    [$w, $h] = [imagesx($src), imagesy($src)];
    $max = MAX_IMG_SIDE;

    if ($w > $max || $h > $max) {
        $ratio = $w > $h ? $max / $w : $max / $h;
        $nw = (int) round($w * $ratio);
        $nh = (int) round($h * $ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }

    ob_start();
    imagejpeg($src, null, IMG_QUALITY);
    $bytes = ob_get_clean();
    imagedestroy($src);
    return $bytes ?: false;
}

/**
 * POST multipart/form-data to FastAPI /analyze using cURL.
 */
function callAnalyzeApi(string $imageBytes, string $fileName): array {
    $boundary = '----FormBoundary' . bin2hex(random_bytes(8));
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"image\"; filename=\"{$fileName}\"\r\n";
    $body .= "Content-Type: image/jpeg\r\n\r\n";
    $body .= $imageBytes . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $ch = curl_init(API_ANALYZE);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ["Content-Type: multipart/form-data; boundary={$boundary}"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err)          throw new RuntimeException("cURL error: {$err}");
    if ($status === 503) throw new RuntimeException("Gemini API sedang sibuk. Silakan coba lagi beberapa saat.");
    if ($status !== 200) throw new RuntimeException("API returned HTTP {$status}: {$resp}");

    $data = json_decode($resp, true);
    if (!$data) throw new RuntimeException("API returned invalid JSON");
    return $data;
}

/**
 * POST JSON to FastAPI /recalculate.
 */
function callRecalcApi(string $foodName): array {
    $payload = json_encode(['food_name' => $foodName]);

    $ch = curl_init(API_RECALCULATE);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err)           throw new RuntimeException("cURL error: {$err}");
    if ($status === 503) throw new RuntimeException("Gemini API sedang sibuk. Silakan coba lagi beberapa saat.");
    if ($status !== 200) throw new RuntimeException("API returned HTTP {$status}: {$resp}");

    $data = json_decode($resp, true);
    if (!$data) throw new RuntimeException("API returned invalid JSON");
    return $data;
}

// ── router ────────────────────────────────────────────────────────────────────

$action = $_POST['action'] ?? '';

switch ($action) {

    // ── analyze uploaded image ────────────────────────────────────────────────
    case 'analyze': {
        if (empty($_FILES['image'])) {
            $debug = isset($_FILES['image']) ? json_encode($_FILES['image']) : 'not set';
            fail('No image uploaded. FILES: ' . $debug . ' POST: ' . json_encode(array_keys($_POST)));
        }

        $file     = $_FILES['image'];
        $tmpPath  = $file['tmp_name'];
        $origName = basename($file['name']);
        $mime     = $file['type'];

        // If tmp_path is empty, the upload may have failed (size limit, disk space, etc.)
        if ($tmpPath === '' || $tmpPath === null) {
            $err = isset($file['error']) ? $file['error'] : 'unknown';
            $errMap = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload size limit.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            ];
            $msg = $errMap[$err] ?? "Upload failed with error code $err.";
            fail($msg);
        }

        if (!is_uploaded_file($tmpPath)) fail('Upload error: file not a valid upload.');

        // Detect MIME type from file content, not browser (which can be empty/wrong on mobile)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
        }
        if (!str_starts_with($mime, 'image/')) fail('File is not an image (detected type: ' . $mime . ').');

        // compress
        $compressed = compressImage($tmpPath, $mime);
        if ($compressed === false) fail('Failed to process image.');

        // call AI API
        try {
            $data = callAnalyzeApi($compressed, $origName);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }

        // persist
        $db   = getDb();
        $stmt = $db->prepare("
            INSERT INTO meals (file_name,food_name,calories,protein,carbs,fat,ingredients,image_blob)
            VALUES (:fn,:food,:cal,:pro,:carb,:fat,:ing,:img)
        ");
        $stmt->bindValue(':fn',   $origName);
        $stmt->bindValue(':food', $data['nama_makanan']    ?? 'Makanan Tidak Dikenal');
        $stmt->bindValue(':cal',  (int)($data['total_kalori']   ?? 0));
        $stmt->bindValue(':pro',  (int)($data['protein_g']      ?? 0));
        $stmt->bindValue(':carb', (int)($data['karbohidrat_g']  ?? 0));
        $stmt->bindValue(':fat',  (int)($data['lemak_g']        ?? 0));
        $stmt->bindValue(':ing',  json_encode($data['bahan_makanan'] ?? []));
        $stmt->bindValue(':img',  $compressed,                      SQLITE3_BLOB);
        $stmt->execute();
        $mealId = $db->lastInsertRowID();

        ok(['food' => $data['nama_makanan'] ?? '', 'meal_id' => $mealId]);
    }

    // ── recalculate by food name ──────────────────────────────────────────────
    case 'recalculate': {
        $mealId   = (int)($_POST['meal_id']   ?? 0);
        $foodName = trim($_POST['food_name']  ?? '');

        if (!$mealId || !$foodName) fail('Missing meal_id or food_name.');

        try {
            $data = callRecalcApi($foodName);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }

        $db   = getDb();
        $stmt = $db->prepare("
            UPDATE meals
            SET food_name=:food, calories=:cal, protein=:pro,
                carbs=:carb, fat=:fat, ingredients=:ing
            WHERE id=:id
        ");
        $stmt->bindValue(':food', $data['nama_makanan']   ?? $foodName);
        $stmt->bindValue(':cal',  (int)($data['total_kalori']  ?? 0));
        $stmt->bindValue(':pro',  (int)($data['protein_g']     ?? 0));
        $stmt->bindValue(':carb', (int)($data['karbohidrat_g'] ?? 0));
        $stmt->bindValue(':fat',  (int)($data['lemak_g']       ?? 0));
        $stmt->bindValue(':ing',  json_encode($data['bahan_makanan'] ?? []));
        $stmt->bindValue(':id',   $mealId);
        $stmt->execute();

        ok(['food' => $data['nama_makanan'] ?? $foodName]);
    }

    // ── delete one meal ───────────────────────────────────────────────────────
    case 'delete': {
        $mealId = (int)($_POST['meal_id'] ?? 0);
        if (!$mealId) fail('Missing meal_id.');

        $db   = getDb();
        $stmt = $db->prepare('DELETE FROM meals WHERE id=:id');
        $stmt->bindValue(':id', $mealId);
        $stmt->execute();

        ok();
    }

    // ── set daily goal ────────────────────────────────────────────────────────
    case 'set_goal': {
        $goal = (int)($_POST['goal'] ?? 0);
        if ($goal < 500 || $goal > 10000) fail('Goal out of range (500–10000).');

        $db   = getDb();
        $stmt = $db->prepare(
            "INSERT OR REPLACE INTO settings (key,value) VALUES ('daily_calorie_goal',:v)"
        );
        $stmt->bindValue(':v', (string)$goal);
        $stmt->execute();

        ok(['goal' => $goal]);
    }

    // ── get stats for cross-device sync ───────────────────────────────────────
    case 'get_stats': {
        $db = getDb();
        $row = $db->querySingle("SELECT COUNT(*) FROM meals");
        $count = (int)($row ?? 0);
        $row2 = $db->querySingle("SELECT COALESCE(SUM(calories),0) FROM meals");
        $totalCal = (int)($row2 ?? 0);
        ok(['count' => $count, 'total_cal' => $totalCal]);
    }

    // ── remove one ingredient ─────────────────────────────────────────────────
    case 'remove_ingredient': {
        $mealId = (int)($_POST['meal_id'] ?? 0);
        $ingIdx = (int)($_POST['ing_index'] ?? -1);
        if (!$mealId || $ingIdx < 0) fail('Missing meal_id or ing_index.');

        $db = getDb();
        $stmt = $db->prepare("SELECT ingredients, calories, protein, carbs, fat FROM meals WHERE id=:id");
        $stmt->bindValue(':id', $mealId);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if (!$row) fail('Meal not found.');

        $oldCal  = (int)$row['calories'];
        $oldProt = (int)$row['protein'];
        $oldCarb = (int)$row['carbs'];
        $oldFat  = (int)$row['fat'];

        $ingredients = json_decode($row['ingredients'], true) ?: [];
        if ($ingIdx >= count($ingredients)) fail('Ingredient index out of range.');

        // Subtract the removed ingredient's calories from totals
        $removed = $ingredients[$ingIdx];
        $removedCal = 0;
        if (is_array($removed) && isset($removed['kalori'])) {
            $removedCal = (int)$removed['kalori'];
        }
        array_splice($ingredients, $ingIdx, 1);

        // Recalculate totals from remaining structured ingredients
        $newCal = 0;
        foreach ($ingredients as $item) {
            if (is_array($item) && isset($item['kalori'])) {
                $newCal += (int)$item['kalori'];
            }
        }
        // If new total is zero (legacy data), fall back to subtraction
        if ($newCal === 0 && $removedCal > 0) {
            $newCal = max(0, $oldCal - $removedCal);
        }

        // Scale macros proportionally
        if ($oldCal > 0) {
            $ratio = $newCal / $oldCal;
            $newProt = max(0, (int)round($oldProt * $ratio));
            $newCarb = max(0, (int)round($oldCarb * $ratio));
            $newFat  = max(0, (int)round($oldFat  * $ratio));
        } else {
            $newProt = $oldProt;
            $newCarb = $oldCarb;
            $newFat  = $oldFat;
        }

        $stmt = $db->prepare("UPDATE meals SET ingredients=:ing, calories=:cal, protein=:pro, carbs=:carb, fat=:fat WHERE id=:id");
        $stmt->bindValue(':ing', json_encode($ingredients));
        $stmt->bindValue(':cal', $newCal);
        $stmt->bindValue(':pro', $newProt);
        $stmt->bindValue(':carb', $newCarb);
        $stmt->bindValue(':fat', $newFat);
        $stmt->bindValue(':id', $mealId);
        $stmt->execute();

        ok(['new_calories' => $newCal, 'new_protein' => $newProt, 'new_carbs' => $newCarb, 'new_fat' => $newFat]);
    }

    // ── clear all meals ───────────────────────────────────────────────────────
    case 'clear': {
        $db = getDb();
        $db->exec('DELETE FROM meals');
        ok();
    }

    default:
        fail('Unknown action.', 400);
}
