<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_extras.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Verifica se a requisição apresenta a chave interna do admin.
 * GET sem chave devolve apenas FAQs ativas (uso público).
 * GET com chave válida devolve todas as FAQs (uso admin).
 * POST/PUT/DELETE exigem sempre chave válida.
 */
function faqs_has_valid_internal_key(PDO $pdo): bool
{
    require_once __DIR__ . '/contract_access.php';
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
        }
    }
    $provided = trim((string) ($headers['x-internal-key'] ?? ''));
    if ($provided === '') return false;
    $expected = getOrCreateInternalApiKey($pdo);
    return hash_equals($expected, $provided);
}

function faqs_sanitize_question(string $s): string
{
    $s = trim($s);
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, 500);
    }
    return substr($s, 0, 500);
}

function faqs_sanitize_answer(string $s): string
{
    $s = trim($s);
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, 10000);
    }
    return substr($s, 0, 10000);
}

if ($method === 'GET') {
    $isAdmin = faqs_has_valid_internal_key($pdo);
    if ($isAdmin) {
        $stmt = $pdo->query('SELECT id, question, answer, sort_order, is_active, created_at, updated_at FROM faqs ORDER BY sort_order ASC, id ASC');
    } else {
        $stmt = $pdo->query('SELECT id, question, answer, sort_order FROM faqs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');
    }
    jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Mutações exigem chave interna.
be_require_internal_key($pdo);

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonResponse(['error' => 'JSON inválido'], 400);
    }
    $question = faqs_sanitize_question((string) ($data['question'] ?? ''));
    $answer = faqs_sanitize_answer((string) ($data['answer'] ?? ''));
    $isActive = isset($data['is_active']) ? ((int) (bool) $data['is_active']) : 1;

    if ($question === '' || $answer === '') {
        jsonResponse(['error' => 'Pergunta e resposta são obrigatórias.'], 400);
    }

    // Se sort_order não foi informado, coloca no fim da lista.
    if (isset($data['sort_order']) && is_numeric($data['sort_order'])) {
        $sortOrder = (int) $data['sort_order'];
    } else {
        $max = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM faqs')->fetchColumn();
        $sortOrder = $max + 10;
    }

    $stmt = $pdo->prepare('INSERT INTO faqs (question, answer, sort_order, is_active) VALUES (?, ?, ?, ?)');
    $stmt->execute([$question, $answer, $sortOrder, $isActive]);
    jsonResponse(['status' => 'ok', 'id' => (int) $pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        jsonResponse(['error' => 'id obrigatório'], 400);
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonResponse(['error' => 'JSON inválido'], 400);
    }

    $fields = [];
    $params = [];

    if (array_key_exists('question', $data)) {
        $q = faqs_sanitize_question((string) $data['question']);
        if ($q === '') {
            jsonResponse(['error' => 'Pergunta não pode ser vazia.'], 400);
        }
        $fields[] = 'question = ?';
        $params[] = $q;
    }
    if (array_key_exists('answer', $data)) {
        $a = faqs_sanitize_answer((string) $data['answer']);
        if ($a === '') {
            jsonResponse(['error' => 'Resposta não pode ser vazia.'], 400);
        }
        $fields[] = 'answer = ?';
        $params[] = $a;
    }
    if (array_key_exists('sort_order', $data) && is_numeric($data['sort_order'])) {
        $fields[] = 'sort_order = ?';
        $params[] = (int) $data['sort_order'];
    }
    if (array_key_exists('is_active', $data)) {
        $fields[] = 'is_active = ?';
        $params[] = (int) (bool) $data['is_active'];
    }

    if (empty($fields)) {
        jsonResponse(['error' => 'Nenhum campo para atualizar.'], 400);
    }

    $params[] = $id;
    $stmt = $pdo->prepare('UPDATE faqs SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($params);
    jsonResponse(['status' => 'ok', 'id' => $id]);
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        jsonResponse(['error' => 'id obrigatório'], 400);
    }
    $pdo->prepare('DELETE FROM faqs WHERE id = ?')->execute([$id]);
    jsonResponse(['status' => 'ok']);
}

jsonResponse(['error' => 'Método não permitido'], 405);
