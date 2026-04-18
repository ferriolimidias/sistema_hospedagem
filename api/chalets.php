<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

function chaletImagePathUsable($path): bool
{
    $p = trim((string)$path);
    if ($p === '') return false;
    if (preg_match('/^https?:\/\//i', $p) === 1) return true;
    if (strpos($p, 'data:image/') === 0) return true;
    $clean = ltrim(str_replace('\\', '/', $p), '/');
    return is_file(__DIR__ . '/../' . $clean);
}

function chaletNormalizeImagePath($path): string
{
    return chaletImagePathUsable($path) ? trim((string)$path) : '';
}

switch ($method) {
    case 'GET':
        // Busca chalés
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM chalets WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $chalet = $stmt->fetch();
            if ($chalet) {
                // Decode JSON images if present
                $chalet['images'] = !empty($chalet['images']) ? json_decode($chalet['images'], true) : [];
                $chalet['main_image'] = chaletNormalizeImagePath($chalet['main_image'] ?? '');
                $chalet['images'] = array_values(array_filter(array_map(
                    static fn($img) => chaletNormalizeImagePath($img),
                    is_array($chalet['images']) ? $chalet['images'] : []
                )));
                // Busca feriados relacionados
                $stmtHol = $pdo->prepare("SELECT custom_date as date, price, description as descr FROM chalet_custom_prices WHERE chalet_id = ?");
                $stmtHol->execute([$chalet['id']]);
                $chalet['holidays'] = $stmtHol->fetchAll();
                jsonResponse($chalet);
            }
            else {
                jsonResponse(['error' => 'Chalé não encontrado'], 404);
            }
        }
        else {
            $stmt = $pdo->query("SELECT * FROM chalets ORDER BY id ASC");
            $chalets = $stmt->fetchAll();

            // Attach holidays to all chalets
            $stmtHol = $pdo->query("SELECT chalet_id, custom_date as date, price, description as descr FROM chalet_custom_prices");
            $allHolidays = $stmtHol->fetchAll();
            $holidaysByChalet = [];
            foreach ($allHolidays as $h) {
                $holidaysByChalet[$h['chalet_id']][] = $h;
            }
            foreach ($chalets as &$c) {
                $c['holidays'] = $holidaysByChalet[$c['id']] ?? [];
                $c['images'] = !empty($c['images']) ? json_decode($c['images'], true) : [];
                $c['main_image'] = chaletNormalizeImagePath($c['main_image'] ?? '');
                $c['images'] = array_values(array_filter(array_map(
                    static fn($img) => chaletNormalizeImagePath($img),
                    is_array($c['images']) ? $c['images'] : []
                )));
            }

            jsonResponse($chalets);
        }
        break;

    case 'POST':
        // Como o JS enviará FormData (para upload de arquivos), o método no formulário será sempre POST.
        // Se houver um 'id' repassado, tratamos como UPDATE.

        $id = !empty($_POST['id']) ? trim($_POST['id']) : null;
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $price = isset($_POST['price']) ? floatval(str_replace(',', '.', $_POST['price'])) : 0;
        $badge = trim($_POST['badge'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $full_description = trim($_POST['full_description'] ?? '');
        $status = trim($_POST['status'] ?? 'Ativo') ?: 'Ativo';

        $p_mon = !empty($_POST['price_mon']) ? floatval(str_replace(',', '.', $_POST['price_mon'])) : null;
        $p_tue = !empty($_POST['price_tue']) ? floatval(str_replace(',', '.', $_POST['price_tue'])) : null;
        $p_wed = !empty($_POST['price_wed']) ? floatval(str_replace(',', '.', $_POST['price_wed'])) : null;
        $p_thu = !empty($_POST['price_thu']) ? floatval(str_replace(',', '.', $_POST['price_thu'])) : null;
        $p_fri = !empty($_POST['price_fri']) ? floatval(str_replace(',', '.', $_POST['price_fri'])) : null;
        $p_sat = !empty($_POST['price_sat']) ? floatval(str_replace(',', '.', $_POST['price_sat'])) : null;
        $p_sun = !empty($_POST['price_sun']) ? floatval(str_replace(',', '.', $_POST['price_sun'])) : null;

        $base_guests = isset($_POST['base_guests']) ? (int) $_POST['base_guests'] : 2;
        if ($base_guests < 1) {
            $base_guests = 1;
        }
        $extra_guest_fee = isset($_POST['extra_guest_fee']) ? floatval(str_replace(',', '.', (string) $_POST['extra_guest_fee'])) : 0.0;
        if ($extra_guest_fee < 0) {
            $extra_guest_fee = 0.0;
        }

        $imagePath = null;
        $uploadDir = __DIR__ . '/../images/uploads/';
        if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
            $r = validateAndSaveImageUpload($_FILES['main_image'], 'main', $uploadDir);
            if ($r['error']) jsonResponse(['error' => $r['error']], 400);
            if ($r['path']) $imagePath = $r['path'];
        }

        $uploadedImages = [];
        if (isset($_FILES['images']) && $_FILES['images']['error'] !== UPLOAD_ERR_NO_FILE) {
            $names = $_FILES['images']['name'];
            $tmpNames = $_FILES['images']['tmp_name'];
            $errors = $_FILES['images']['error'];
            if (!is_array($names)) {
                $names = [$names];
                $tmpNames = [$tmpNames];
                $errors = [$errors];
            }
            for ($i = 0; $i < count($names); $i++) {
                if (isset($errors[$i]) && $errors[$i] === UPLOAD_ERR_OK) {
                    $f = ['name' => $names[$i], 'tmp_name' => $tmpNames[$i], 'error' => $errors[$i], 'size' => $_FILES['images']['size'][$i] ?? 0];
                    $r = validateAndSaveImageUpload($f, 'img' . $i, $uploadDir);
                    if ($r['error']) {
                        jsonResponse(['error' => $r['error']], 400);
                    }
                    if ($r['path']) $uploadedImages[] = $r['path'];
                }
            }
        }

        if (empty($name) || empty($type)) {
            jsonResponse(['error' => 'Nome e tipo são obrigatórios'], 400);
        }

        try {
            $pdo->beginTransaction();

            if ($id) {
                // Edição de Chalé Existente
                $sql = "UPDATE chalets SET name=?, `type`=?, badge=?, price=?, description=?, full_description=?, status=?, 
                        price_mon=?, price_tue=?, price_wed=?, price_thu=?, price_fri=?, price_sat=?, price_sun=?,
                        base_guests=?, extra_guest_fee=?";
                $params = [$name, $type, $badge ?: null, $price, $description ?: null, $full_description ?: null, $status, $p_mon, $p_tue, $p_wed, $p_thu, $p_fri, $p_sat, $p_sun, $base_guests, $extra_guest_fee];

                if ($imagePath) {
                    $sql .= ", main_image=?";
                    $params[] = $imagePath;
                }

                if (count($uploadedImages) > 0) {
                    // Vamos tentar recuperar as imagens existentes para não sobrescrever caso seja apenas um append?
                    // Para simplificar: se mandar novas imagens, elas vão juntar com as antigas (opcional) ou substituir.
                    // O jeito mais robusto é pegar as atuais e fazer merge.
                    $stmtImg = $pdo->prepare("SELECT images FROM chalets WHERE id = ?");
                    $stmtImg->execute([$id]);
                    $curr = $stmtImg->fetch();
                    $currentImages = [];
                    if ($curr && !empty($curr['images'])) {
                        $currentImages = json_decode($curr['images'], true) ?: [];
                    }
                    $allImages = array_merge($currentImages, $uploadedImages);

                    $sql .= ", images=?";
                    $params[] = json_encode($allImages);
                }

                $sql .= " WHERE id=?";
                $params[] = $id;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $chalet_id = $id;
            }
            else {
                // Se $imagePath estiver nulo, mas o usuário mandou $uploadedImages, a primeira pode ser o main_image
                if (!$imagePath && count($uploadedImages) > 0) {
                    $imagePath = $uploadedImages[0];
                }

                // Criação de Novo Chalé
                $sql = "INSERT INTO chalets (name, type, badge, price, description, full_description, status, main_image, images,
                        price_mon, price_tue, price_wed, price_thu, price_fri, price_sat, price_sun, base_guests, extra_guest_fee) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $name, $type, $badge, $price, $description, $full_description, $status, $imagePath, (count($uploadedImages) > 0 ? json_encode($uploadedImages) : null),
                    $p_mon, $p_tue, $p_wed, $p_thu, $p_fri, $p_sat, $p_sun, $base_guests, $extra_guest_fee
                ]);
                $chalet_id = $pdo->lastInsertId();
            }

            // Gerenciar Preços Dinâmicos Especiais (Feriados) enviados via JSON String
            if (isset($_POST['holidays'])) {
                $holidays = json_decode($_POST['holidays'], true);
                if (is_array($holidays)) {
                    $stmtDel = $pdo->prepare("DELETE FROM chalet_custom_prices WHERE chalet_id = ?");
                    $stmtDel->execute([$chalet_id]);

                    if (count($holidays) > 0) {
                        $stmtHol = $pdo->prepare("INSERT INTO chalet_custom_prices (chalet_id, custom_date, price, description) VALUES (?, ?, ?, ?)");
                        foreach ($holidays as $hol) {
                            if (!empty($hol['date']) && isset($hol['price']) && $hol['price'] !== '') {
                                $holPrice = is_numeric($hol['price']) ? floatval(str_replace(',', '.', $hol['price'])) : 0;
                                if ($holPrice > 0) {
                                    $stmtHol->execute([$chalet_id, $hol['date'], $holPrice, trim($hol['descr'] ?? '') ?: null]);
                                }
                            }
                        }
                    }
                }
            }

            $pdo->commit();
            jsonResponse(['status' => 'success', 'id' => $chalet_id], $id ? 200 : 201);

        }
        catch (Exception $e) {
            $pdo->rollBack();
            error_log('Chalets API save error: ' . $e->getMessage());
            jsonResponse(['error' => 'Falha ao salvar chalé'], 500);
        }
        break;

    case 'DELETE':
        $id = isset($_GET['id']) ? intval($_GET['id']) : null;
        if (!$id) {
            jsonResponse(['error' => 'ID do chalé não fornecido'], 400);
        }

        // Verifica se existem reservas vinculadas
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE chalet_id = ?");
        $stmtCheck->execute([$id]);
        if ($stmtCheck->fetchColumn() > 0) {
            jsonResponse(['error' => 'Não é possível excluir: existem reservas vinculadas a este chalé.'], 400);
        }

        try {
            $pdo->beginTransaction();
            $stmtDel = $pdo->prepare("DELETE FROM chalet_custom_prices WHERE chalet_id = ?");
            $stmtDel->execute([$id]);
            $stmtDel = $pdo->prepare("DELETE FROM chalets WHERE id = ?");
            $stmtDel->execute([$id]);
            if ($stmtDel->rowCount() === 0) {
                $pdo->rollBack();
                jsonResponse(['error' => 'Chalé não encontrado'], 404);
            }
            $pdo->commit();
            jsonResponse(['status' => 'success']);
        }
        catch (Exception $e) {
            $pdo->rollBack();
            error_log('Chalets API delete error: ' . $e->getMessage());
            jsonResponse(['error' => 'Falha ao excluir chalé'], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
        break;
}
?>
