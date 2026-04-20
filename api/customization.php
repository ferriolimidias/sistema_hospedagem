<?php
/**
 * API para Personalização do site (Admin > Personalização).
 * Usa a tabela personalizacao com colunas separadas.
 */
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];

// Converte linha da tabela personalizacao para formato esperado pelo frontend
function rowToCustomization($row) {
    if (!$row) return [];
    $heroImgs = !empty($row['hero_imagens']) ? json_decode($row['hero_imagens'], true) : ['images/hero.png'];
    if (!is_array($heroImgs)) $heroImgs = ['images/hero.png'];
    return [
        'heroTitle' => $row['hero_titulo'] ?? '',
        'heroSubtitle' => $row['hero_subtitulo'] ?? '',
        'heroImages' => $heroImgs,
        'aboutTitle' => $row['about_titulo'] ?? '',
        'aboutText' => $row['about_texto'] ?? '',
        'aboutImage' => $row['about_imagem'] ?? '',
        'chaletsSubtitle' => $row['chalets_subtitulo'] ?? '',
        'chaletsTitle' => $row['chalets_titulo'] ?? '',
        'chaletsDesc' => $row['chalets_desc'] ?? '',
        'feat1Title' => $row['feat1_titulo'] ?? '',
        'feat1Desc' => $row['feat1_desc'] ?? '',
        'feat2Title' => $row['feat2_titulo'] ?? '',
        'feat2Desc' => $row['feat2_desc'] ?? '',
        'feat3Title' => $row['feat3_titulo'] ?? '',
        'feat3Desc' => $row['feat3_desc'] ?? '',
        'feat4Title' => $row['feat4_titulo'] ?? '',
        'feat4Desc' => $row['feat4_desc'] ?? '',
        'feat5Title' => $row['feat5_titulo'] ?? '',
        'feat5Desc' => $row['feat5_desc'] ?? '',
        'testi1Name' => $row['testi1_nome'] ?? '',
        'testi1Location' => $row['testi1_local'] ?? '',
        'testi1Text' => $row['testi1_texto'] ?? '',
        'testi1Image' => $row['testi1_imagem'] ?? '',
        'testi2Name' => $row['testi2_nome'] ?? '',
        'testi2Location' => $row['testi2_local'] ?? '',
        'testi2Text' => $row['testi2_texto'] ?? '',
        'testi2Image' => $row['testi2_imagem'] ?? '',
        'testi3Name' => $row['testi3_nome'] ?? '',
        'testi3Location' => $row['testi3_local'] ?? '',
        'testi3Text' => $row['testi3_texto'] ?? '',
        'testi3Image' => $row['testi3_imagem'] ?? '',
        'locAddress' => $row['loc_endereco'] ?? '',
        'locCar' => $row['loc_carro'] ?? '',
        'locMapLink' => $row['loc_map_link'] ?? '',
        'waNumber' => $row['wa_numero'] ?? '',
        'waMessage' => $row['wa_mensagem'] ?? '',
        'footerDesc' => $row['footer_desc'] ?? '',
        'footerAddress' => $row['footer_endereco'] ?? '',
        'footerEmail' => $row['footer_email'] ?? '',
        'footerPhone' => $row['footer_telefone'] ?? '',
        'footerCopyright' => $row['footer_copyright'] ?? '',
        'favicon' => $row['favicon'] ?? ''
    ];
}

switch ($method) {
    case 'GET':
        $stmt = $pdo->query("SELECT * FROM personalizacao ORDER BY id DESC LIMIT 1");
        $row = $stmt->fetch();
        jsonResponse(rowToCustomization($row));
        break;

    case 'POST':
        $customization = [];
        if (!empty($_POST['customization'])) {
            $decoded = json_decode($_POST['customization'], true);
            if (is_array($decoded)) $customization = $decoded;
        }

        $uploadDir = __DIR__ . '/../images/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        // Uploads de novas imagens do hero.
        $newHeroPaths = [];
        if (!empty($_FILES['hero_images']['name'][0])) {
            $names = $_FILES['hero_images']['name'];
            $tmpNames = $_FILES['hero_images']['tmp_name'];
            $errors = $_FILES['hero_images']['error'];
            $sizes = $_FILES['hero_images']['size'] ?? [];
            if (!is_array($names)) { $names = [$names]; $tmpNames = [$tmpNames]; $errors = [$errors]; $sizes = is_array($sizes) ? $sizes : [$sizes]; }
            foreach ($names as $i => $name) {
                if (($errors[$i] ?? 0) !== UPLOAD_ERR_OK) continue;
                $f = ['name' => $name, 'tmp_name' => $tmpNames[$i], 'error' => $errors[$i], 'size' => $sizes[$i] ?? 0];
                $r = validateAndSaveImageUpload($f, 'hero' . $i, $uploadDir);
                if ($r['error']) jsonResponse(['error' => $r['error']], 400);
                if ($r['path']) $newHeroPaths[] = $r['path'];
            }
        }

        // Lê imagens atuais para aplicar deletions e merge.
        $stmtHero = $pdo->query("SELECT hero_imagens FROM personalizacao ORDER BY id DESC LIMIT 1");
        $heroRow = $stmtHero ? $stmtHero->fetch() : null;
        $currentHeroList = [];
        if ($heroRow && !empty($heroRow['hero_imagens'])) {
            $decoded = json_decode($heroRow['hero_imagens'], true);
            if (is_array($decoded)) $currentHeroList = $decoded;
        }

        // Processa pedidos de remoção vindos do frontend.
        $heroToDelete = [];
        if (isset($_POST['hero_images_to_delete'])) {
            $raw = $_POST['hero_images_to_delete'];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $heroToDelete = $decoded;
                elseif (trim($raw) !== '') $heroToDelete = [$raw];
            } elseif (is_array($raw)) {
                $heroToDelete = $raw;
            }
        }
        $heroToDelete = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $heroToDelete), static fn($v) => $v !== ''));

        $remainingHero = $currentHeroList;
        if (!empty($heroToDelete)) {
            $remainingHero = array_values(array_filter($currentHeroList, static fn($p) => !in_array($p, $heroToDelete, true)));
            foreach ($heroToDelete as $delPath) {
                safeDeleteUploadedImage($delPath);
            }
        }

        // Aplica a ordem enviada pelo drag-and-drop (somente para as imagens já guardadas).
        $orderedHero = $remainingHero;
        $heroOrderChanged = false;
        if (isset($_POST['hero_images_order'])) {
            $rawOrder = $_POST['hero_images_order'];
            $requestedOrder = null;
            if (is_string($rawOrder)) {
                $decodedOrder = json_decode($rawOrder, true);
                if (is_array($decodedOrder)) $requestedOrder = $decodedOrder;
            } elseif (is_array($rawOrder)) {
                $requestedOrder = $rawOrder;
            }
            if (is_array($requestedOrder)) {
                $requestedOrder = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $requestedOrder), static fn($v) => $v !== ''));
                $seen = [];
                $ordered = [];
                foreach ($requestedOrder as $p) {
                    if (in_array($p, $remainingHero, true) && !in_array($p, $seen, true)) {
                        $ordered[] = $p;
                        $seen[] = $p;
                    }
                }
                foreach ($remainingHero as $p) {
                    if (!in_array($p, $seen, true)) $ordered[] = $p;
                }
                $orderedHero = $ordered;
                $heroOrderChanged = $orderedHero !== $currentHeroList;
            }
        }

        $heroImgs = null;
        $heroChanged = !empty($newHeroPaths) || !empty($heroToDelete) || $heroOrderChanged;
        if ($heroChanged) {
            $mergedHero = array_values(array_unique(array_merge($orderedHero, $newHeroPaths)));
            $heroImgs = !empty($mergedHero) ? json_encode($mergedHero) : json_encode([]);
        }

        $imageMap = ['about_image' => 'about_imagem', 'favicon_image' => 'favicon', 'testi1_image' => 'testi1_imagem', 'testi2_image' => 'testi2_imagem', 'testi3_image' => 'testi3_imagem'];
        $uploadedImages = [];
        foreach ($imageMap as $formKey => $col) {
            if (!empty($_FILES[$formKey]['tmp_name']) && $_FILES[$formKey]['error'] === UPLOAD_ERR_OK) {
                $r = validateAndSaveImageUpload($_FILES[$formKey], $col, $uploadDir);
                if ($r['error']) jsonResponse(['error' => $r['error']], 400);
                if ($r['path']) $uploadedImages[$col] = $r['path'];
            }
        }

        // Buscar dados existentes para manter imagens antigas
        $stmt = $pdo->query("SELECT * FROM personalizacao ORDER BY id DESC LIMIT 1");
        $existing = $stmt->fetch();
        $existingImages = $existing ? [
            'about_imagem' => $existing['about_imagem'],
            'favicon' => $existing['favicon'],
            'testi1_imagem' => $existing['testi1_imagem'],
            'testi2_imagem' => $existing['testi2_imagem'],
            'testi3_imagem' => $existing['testi3_imagem'],
            'hero_imagens' => $existing['hero_imagens']
        ] : [];

        $heroImgs = $heroImgs ?? $existingImages['hero_imagens'] ?? '["images/hero.png"]';

        $aboutImg = $uploadedImages['about_imagem'] ?? $existingImages['about_imagem'] ?? $customization['aboutImage'] ?? '';
        $favicon = $uploadedImages['favicon'] ?? $existingImages['favicon'] ?? $customization['favicon'] ?? '';
        $t1img = $uploadedImages['testi1_imagem'] ?? $existingImages['testi1_imagem'] ?? $customization['testi1Image'] ?? '';
        $t2img = $uploadedImages['testi2_imagem'] ?? $existingImages['testi2_imagem'] ?? $customization['testi2Image'] ?? '';
        $t3img = $uploadedImages['testi3_imagem'] ?? $existingImages['testi3_imagem'] ?? $customization['testi3Image'] ?? '';

        $params = [
            $customization['heroTitle'] ?? '',
            $customization['heroSubtitle'] ?? '',
            $heroImgs,
            $customization['aboutTitle'] ?? '',
            $customization['aboutText'] ?? '',
            $aboutImg ?: ($customization['aboutImage'] ?? ''),
            $customization['chaletsSubtitle'] ?? '',
            $customization['chaletsTitle'] ?? '',
            $customization['chaletsDesc'] ?? '',
            $customization['feat1Title'] ?? '',
            $customization['feat1Desc'] ?? '',
            $customization['feat2Title'] ?? '',
            $customization['feat2Desc'] ?? '',
            $customization['feat3Title'] ?? '',
            $customization['feat3Desc'] ?? '',
            $customization['feat4Title'] ?? '',
            $customization['feat4Desc'] ?? '',
            $customization['feat5Title'] ?? '',
            $customization['feat5Desc'] ?? '',
            $customization['testi1Name'] ?? '',
            $customization['testi1Location'] ?? '',
            $customization['testi1Text'] ?? '',
            $t1img,
            $customization['testi2Name'] ?? '',
            $customization['testi2Location'] ?? '',
            $customization['testi2Text'] ?? '',
            $t2img,
            $customization['testi3Name'] ?? '',
            $customization['testi3Location'] ?? '',
            $customization['testi3Text'] ?? '',
            $t3img,
            $customization['locAddress'] ?? '',
            $customization['locCar'] ?? '',
            $customization['locMapLink'] ?? '',
            $customization['waNumber'] ?? '',
            $customization['waMessage'] ?? '',
            $customization['footerDesc'] ?? '',
            $customization['footerAddress'] ?? '',
            $customization['footerEmail'] ?? '',
            $customization['footerPhone'] ?? '',
            $customization['footerCopyright'] ?? '',
            $favicon
        ];

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE personalizacao SET hero_titulo=?, hero_subtitulo=?, hero_imagens=?, about_titulo=?, about_texto=?, about_imagem=?, chalets_subtitulo=?, chalets_titulo=?, chalets_desc=?, feat1_titulo=?, feat1_desc=?, feat2_titulo=?, feat2_desc=?, feat3_titulo=?, feat3_desc=?, feat4_titulo=?, feat4_desc=?, feat5_titulo=?, feat5_desc=?, testi1_nome=?, testi1_local=?, testi1_texto=?, testi1_imagem=?, testi2_nome=?, testi2_local=?, testi2_texto=?, testi2_imagem=?, testi3_nome=?, testi3_local=?, testi3_texto=?, testi3_imagem=?, loc_endereco=?, loc_carro=?, loc_map_link=?, wa_numero=?, wa_mensagem=?, footer_desc=?, footer_endereco=?, footer_email=?, footer_telefone=?, footer_copyright=?, favicon=? WHERE id=?");
            $params[] = $existing['id'];
            $stmt->execute($params);
        } else {
            $stmt = $pdo->prepare("INSERT INTO personalizacao (hero_titulo, hero_subtitulo, hero_imagens, about_titulo, about_texto, about_imagem, chalets_subtitulo, chalets_titulo, chalets_desc, feat1_titulo, feat1_desc, feat2_titulo, feat2_desc, feat3_titulo, feat3_desc, feat4_titulo, feat4_desc, feat5_titulo, feat5_desc, testi1_nome, testi1_local, testi1_texto, testi1_imagem, testi2_nome, testi2_local, testi2_texto, testi2_imagem, testi3_nome, testi3_local, testi3_texto, testi3_imagem, loc_endereco, loc_carro, loc_map_link, wa_numero, wa_mensagem, footer_desc, footer_endereco, footer_email, footer_telefone, footer_copyright, favicon) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute($params);
        }

        jsonResponse(['status' => 'success', 'message' => 'Personalizações salvas no banco de dados.']);
        break;

    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
}
?>
