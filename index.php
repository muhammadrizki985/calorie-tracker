<?php
// ─── DB helpers ───────────────────────────────────────────────────────────────
define('DB_PATH', __DIR__ . '/data/food_tracker.db');
define('API_ANALYZE',     'http://127.0.0.1:8282/analyze');
define('API_RECALCULATE', 'http://127.0.0.1:8282/recalculate');

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

function getDailyGoal(): int {
    $db = getDb();
    $row = $db->querySingle("SELECT value FROM settings WHERE key='daily_calorie_goal'");
    return $row ? (int)$row : 2000;
}

function getTodayMeals(): array {
    $db = getDb();
    $res = $db->query("
        SELECT id,file_name,food_name,calories,protein,carbs,fat,ingredients,image_blob
        FROM meals ORDER BY timestamp DESC
    ");
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    return $rows;
}

function getAllMeals(): array {
    $db = getDb();
    $res = $db->query("
        SELECT id,timestamp,file_name,food_name,calories,protein,carbs,fat,ingredients,image_blob
        FROM meals ORDER BY timestamp DESC
    ");
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    return $rows;
}

session_start();

$dailyGoal  = getDailyGoal();
$todayMeals = getTodayMeals();
$allMeals   = getAllMeals();

$todayCals    = array_sum(array_column($todayMeals, 'calories'));
$todayProtein = array_sum(array_column($todayMeals, 'protein'));
$todayCarbs   = array_sum(array_column($todayMeals, 'carbs'));
$todayFat     = array_sum(array_column($todayMeals, 'fat'));
$remaining    = $dailyGoal - $todayCals;
$pct          = min(100, round($todayCals / max($dailyGoal, 1) * 100));

// Flash messages
$flash = $_SESSION['flash'] ?? null;
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Jejak Kalori 🍱</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;1,400&family=Nunito:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --cream:    #fdf6ee;
    --parchment:#f5e9d4;
    --terracotta:#c9622f;
    --terra-light:#e8845a;
    --terra-pale:#fce8da;
    --olive:    #4a5240;
    --ink:      #2b2318;
    --muted:    #8a7a6a;
    --white:    #ffffff;
    --card-bg:  #fffaf4;
    --border:   #e8d9c4;
    --shadow:   rgba(43,35,24,.08);
    --red:      #d94f3d;
    --green:    #5a7a50;
    --radius:   14px;
    --radius-sm:8px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Nunito', sans-serif;
    background: var(--cream);
    color: var(--ink);
    min-height: 100vh;

    /* Tambahkan baris di bawah ini untuk menonaktifkan seleksi teks secara global */
    user-select: none;
    -webkit-user-select: none; /* Untuk Chrome/Safari/Android */
    -webkit-tap-highlight-color: transparent; /* Menghilangkan kotak biru saat klik di mobile */
}

/* Pengecualian untuk elemen input agar tetap bisa diketik/diedit */
input, textarea {
    user-select: text;
    -webkit-user-select: text;
}

/* ── layout ── */
.wrapper { max-width: 720px; margin: 0 auto; padding: 0 1rem 4rem; }

/* ── header ── */
.site-header {
    background: var(--olive);
    color: var(--cream);
    padding: 1.5rem 1rem 1.4rem;
    text-align: center;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 2px 12px rgba(0,0,0,.18);
}
.site-header:active {
    background-color: color-mix(in srgb, var(--olive), black 7%);
    transform: translateY(1px);
}
.site-header h1 {
    font-family: 'Lora', serif;
    font-size: 1.65rem;
    letter-spacing: .02em;
}
.site-header p {
    font-size: .82rem;
    opacity: .7;
    margin-top: .25rem;
    font-style: italic;
}

/* ── section ── */
.section { margin-top: 2rem; }

.section-title {
    font-family: 'Lora', serif;
    font-size: 1.15rem;
    color: var(--olive);
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: 1rem;
    padding-bottom: .5rem;
    border-bottom: 2px solid var(--border);
}

/* ── card ── */
.card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.25rem 1.4rem;
    box-shadow: 0 2px 8px var(--shadow);
}

/* ── upload zone ── */
.upload-zone {
    display: block;
    border: 2px dashed var(--terracotta);
    border-radius: var(--radius);
    padding: 2.2rem 1.5rem;
    text-align: center;
    background: var(--terra-pale);
    transition: background .2s, border-color .2s;
    cursor: pointer;
    position: relative;
}
.upload-zone:hover, .upload-zone.drag { background: #fcd6c0; border-color: var(--terra-light); }
.upload-zone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.upload-zone .icon { font-size: 2.5rem; display: block; margin-bottom: .6rem; }
.upload-zone .label { font-size: .95rem; color: var(--terracotta); font-weight: 600; display: block; }
.upload-zone .sub   { font-size: .78rem; color: var(--muted); margin-top: .3rem; display: block; }

/* preview strip */
#preview-strip {
    display: flex; flex-wrap: wrap; gap: .5rem;
    margin-top: .8rem; min-height: 0;
}
.preview-thumb {
    width: 72px; height: 72px; border-radius: var(--radius-sm);
    object-fit: cover; border: 2px solid var(--border);
}

/* info tip */
.tip {
    background: var(--parchment);
    border-left: 3px solid var(--terracotta);
    padding: .65rem 1rem;
    border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
    font-size: .82rem;
    color: var(--muted);
    margin-top: .8rem;
}

/* ── submit btn ── */
.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
    padding: .65rem 1.4rem;
    border-radius: var(--radius-sm);
    font-family: 'Nunito', sans-serif;
    font-size: .9rem; font-weight: 700;
    cursor: pointer; border: none;
    transition: opacity .15s, transform .1s;
}
.btn:active { transform: scale(.97); }
.btn-primary { background: var(--terracotta); color: var(--white); width: 100%; }
.btn-primary:hover { opacity: .88; }
.btn-secondary { background: var(--parchment); color: var(--olive); border: 1px solid var(--border); }
.btn-secondary:hover { background: var(--border); }
.btn-danger  { background: #fde8e8; color: var(--red); border: 1px solid #f5c2c2; }
.btn-danger:hover { background: #fad2d2; }
.btn-sm { padding: .45rem .9rem; font-size: .82rem; }
.btn:disabled { opacity: .45; pointer-events: none; }
.btn-full { width: 100%; }

/* ── progress ── */
.prog-wrap { margin: .8rem 0; }
.prog-label { display: flex; justify-content: space-between; font-size: .8rem; color: var(--muted); margin-bottom: .3rem; }
.prog-bar-bg {
    height: 14px; border-radius: 99px;
    background: var(--parchment);
    overflow: hidden;
    border: 1px solid var(--border);
}
.prog-bar-fill {
    height: 100%; border-radius: 99px;
    background: linear-gradient(90deg, var(--terracotta), var(--terra-light));
    transition: width .6s cubic-bezier(.4,0,.2,1);
}
.prog-bar-fill.over { background: linear-gradient(90deg,#d94f3d,#f07060); }

/* ── macro grid ── */
.macro-grid {
    display: grid; grid-template-columns: repeat(4,1fr); gap: .7rem;
    margin-top: 1rem;
}
@media(max-width:480px) { .macro-grid { grid-template-columns: repeat(2,1fr); } }
.macro-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: .8rem .6rem;
    text-align: center;
}
.macro-card .val {
    font-size: 1.25rem; font-weight: 700;
    font-family: 'Lora', serif;
    color: var(--terracotta);
}
.macro-card .lbl { font-size: .72rem; color: var(--muted); margin-top: .2rem; }

/* remaining badge */
.remaining {
    text-align: center; margin-top: .8rem;
    font-size: .9rem; font-weight: 600;
    padding: .55rem 1rem;
    border-radius: var(--radius-sm);
}
.remaining.ok  { background: #eaf4e5; color: var(--green); }
.remaining.over{ background: #fde8e5; color: var(--red); }

/* ── goal input ── */
.goal-row { display: flex; align-items: center; gap: .8rem; flex-wrap: wrap; }
.input-field {
    padding: .55rem .85rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'Nunito', sans-serif;
    font-size: .9rem;
    color: var(--ink);
    background: var(--white);
    width: 130px;
    transition: border-color .2s;
}
.input-field:focus { outline: none; border-color: var(--terracotta); }

/* ── meal card ── */
.meal-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 1rem;
    box-shadow: 0 2px 8px var(--shadow);
    transition: box-shadow .2s;
    scroll-margin-top: 100px;  /* offset for sticky header when auto-scrolling */
}
.meal-card:hover { box-shadow: 0 4px 16px var(--shadow); }

.meal-header {
    display: flex; 
    align-items: center; /* Keeps the chevron perfectly centered vertically */
    justify-content: space-between;
    padding: 0.85rem 1.1rem;
    background: var(--parchment);
    cursor: pointer; 
    user-select: none; 
    gap: 1rem;
}

.meal-info {
    display: flex; 
    flex-direction: column; /* Stacks title and time on top of each other */
    gap: 0.2rem;
    flex: 1; 
    min-width: 0; /* Crucial for allowing long text to wrap inside flexbox */
}

.meal-info .title {
    font-family: 'Lora', serif; 
    font-weight: 600; 
    font-size: 1rem;
    word-break: break-word; 
    line-height: 1.3;
}

.meal-info .time { 
    font-size: 0.75rem; 
    color: var(--muted); 
}


.meal-header .chevron { font-size: .75rem; color: var(--muted); transition: transform .2s; }
.meal-header.open .chevron { transform: rotate(180deg); }

.meal-body { display: none; padding: 1rem 1.1rem 1.2rem; }
.meal-body.open { display: block; }

.meal-inner { display: flex; gap: 1rem; align-items: flex-start; }
@media(max-width:480px){ .meal-inner { flex-direction: column; } }

.meal-img {
    width: 110px; height: 110px; object-fit: cover;
    border-radius: var(--radius-sm); border: 1px solid var(--border);
    flex-shrink: 0;
}
.meal-img-placeholder {
    width: 110px; height: 110px; background: var(--parchment);
    border-radius: var(--radius-sm); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; flex-shrink: 0;
}

.meal-stats { flex: 1; }
.meal-macro-row {
    display: grid; grid-template-columns: repeat(4,1fr); gap: .4rem;
    margin-bottom: .7rem;
}
.meal-macro-pill {
    background: var(--parchment);
    border-radius: var(--radius-sm);
    padding: .35rem .4rem;
    text-align: center;
}
.meal-macro-pill .v { font-size: .9rem; font-weight: 700; color: var(--terracotta); }
.meal-macro-pill .l { font-size: .65rem; color: var(--muted); }

.ingredients-line { font-size: .78rem; color: var(--muted); margin-bottom: .8rem; }
.ingredients-line strong { color: var(--olive); }

.ingredient-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: .8rem;
    font-size: .78rem;
}
.ingredient-table th {
    text-align: left;
    color: var(--olive);
    font-weight: 700;
    padding: .25rem .4rem;
    border-bottom: 1px solid var(--border);
}
.ingredient-table td {
    color: var(--muted);
    padding: .25rem .4rem;
}
.ingredient-table td:last-child {
    text-align: right;
}
.ingredient-table th:last-child {
    text-align: right;
}
.ingredient-table tr + tr {
    border-top: 1px dotted var(--border);
}

.edit-row { display: block; margin-bottom: .7rem; }
.edit-label { font-size: .84rem; color: var(--muted); margin-bottom: .3rem; display: block; font-weight: 700; }
.edit-row input {
    width: 100%; box-sizing: border-box;
    padding: .45rem .7rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'Nunito', sans-serif;
    font-size: .84rem;
    background: var(--white);
}
.edit-row input:focus { outline: none; border-color: var(--terracotta); }

.action-row { display: flex; gap: .5rem; flex-wrap: wrap; }

.action-row .btn-re {
    flex: 1;
}

.action-row .btn-del {
    flex: 0;
    min-width: 42px;
}

/* ── flash / toast ── */
.toast {
    position: fixed; bottom: 1.5rem; left: 50%; transform: translateX(-50%);
    background: var(--olive); color: var(--cream);
    padding: .7rem 1.5rem; border-radius: 99px;
    font-size: .85rem; font-weight: 600;
    box-shadow: 0 4px 16px rgba(0,0,0,.2);
    z-index: 999; white-space: nowrap;
    animation: slideUp .3s ease, fadeOut .4s ease 2.5s forwards;
}
@keyframes slideUp   { from { opacity:0; transform: translateX(-50%) translateY(12px) } to { opacity:1; transform: translateX(-50%) translateY(0) } }
@keyframes fadeOut   { to   { opacity:0; transform: translateX(-50%) translateY(8px) } }

/* ── spinner overlay ── */
#spinner-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(43,35,24,.35);
    z-index: 500;
    align-items: center; justify-content: center;
    flex-direction: column; gap: 1rem;
}
#spinner-overlay.show { display: flex; }
.spinner {
    width: 48px; height: 48px;
    border: 4px solid rgba(255,255,255,.3);
    border-top-color: var(--cream);
    border-radius: 50%;
    animation: spin .8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg) } }
#spinner-overlay p { color: var(--cream); font-weight: 600; font-size: .95rem; text-align: center; padding: 0 1.5rem; }

/* ── empty state ── */
.empty { text-align: center; padding: 2.5rem 1rem; color: var(--muted); }
.empty .icon { font-size: 3rem; display: block; margin-bottom: .5rem; }

/* ── divider ── */
hr { border: none; border-top: 1px solid var(--border); margin: 2rem 0; }

/* ── danger zone ── */
.danger-zone { text-align: center; }

/* Mobile Optimization - Place at the end of styles */
@media(max-width: 600px) {
    .meal-inner { 
        flex-direction: column; 
    }
    .meal-img, .meal-img-placeholder {
        width: 100% !important; 
        height: 180px !important; 
    }
    .meal-stats { 
        width: 100% !important; 
    }
}
</style>
</head>
<body>

<header class="site-header">
    <h1>🍱 Jejak Kalori</h1>
    <p>Upload foto, dapatkan estimasi kalori &amp; makro dalam hitungan detik.</p>
</header>

<!-- spinner overlay -->
<div id="spinner-overlay">
    <div class="spinner"></div>
    <p id="spinner-text">Menganalisis makanan Anda...</p>
</div>

<div class="wrapper">

    <!-- ── UPLOAD ─────────────────────────────────── -->
    <div class="section">
        <div class="section-title">Tambah Makanan</div>
        <form id="upload-form" enctype="multipart/form-data">
            <label class="upload-zone" id="drop-zone">
                <span class="icon">📂</span>
                <span class="label">Pilih atau seret gambar makanan</span>
                <span class="sub">JPG, JPEG, PNG — satu gambar per-analisis</span>
                <input type="file" name="images[]" id="file-input" accept="image/; capture=camera*">
            </label>
            <div id="preview-strip"></div>
            <div class="tip">💡 <strong>Tips:</strong> Sertakan sendok atau garpu di foto untuk estimasi porsi yang lebih akurat.</div>
            <div style="margin-top:1rem">
                <button type="submit" class="btn btn-primary" id="analyze-btn" disabled>
                    <span>🔍 Analisis &amp; Simpan Makanan</span>
                </button>
            </div>
        </form>
    </div>

    <!-- ── DAILY GOAL ──────────────────────────────── -->
    <div class="section">
        <div class="section-title">Target Harian</div>
        <div class="card">
            <form id="goal-form" class="goal-row">
                <label for="goal-input" style="font-size:.88rem;color:var(--muted)">Target Kalori:</label>
                <input type="number" id="goal-input" class="input-field"
                       min="500" max="10000" step="50" value="<?= $dailyGoal ?>">
                <button type="submit" class="btn btn-secondary btn-sm" style="width:100%">Simpan</button>
            </form>

            <div class="prog-wrap" style="margin-top:1.2rem">
                <div class="prog-label">
                    <span><?= $todayCals ?> kkal dikonsumsi</span>
                    <span>Target: <?= $dailyGoal ?> kkal</span>
                </div>
                <div class="prog-bar-bg">
                    <div class="prog-bar-fill <?= $pct >= 100 ? 'over' : '' ?>"
                         style="width:<?= $pct ?>%"></div>
                </div>
            </div>

            <div class="macro-grid">
                <div class="macro-card">
                    <div class="val"><?= $todayCals ?></div>
                    <div class="lbl">🔥 kkal</div>
                </div>
                <div class="macro-card">
                    <div class="val"><?= $todayProtein ?>g</div>
                    <div class="lbl">🍗 Protein</div>
                </div>
                <div class="macro-card">
                    <div class="val"><?= $todayCarbs ?>g</div>
                    <div class="lbl">🍚 Karbo</div>
                </div>
                <div class="macro-card">
                    <div class="val"><?= $todayFat ?>g</div>
                    <div class="lbl">🥑 Lemak</div>
                </div>
            </div>

            <div class="remaining <?= $remaining >= 0 ? 'ok' : 'over' ?>">
                <?php if ($remaining >= 0): ?>
                    ✅ Sisa hari ini: <strong><?= $remaining ?> kkal</strong>
                <?php else: ?>
                    ⚠️ Melebihi target: <strong><?= abs($remaining) ?> kkal</strong>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── MEAL HISTORY ────────────────────────────── -->
    <div class="section">
        <div class="section-title">Catatan Makan</div>

        <?php if (empty($allMeals)): ?>
            <div class="empty">
                <span class="icon">🥢</span>
                Belum ada makanan yang tersimpan.<br>
                <small>Unggah beberapa gambar di atas untuk memulai!</small>
            </div>
        <?php else: ?>
            <?php foreach ($allMeals as $meal): ?>
                <?php
                $ing  = json_decode($meal['ingredients'], true) ?: [];
                $dt   = new DateTime($meal['timestamp']);
                $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
                $time = $dt->format('H:i');
                $imgSrc = $meal['image_blob']
                    ? 'data:image/jpeg;base64,' . base64_encode($meal['image_blob'])
                    : null;
                ?>
                <div class="meal-card" id="meal-<?= $meal['id'] ?>">
                    <div class="meal-header" onclick="toggleMeal(<?= $meal['id'] ?>)" id="hdr-<?= $meal['id'] ?>">
                        <div class="meal-info">
                            <span class="title"><?= htmlspecialchars($meal['food_name']) ?></span>
                            <span class="time"><?= $time ?> · <?= $meal['calories'] ?> kkal</span>
                        </div>
                        <span class="chevron">▼</span>
                    </div>
                    <div class="meal-body open" id="body-<?= $meal['id'] ?>">
                        <div class="meal-inner">
                            <?php if ($imgSrc): ?>
                                <img class="meal-img" src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($meal['food_name']) ?>">
                            <?php else: ?>
                                <div class="meal-img-placeholder">🍽️</div>
                            <?php endif; ?>

                            <div class="meal-stats">
                                <div class="meal-macro-row">
                                    <div class="meal-macro-pill"><div class="v"><?= $meal['calories'] ?></div><div class="l">kkal</div></div>
                                    <div class="meal-macro-pill"><div class="v"><?= $meal['protein'] ?>g</div><div class="l">Protein</div></div>
                                    <div class="meal-macro-pill"><div class="v"><?= $meal['carbs'] ?>g</div><div class="l">Karbo</div></div>
                                    <div class="meal-macro-pill"><div class="v"><?= $meal['fat'] ?>g</div><div class="l">Lemak</div></div>
                                </div>

                                <?php if ($ing): ?>
                                <table class="ingredient-table">
                                    <tr><th>Bahan</th><th>Berat (g)</th><th>Kalori (kkal)</th></tr>
                                    <?php
                                    // Support both old string format and new object format
                                    $items = [];
                                    foreach ($ing as $item) {
                                        if (is_array($item) && isset($item['nama'])) {
                                            $items[] = [
                                                'nama'   => htmlspecialchars($item['nama']),
                                                'berat'  => $item['berat_g'],
                                                'kalori' => $item['kalori'],
                                                'sort'   => (int)($item['kalori'] ?? 0),
                                            ];
                                        } else {
                                            // Fallback for legacy entries
                                            $items[] = [
                                                'nama'   => htmlspecialchars((string)$item),
                                                'berat'  => '—',
                                                'kalori' => '—',
                                                'sort'   => 0,
                                            ];
                                        }
                                    }
                                    // Sort by calories descending
                                    usort($items, fn($a, $b) => $b['sort'] <=> $a['sort']);
                                    foreach ($items as $row): ?>
                                    <tr>
                                        <td><?= $row['nama'] ?></td>
                                        <td><?= $row['berat'] ?></td>
                                        <td><?= $row['kalori'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                                <?php endif; ?>

                                <div class="edit-row">
                                    <div class="edit-label">Koreksi nama makanan:</div>
                                    <input type="text" id="name-<?= $meal['id'] ?>"
                                           value="<?= htmlspecialchars($meal['food_name']) ?>"
                                           placeholder="Koreksi nama makanan...">
                                </div>
                                <div class="action-row">
                                    <button class="btn btn-danger btn-sm btn-del"
                                            onclick="deleteMeal(<?= $meal['id'] ?>)">
                                        ✕
                                    </button>
                                    <button class="btn btn-secondary btn-sm btn-re"
                                            onclick="recalcMeal(<?= $meal['id'] ?>)">
                                        Hitung Ulang
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <hr>

    <!-- ── DANGER ZONE ──────────────────────────────── -->
    <div class="danger-zone">
        <button class="btn btn-danger" onclick="clearAll()">
            🗑️ Hapus Semua Catatan Makan
        </button>
    </div>

</div><!-- /wrapper -->

<?php if ($flash): ?>
<div class="toast" id="toast"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<script>

// ── Auto-scroll to newly added meal on page load ─────────────
(function scrollToNewMeal() {
    const mealId = sessionStorage.getItem('scrollToMeal');
    if (!mealId) return;

    sessionStorage.removeItem('scrollToMeal');

    // Wait for DOM to be ready
    requestAnimationFrame(() => {
        const card = document.getElementById('meal-' + mealId);
        if (!card) return;

        // Ensure the meal body is expanded
        const body = document.getElementById('body-' + mealId);
        const hdr  = document.getElementById('hdr-' + mealId);
        if (body && !body.classList.contains('open')) {
            body.classList.add('open');
            hdr?.classList.add('open');
        }

        // Scroll to the card with smooth behavior
        setTimeout(() => {
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // Add a brief highlight effect
            card.style.transition = 'box-shadow 0.6s ease';
            card.style.boxShadow  = '0 0 0 3px var(--terracotta), 0 4px 16px var(--shadow)';
            setTimeout(() => {
                card.style.boxShadow = '';
            }, 1800);
        }, 400);
    });
})();

// Klik header untuk kembali ke atas
document.querySelector('.site-header').addEventListener('click', () => {
    window.scrollTo({
        top: 0,
        behavior: 'smooth' // Efek scroll halus
    });
});

// ── file picker + preview ──────────────────────────────────────
const fileInput   = document.getElementById('file-input');
const previewStrip= document.getElementById('preview-strip');
const analyzeBtn  = document.getElementById('analyze-btn');
const dropZone    = document.getElementById('drop-zone');

fileInput.addEventListener('change', showPreviews);
['dragover','dragenter'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('drag'); }));
['dragleave','drop'].forEach(e => dropZone.addEventListener(e, ev => { dropZone.classList.remove('drag'); }));
dropZone.addEventListener('drop', ev => { ev.preventDefault(); fileInput.files = ev.dataTransfer.files; showPreviews(); });

function showPreviews() {
    previewStrip.innerHTML = '';
    analyzeBtn.disabled = fileInput.files.length === 0;
    Array.from(fileInput.files).forEach(f => {
        const img = document.createElement('img');
        img.className = 'preview-thumb';
        img.src = URL.createObjectURL(f);
        previewStrip.appendChild(img);
    });
}

// ── upload & analyze ──────────────────────────────────────────
document.getElementById('upload-form').addEventListener('submit', async e => {
    e.preventDefault();
    if (!fileInput.files.length) return;

    // Pause auto-sync polling so PHP isn't blocked by a concurrent poll
    if (window._syncPause) window._syncPause.stop();

    showSpinner('Menganalisis makanan Anda...');
    const files = Array.from(fileInput.files);
    const newMealIds = [];
    const startTime = Date.now();

    // Update spinner text with elapsed time
    const updateTimer = setInterval(() => {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        setSpinnerText(`Menganalisis makanan... (${elapsed}s)`);
    }, 1000);

    for (const file of files) {
        const fd = new FormData();
        fd.append('action', 'analyze');
        fd.append('image', file, file.name);
        try {
            const res = await fetch('actions.php', { method: 'POST', body: fd });
            const text = await res.text();
            let data;
            try { data = JSON.parse(text); } catch {
                console.error('Non-JSON response:', text.slice(0, 200));
                showToast('❌ Gagal: server mengembalikan response yang tidak valid.');
                clearInterval(updateTimer);
                hideSpinner();
                if (window._syncPause) window._syncPause.start();
                return;
            }
            if (data.ok && data.meal_id) {
                newMealIds.push(data.meal_id);
            } else {
                console.error('Analyze error:', data.error);
                showToast('❌ Gagal: ' + (data.error || 'Unknown error'));
                clearInterval(updateTimer);
                hideSpinner();
                if (window._syncPause) window._syncPause.start();
                return;
            }
        } catch (err) {
            console.error('Fetch error:', err.message);
            showToast('❌ Gagal: tidak bisa terhubung ke server. Periksa koneksi internet.');
            clearInterval(updateTimer);
            hideSpinner();
            if (window._syncPause) window._syncPause.start();
            return;
        }
    }

    clearInterval(updateTimer);

    // Resume auto-sync before reload
    if (window._syncPause) window._syncPause.start();

    // Store new meal IDs so we can scroll to them after reload
    if (newMealIds.length > 0) {
        sessionStorage.setItem('scrollToMeal', newMealIds[0]);
    }

    hideSpinner();
    location.reload();
});

// ── daily goal ────────────────────────────────────────────────
document.getElementById('goal-form').addEventListener('submit', async e => {
    e.preventDefault();
    const goal = document.getElementById('goal-input').value;
    const fd = new FormData();
    fd.append('action', 'set_goal');
    fd.append('goal', goal);
    await fetch('actions.php', { method: 'POST', body: fd });
    location.reload();
});

// ── toggle meal card ──────────────────────────────────────────
function toggleMeal(id) {
    const hdr  = document.getElementById('hdr-'  + id);
    const body = document.getElementById('body-' + id);
    hdr.classList.toggle('open');
    body.classList.toggle('open');
}

// ── recalculate ───────────────────────────────────────────────
async function recalcMeal(id) {
    const name = document.getElementById('name-' + id).value.trim();
    if (!name) return;

    const startTime = Date.now();
    showSpinner('Menghitung ulang ' + name + '... (0s)');
    const updateTimer = setInterval(() => {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        setSpinnerText('Menghitung ulang ' + name + `... (${elapsed}s)`);
    }, 1000);

    const fd = new FormData();
    fd.append('action', 'recalculate');
    fd.append('meal_id', id);
    fd.append('food_name', name);
    try {
        const res = await fetch('actions.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) { location.reload(); }
        else { clearInterval(updateTimer); hideSpinner(); showToast('Gagal: ' + (data.error || 'Unknown error')); }
    } catch (err) { clearInterval(updateTimer); hideSpinner(); showToast('Terjadi kesalahan jaringan.'); }
}

// ── delete ────────────────────────────────────────────────────
async function deleteMeal(id) {
    if (!confirm('Hapus makanan ini?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('meal_id', id);
    await fetch('actions.php', { method: 'POST', body: fd });
    document.getElementById('meal-' + id).remove();
    location.reload();
}

// ── clear all ────────────────────────────────────────────────
async function clearAll() {
    if (!confirm('Hapus SEMUA catatan makan? Tindakan ini tidak dapat dibatalkan.')) return;
    const fd = new FormData();
    fd.append('action', 'clear');
    await fetch('actions.php', { method: 'POST', body: fd });
    location.reload();
}

// ── spinner ──────────────────────────────────────────────────
const overlay = document.getElementById('spinner-overlay');
function showSpinner(msg) { document.getElementById('spinner-text').textContent = msg || ''; overlay.classList.add('show'); document.body.style.overflow = 'hidden'; }
function setSpinnerText(msg) { document.getElementById('spinner-text').textContent = msg; }
function hideSpinner() { overlay.classList.remove('show'); document.body.style.overflow = ''; }

// ── toast ────────────────────────────────────────────────────
function showToast(msg) {
    const t = document.createElement('div');
    t.className = 'toast'; t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3200);
}

// ── cross-device auto-sync via polling ───────────────────────
(function autoSync() {
    // Read initial stats from PHP-rendered values
    const mealCards = document.querySelectorAll('.meal-card');
    let lastMealCount = mealCards.length;
    let lastTotalCal  = <?= $todayCals ?>;

    const POLL_INTERVAL = 5000; // 5 seconds
    let syncTimer = null;

    function startPolling() {
        if (syncTimer) return;
        syncTimer = setInterval(checkForUpdates, POLL_INTERVAL);
    }

    function stopPolling() {
        if (syncTimer) { clearInterval(syncTimer); syncTimer = null; }
    }

    async function checkForUpdates() {
        try {
            const fd = new FormData();
            fd.append('action', 'get_stats');
            const res  = await fetch('actions.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (!json.ok) return;

            const serverCount = json.count;
            const serverCal   = json.total_cal;

            if (serverCount !== lastMealCount || serverCal !== lastTotalCal) {
                console.log('[sync] change detected', lastMealCount, '→', serverCount);
                stopPolling();
                showToast('🔄 Data diperbarui dari perangkat lain...');
                setTimeout(() => location.reload(), 600);
            }
        } catch (e) { /* ignore */ }
    }

    // Start polling immediately
    startPolling();

    // Also check when tab regains focus
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            checkForUpdates();
            startPolling();
        } else {
            stopPolling();
        }
    });

    // Expose for manual debugging: window._syncCheck()
    window._syncCheck = checkForUpdates;

    // Expose for pausing during heavy operations (like upload)
    window._syncPause = { stop: stopPolling, start: startPolling };
})();
</script>
</body>
</html>
