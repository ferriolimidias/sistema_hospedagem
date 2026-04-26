<?php
/**
 * API para Personalização do site (Admin > Personalização).
 * Usa a tabela personalizacao com colunas separadas.
 */
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
try {
    $pdo->exec("ALTER TABLE personalizacao ADD COLUMN logo_principal VARCHAR(500) NULL AFTER footer_copyright");
} catch (Throwable $e) {
    // Coluna já existe.
}
try {
    $pdo->exec("ALTER TABLE personalizacao ADD COLUMN logo_alternativa VARCHAR(500) NULL AFTER logo_principal");
} catch (Throwable $e) {
    // Coluna já existe.
}

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
        'locMapEmbed' => $row['loc_map_embed'] ?? '',
        'videosEnabled' => (int)($row['videos_enabled'] ?? 0),
        'videosJson' => !empty($row['videos_json']) ? (json_decode($row['videos_json'], true) ?: []) : [],
        'waNumber' => $row['wa_numero'] ?? '',
        'waMessage' => $row['wa_mensagem'] ?? '',
        'footerDesc' => $row['footer_desc'] ?? '',
        'footerAddress' => $row['footer_endereco'] ?? '',
        'footerEmail' => $row['footer_email'] ?? '',
        'footerPhone' => $row['footer_telefone'] ?? '',
        'footerCopyright' => $row['footer_copyright'] ?? '',
        'logoPrincipalImg' => $row['logo_principal'] ?? '',
        'logoAlternativaImg' => $row['logo_alternativa'] ?? '',
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
        $heroExistingProvided = false;
        if (isset($_POST['hero_existing_images'])) {
            $rawExisting = $_POST['hero_existing_images'];
            $existingRequested = [];
            if (is_string($rawExisting)) {
                $decodedExisting = json_decode($rawExisting, true);
                if (is_array($decodedExisting)) $existingRequested = $decodedExisting;
            } elseif (is_array($rawExisting)) {
                $existingRequested = $rawExisting;
            }
            $existingRequested = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $existingRequested), static fn($v) => $v !== ''));
            $heroExistingProvided = true;
            $remainingHero = array_values(array_filter($currentHeroList, static fn($p) => in_array($p, $existingRequested, true)));
            $omittedHero = array_values(array_filter($currentHeroList, static fn($p) => !in_array($p, $remainingHero, true)));
            foreach ($omittedHero as $delPath) {
                safeDeleteUploadedImage($delPath);
            }
        }
        if (!empty($heroToDelete)) {
            $remainingHero = array_values(array_filter($remainingHero, static fn($p) => !in_array($p, $heroToDelete, true)));
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
        $heroChanged = !empty($newHeroPaths) || !empty($heroToDelete) || $heroOrderChanged || $heroExistingProvided;
        if ($heroChanged) {
            $mergedHero = array_values(array_unique(array_merge($orderedHero, $newHeroPaths)));
            $heroImgs = !empty($mergedHero) ? json_encode($mergedHero) : json_encode([]);
        }

        $imageMap = ['about_image' => 'about_imagem', 'favicon_image' => 'favicon', 'logoPrincipalImg' => 'logo_principal', 'logoAlternativaImg' => 'logo_alternativa', 'testi1_image' => 'testi1_imagem', 'testi2_image' => 'testi2_imagem', 'testi3_image' => 'testi3_imagem'];
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
            'logo_principal' => $existing['logo_principal'] ?? '',
            'logo_alternativa' => $existing['logo_alternativa'] ?? '',
            'testi1_imagem' => $existing['testi1_imagem'],
            'testi2_imagem' => $existing['testi2_imagem'],
            'testi3_imagem' => $existing['testi3_imagem'],
            'hero_imagens' => $existing['hero_imagens']
        ] : [];

        $removeTesti1 = isset($_POST['remove_testi1Img']) && $_POST['remove_testi1Img'] === '1';
        $removeTesti2 = isset($_POST['remove_testi2Img']) && $_POST['remove_testi2Img'] === '1';
        $removeTesti3 = isset($_POST['remove_testi3Img']) && $_POST['remove_testi3Img'] === '1';
        $removeLogoPrincipal = isset($_POST['remove_logoPrincipalImg']) && $_POST['remove_logoPrincipalImg'] === '1';
        $removeLogoAlternativa = isset($_POST['remove_logoAlternativaImg']) && $_POST['remove_logoAlternativaImg'] === '1';
        if ($existing) {
            if ($removeLogoPrincipal && !isset($uploadedImages['logo_principal'])) {
                safeDeleteUploadedImage((string)($existingImages['logo_principal'] ?? ''));
                $existingImages['logo_principal'] = '';
            }
            if ($removeLogoAlternativa && !isset($uploadedImages['logo_alternativa'])) {
                safeDeleteUploadedImage((string)($existingImages['logo_alternativa'] ?? ''));
                $existingImages['logo_alternativa'] = '';
            }
            if ($removeTesti1 && !isset($uploadedImages['testi1_imagem'])) {
                safeDeleteUploadedImage((string)($existingImages['testi1_imagem'] ?? ''));
                $existingImages['testi1_imagem'] = '';
            }
            if ($removeTesti2 && !isset($uploadedImages['testi2_imagem'])) {
                safeDeleteUploadedImage((string)($existingImages['testi2_imagem'] ?? ''));
                $existingImages['testi2_imagem'] = '';
            }
            if ($removeTesti3 && !isset($uploadedImages['testi3_imagem'])) {
                safeDeleteUploadedImage((string)($existingImages['testi3_imagem'] ?? ''));
                $existingImages['testi3_imagem'] = '';
            }
        }

        $heroImgs = $heroImgs ?? $existingImages['hero_imagens'] ?? '["images/hero.png"]';

        $aboutImg = $uploadedImages['about_imagem'] ?? $existingImages['about_imagem'] ?? $customization['aboutImage'] ?? '';
        $favicon = $uploadedImages['favicon'] ?? $existingImages['favicon'] ?? $customization['favicon'] ?? '';
        $logoPrincipal = $uploadedImages['logo_principal'] ?? $existingImages['logo_principal'] ?? $customization['logoPrincipalImg'] ?? '';
        $logoAlternativa = $uploadedImages['logo_alternativa'] ?? $existingImages['logo_alternativa'] ?? $customization['logoAlternativaImg'] ?? '';
        $t1img = $uploadedImages['testi1_imagem'] ?? $existingImages['testi1_imagem'] ?? $customization['testi1Image'] ?? '';
        $t2img = $uploadedImages['testi2_imagem'] ?? $existingImages['testi2_imagem'] ?? $customization['testi2Image'] ?? '';
        $t3img = $uploadedImages['testi3_imagem'] ?? $existingImages['testi3_imagem'] ?? $customization['testi3Image'] ?? '';

        // Sanitização estrita do embed do mapa (permite apenas a tag iframe).
        $locMapEmbed = strip_tags($customization['locMapEmbed'] ?? '', '<iframe>');
        $videosEnabled = !empty($customization['videosEnabled']) ? 1 : 0;
        $videosJsonInput = $customization['videosJson'] ?? [];
        if (is_string($videosJsonInput)) {
            $decodedVideos = json_decode($videosJsonInput, true);
            $videosJsonInput = is_array($decodedVideos) ? $decodedVideos : [];
        }
        if (!is_array($videosJsonInput)) {
            $videosJsonInput = [];
        }
        $videosJsonClean = [];
        foreach ($videosJsonInput as $v) {
            $url = trim((string) ($v['url'] ?? $v ?? ''));
            if ($url === '') continue;
            if (!preg_match('/^https?:\/\//i', $url)) continue;
            $videosJsonClean[] = ['url' => $url];
        }
        $videosJson = json_encode($videosJsonClean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
            $locMapEmbed,
            $videosEnabled,
            $videosJson,
            $customization['waNumber'] ?? '',
            $customization['waMessage'] ?? '',
            $customization['footerDesc'] ?? '',
            $customization['footerAddress'] ?? '',
            $customization['footerEmail'] ?? '',
            $customization['footerPhone'] ?? '',
            $customization['footerCopyright'] ?? '',
            $logoPrincipal,
            $logoAlternativa,
            $favicon
        ];

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE personalizacao SET hero_titulo=?, hero_subtitulo=?, hero_imagens=?, about_titulo=?, about_texto=?, about_imagem=?, chalets_subtitulo=?, chalets_titulo=?, chalets_desc=?, feat1_titulo=?, feat1_desc=?, feat2_titulo=?, feat2_desc=?, feat3_titulo=?, feat3_desc=?, feat4_titulo=?, feat4_desc=?, feat5_titulo=?, feat5_desc=?, testi1_nome=?, testi1_local=?, testi1_texto=?, testi1_imagem=?, testi2_nome=?, testi2_local=?, testi2_texto=?, testi2_imagem=?, testi3_nome=?, testi3_local=?, testi3_texto=?, testi3_imagem=?, loc_endereco=?, loc_carro=?, loc_map_link=?, loc_map_embed=?, videos_enabled=?, videos_json=?, wa_numero=?, wa_mensagem=?, footer_desc=?, footer_endereco=?, footer_email=?, footer_telefone=?, footer_copyright=?, logo_principal=?, logo_alternativa=?, favicon=? WHERE id=?");
            $params[] = $existing['id'];
            $stmt->execute($params);
        } else {
            $stmt = $pdo->prepare("INSERT INTO personalizacao (hero_titulo, hero_subtitulo, hero_imagens, about_titulo, about_texto, about_imagem, chalets_subtitulo, chalets_titulo, chalets_desc, feat1_titulo, feat1_desc, feat2_titulo, feat2_desc, feat3_titulo, feat3_desc, feat4_titulo, feat4_desc, feat5_titulo, feat5_desc, testi1_nome, testi1_local, testi1_texto, testi1_imagem, testi2_nome, testi2_local, testi2_texto, testi2_imagem, testi3_nome, testi3_local, testi3_texto, testi3_imagem, loc_endereco, loc_carro, loc_map_link, loc_map_embed, videos_enabled, videos_json, wa_numero, wa_mensagem, footer_desc, footer_endereco, footer_email, footer_telefone, footer_copyright, logo_principal, logo_alternativa, favicon) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute($params);
        }

        jsonResponse(['status' => 'success', 'message' => 'Personalizações salvas no banco de dados.']);
        break;

    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
}
?>
