<?php
/**
 * Sincroniza caminhos de imagens no banco com ficheiros existentes em images/.
 * Uso: GET ou POST /api/sync_assets.php
 * Recomenda-se proteger em produção (ex.: .htaccess ou token em variável de ambiente).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';

header('Content-Type: application/json; charset=utf-8');

$optionalKey = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
$expected = (string) (getenv('SYNC_ASSETS_KEY') ?: '');
if ($expected !== '' && !hash_equals($expected, $optionalKey)) {
    jsonResponse(['error' => 'Chave inválida ou em falta.'], 403);
}

$imagesRoot = projectImagesRoot();
$result = [
    'ok' => true,
    'scanned' => $imagesRoot,
    'updates' => [],
    'files_detected' => [],
];

if (!is_dir($imagesRoot)) {
    jsonResponse(array_merge($result, ['ok' => false, 'error' => 'Pasta images/ não encontrada.']), 404);
}

// Lista ficheiros na raiz de images/ (sem uploads/ recursivo para nomes padrão)
$files = [];
foreach (scandir($imagesRoot) ?: [] as $f) {
    if ($f === '.' || $f === '..') {
        continue;
    }
    $full = $imagesRoot . DIRECTORY_SEPARATOR . $f;
    if (is_file($full)) {
        $result['files_detected'][] = 'images/' . $f;
    }
}

// --- personalizacao (última linha) ---
$stmt = $pdo->query('SELECT id FROM personalizacao ORDER BY id DESC LIMIT 1');
$pRow = $stmt->fetch(PDO::FETCH_ASSOC);

if ($pRow) {
    $pid = (int) $pRow['id'];
    $sets = [];
    $params = [];

    $heroList = discoverHeroImagePaths();
    if ($heroList !== []) {
        $sets[] = 'hero_imagens = ?';
        $params[] = json_encode($heroList, JSON_UNESCAPED_UNICODE);
    }

    foreach (
        [
            'about_imagem' => ['chalet3.png', 'chalet3.jpg', 'about.png', 'about.jpg'],
            'testi1_imagem' => ['testi1.png', 'testi1.jpg'],
            'testi2_imagem' => ['testi2.png', 'testi2.jpg'],
            'testi3_imagem' => ['testi3.png', 'testi3.jpg'],
            'favicon' => ['favicon.ico', 'favicon.png'],
        ] as $col => $names
    ) {
        $rel = firstExistingAssetRelPath($names);
        if ($rel !== '') {
            $sets[] = $col . ' = ?';
            $params[] = $rel;
        }
    }

    if ($sets !== []) {
        $params[] = $pid;
        $sql = 'UPDATE personalizacao SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $pdo->prepare($sql)->execute($params);
        $result['updates']['personalizacao_id'] = $pid;
        $result['updates']['personalizacao_fields'] = array_map(
            static function (string $s): string {
                return trim(explode('=', $s)[0]);
            },
            $sets
        );
    }
} else {
    $result['updates']['personalizacao'] = 'nenhuma linha; execute instalação ou insira personalizacao manualmente.';
}

// --- settings: company_logo ---
foreach (
    [
        'company_logo' => ['logo.png', 'logo.jpg'],
        'company_logo_light' => ['logo_light.png', 'logo-light.png', 'logo_light.jpg'],
    ] as $key => $names
) {
    $rel = firstExistingAssetRelPath($names);
    if ($rel === '') {
        continue;
    }
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $rel]);
    $result['updates']['settings_' . $key] = $rel;
}

// --- chalets: 1º, 2º e 3º por id ---
$stmt = $pdo->query('SELECT id FROM chalets ORDER BY id ASC LIMIT 3');
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
$chaletFiles = [
    1 => ['chalet1.png', 'chalet1.jpg'],
    2 => ['chalet2.png', 'chalet2.jpg'],
    3 => ['chalet3.png', 'chalet3.jpg'],
];
foreach ($ids as $i => $cid) {
    $n = (int) $i + 1;
    if (!isset($chaletFiles[$n])) {
        break;
    }
    $rel = firstExistingAssetRelPath($chaletFiles[$n]);
    if ($rel === '') {
        continue;
    }
    $upd = $pdo->prepare('UPDATE chalets SET main_image = ? WHERE id = ?');
    $upd->execute([$rel, (int) $cid]);
    $result['updates']['chalet_' . $cid . '_main_image'] = $rel;
}

jsonResponse($result);
