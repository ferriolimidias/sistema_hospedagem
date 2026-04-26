<?php
/**
 * DEPRECATED:
 * Este endpoint foi substituído por api/evolution_service.php
 * (fonte única de verdade para notificações Evolution API).
 */
header('Content-Type: application/json');
http_response_code(410);
echo json_encode([
    'ok' => false,
    'deprecated' => true,
    'message' => 'Endpoint descontinuado. Utilize api/evolution_service.php.'
]);
