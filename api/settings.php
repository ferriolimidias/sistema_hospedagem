<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Busca todas as configurações (ou uma específica se chave informada)
        if (isset($_GET['key'])) {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([$_GET['key']]);
            $setting = $stmt->fetch();
            if ($setting) {
                // Tenta fazer decode se for JSON, senão entrega string limpa
                $val = json_decode($setting['setting_value'], true);
                jsonResponse([$_GET['key'] => $val ?? $setting['setting_value']]);
            }
            else {
                jsonResponse([$_GET['key'] => null]);
            }
        }
        else {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Decodar os que parecem JSON - sempre retornar objeto para o frontend
            $parsedSettings = [];
            foreach ($settings as $k => $v) {
                $decoded = json_decode($v, true);
                $parsedSettings[$k] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $v;
            }

            // Personalização vem da tabela personalizacao (prioridade sobre settings)
            try {
                    $stmt = $pdo->query("SELECT * FROM personalizacao ORDER BY id DESC LIMIT 1");
                    $row = $stmt->fetch();
                    if ($row) {
                        $heroImgs = !empty($row['hero_imagens']) ? json_decode($row['hero_imagens'], true) : ['images/hero.png'];
                        if (!is_array($heroImgs)) $heroImgs = ['images/hero.png'];
                        $parsedSettings['customization'] = [
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
            } catch (Exception $e) {
                // Tabela personalizacao pode não existir ainda
            }

            $hasAdminSession = isset($_SESSION['admin_id']) || isset($_SESSION['admin_email']);
            if (!$hasAdminSession) {
                unset($parsedSettings['internalApiKey']);
            }

            jsonResponse((object) $parsedSettings);
        }
        break;

    case 'POST':
        $uploadDir = __DIR__ . '/../images/uploads/';
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $r = validateAndSaveImageUpload($_FILES['logo'], 'logo', $uploadDir);
            if ($r['error']) jsonResponse(['error' => $r['error']], 400);
            if ($r['path']) $_POST['company_logo'] = $r['path'];
        }

        if (isset($_FILES['logo_light']) && $_FILES['logo_light']['error'] === UPLOAD_ERR_OK) {
            $r = validateAndSaveImageUpload($_FILES['logo_light'], 'logo_light', $uploadDir);
            if ($r['error']) jsonResponse(['error' => $r['error']], 400);
            if ($r['path']) $_POST['company_logo_light'] = $r['path'];
        }

        if (!empty($_FILES['hero_images']['name'][0])) {
            $heroPaths = [];
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
                if ($r['path']) $heroPaths[] = $r['path'];
            }
            if (!empty($heroPaths)) {
                $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'customization'");
                $stmt->execute();
                $setting = $stmt->fetch();
                $customization = $setting ? json_decode($setting['setting_value'], true) : [];
                if (isset($_POST['customization'])) $customization = array_merge($customization, json_decode($_POST['customization'], true));
                $customization['heroImages'] = $heroPaths;
                $_POST['customization'] = json_encode($customization);
            }
        }

        $customizationImages = [
            'hero_image' => 'heroImage',
            'about_image' => 'aboutImage',
            'favicon_image' => 'favicon',
            'testi1_image' => 'testi1Image',
            'testi2_image' => 'testi2Image',
            'testi3_image' => 'testi3Image'
        ];

        foreach ($customizationImages as $fileKey => $jsonKey) {
            if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $r = validateAndSaveImageUpload($_FILES[$fileKey], $jsonKey, $uploadDir);
                if ($r['error']) jsonResponse(['error' => $r['error']], 400);
                if ($r['path']) {
                    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'customization'");
                    $stmt->execute();
                    $setting = $stmt->fetch();
                    $customization = $setting ? json_decode($setting['setting_value'], true) : [];
                    if (!isset($_POST['customization'])) {
                        $_POST['customization'] = json_encode($customization);
                    }
                    else {
                        $customization = json_decode($_POST['customization'], true);
                    }
                    $customization[$jsonKey] = $r['path'];
                    if ($fileKey === 'hero_image') {
                        $customization['heroImages'] = [$r['path']];
                    }
                    $_POST['customization'] = json_encode($customization);
                }
            }
        }

        // Salva ou atualiza configuração em lote
        $rawData = file_get_contents("php://input");
        $data = json_decode($rawData, true);

        // Fallback para multipart form data (usa $_POST após processar uploads)
        if (!$data && !empty($_POST)) {
            $data = $_POST;
        } elseif (!empty($_POST)) {
            $data = $_POST;
        }

        if (!$data || !is_array($data)) {
            jsonResponse(['error' => 'Formato de dados inválido.'], 400);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

            $ignoreKeys = ['logo', 'logo_light', 'dummy', 'hero_images', 'about_image', 'favicon_image', 'testi1_image', 'testi2_image', 'testi3_image'];
            foreach ($data as $key => $value) {
                if (in_array($key, $ignoreKeys)) continue;
                if (is_array($value) && isset($value['tmp_name'])) continue; // skip raw file refs

                $stringVal = is_array($value) ? json_encode($value) : (string) $value;
                $stmt->execute([$key, $stringVal]);
            }

            $pdo->commit();
            jsonResponse(['status' => 'success', 'message' => 'Configurações salvas com sucesso']);
        }
        catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Erro ao salvar configurações', 'details' => $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
        break;
}
?>
