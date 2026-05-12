<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../config/connectionBD.php';

$conn = Database::getConnection();

if (!isset($_SESSION['user_id'])) {

    http_response_code(401);

    echo json_encode([
        'ok' => false,
        'msg' => 'No autorizado'
    ]);

    exit;
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'msg' => 'ID inválido'
    ]);

    exit;
}

try {

    $stmt = $conn->prepare("
        SELECT *
        FROM maintenance_requests
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);

    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {

        http_response_code(404);

        echo json_encode([
            'ok' => false,
            'msg' => 'Solicitud no encontrada'
        ]);

        exit;
    }

    ob_start();
    ?>

    <div class="task-card">

        <div class="task-row">
            <div class="task-label">ID</div>
            <div class="task-value">
                #<?php echo (int)$r['id']; ?>
            </div>
        </div>

        <div class="task-row">
            <div class="task-label">Solicitante</div>
            <div class="task-value">
                <?php echo htmlspecialchars($r['requester_email']); ?>
            </div>
        </div>

        <div class="task-row">
            <div class="task-label">Asunto</div>
            <div class="task-value">
                <?php echo htmlspecialchars($r['title']); ?>
            </div>
        </div>

        <div class="task-row">
            <div class="task-label">Estatus</div>
            <div class="task-value">
                <?php echo htmlspecialchars($r['status']); ?>
            </div>
        </div>

        <div class="panel-divider"></div>

        <div class="panel-mini-title">
            Descripción
        </div>

        <div class="task-desc">
            <?php echo nl2br(htmlspecialchars($r['description'])); ?>
        </div>

    </div>

    <?php

    $html = ob_get_clean();

    echo json_encode([
        'ok' => true,
        'html' => $html
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage()
    ]);
}