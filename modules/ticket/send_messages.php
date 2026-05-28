<?php
session_start();
require_once __DIR__ . '/../../config/connectionBD.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'No autenticado']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$rol    = (int)($_SESSION['user_rol'] ?? 0);

$ticketId = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
$mensaje  = trim($_POST['mensaje'] ?? '');
$equipoArea = $_POST['equipo_area'] ?? [];
$equipoAreaJson = json_encode($equipoArea, JSON_UNESCAPED_UNICODE);
// acepta cualquiera de los 2 nombres:
$isInternal = 0;
if (isset($_POST['interno'])) {
    $isInternal = (int)$_POST['interno'];
} elseif (isset($_POST['is_internal'])) {
    $isInternal = (int)$_POST['is_internal'];
}

// Usuario final no puede marcar interno
if ($rol === 4) {
    $isInternal = 0;
}

$hasFile = isset($_FILES['adjunto']) && $_FILES['adjunto']['error'] !== UPLOAD_ERR_NO_FILE;
$hasClipboardFiles = (
    isset($_FILES['clipboard_files']) &&
    isset($_FILES['clipboard_files']['name']) &&
    is_array($_FILES['clipboard_files']['name']) &&
    count(array_filter($_FILES['clipboard_files']['name'])) > 0
);

if ($ticketId <= 0 || ($mensaje === '' && !$hasFile && !$hasClipboardFiles)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']);
    exit;
}

$pdo = Database::getConnection();

try {
    // Validar ticket / permisos
    $stmt = $pdo->prepare("
        SELECT id, user_id, asignado_a
        FROM tickets
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => 'Ticket no encontrado']);
        exit;
    }

    $ticketUserId    = (int)$ticket['user_id'];
    $ticketAnalystId = (int)($ticket['asignado_a'] ?? 0);

    $allowed = false;

//  Dueño del ticket puede escribir (sea usuario final o analista)
if ($userId === $ticketUserId) {
    $allowed = true;

//  Analista asignado puede escribir
} elseif ($rol === 3 && $userId === $ticketAnalystId) {
    $allowed = true;

//  SA / Admin
} elseif (in_array($rol, [1, 2], true)) {
    $allowed = true;
}


    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Sin permisos para escribir en este ticket']);
        exit;
    }

    $senderRole = match ($rol) {
        3       => 'analista',
        4       => 'usuario',
        1       => 'sa',
        2       => 'admin',
        default => 'usuario'
    };

    $pdo->beginTransaction();
if ($isInternal === 1 && !empty($equipoArea)) {

    $stmtEq = $pdo->prepare("
        UPDATE tickets
        SET equipo_area = :equipo_area
        WHERE id = :id
    ");

    $stmtEq->execute([
        ':equipo_area' => $equipoAreaJson,
        ':id' => $ticketId
    ]);
}
    // 1) Insertamos mensaje (con created_at explícito por seguridad)
    $stmtIns = $pdo->prepare("
        INSERT INTO ticket_messages (ticket_id, sender_id, sender_role, mensaje, is_internal, created_at)
        VALUES (:ticket_id, :sender_id, :sender_role, :mensaje, :is_internal, NOW())
    ");
    $stmtIns->execute([
        ':ticket_id'   => $ticketId,
        ':sender_id'   => $userId,
        ':sender_role' => $senderRole,
        ':mensaje'     => $mensaje !== '' ? $mensaje : '[Archivo adjunto]',
        ':is_internal' => $isInternal ? 1 : 0
    ]);

    $msgId = (int)$pdo->lastInsertId();

    // 2) Si hay archivo, lo guardamos
    $fileInfo = null;

    if ($hasFile && isset($_FILES['adjunto']) && $_FILES['adjunto']['error'] === UPLOAD_ERR_OK) {

        // whitelist de extensiones (simple)
        $allowedExt = ['jpg','jpeg','png','webp','pdf','doc','docx','xls','xlsx','csv'];
        $origName = $_FILES['adjunto']['name'] ?? 'archivo';
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext && !in_array($ext, $allowedExt, true)) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'Tipo de archivo no permitido']);
            exit;
        }

        $uploadDir = __DIR__ . '/../../uploads/ticket_messages/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $tmpName  = $_FILES['adjunto']['tmp_name'];
        $mimeType = $_FILES['adjunto']['type'] ?? null;

        $safeExt  = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        $newName  = 't' . $ticketId . '_m' . $msgId . '_' . uniqid() . ($safeExt ? '.' . $safeExt : '');
        $destPath = $uploadDir . $newName;

        if (move_uploaded_file($tmpName, $destPath)) {
            $relPath = 'uploads/ticket_messages/' . $newName;

            $stmtFile = $pdo->prepare("
                INSERT INTO ticket_message_files (message_id, ticket_id, file_name, file_path, file_type)
                VALUES (:message_id, :ticket_id, :file_name, :file_path, :file_type)
            ");
            $stmtFile->execute([
                ':message_id' => $msgId,
                ':ticket_id'  => $ticketId,
                ':file_name'  => $origName,
                ':file_path'  => $relPath,
                ':file_type'  => $mimeType
            ]);

            $fileInfo = [
                'name' => $origName,
                'path' => $relPath,
                'type' => $mimeType
            ];
        }
    }
    // 3) Si hay archivos del portapapeles (clipboard_files[]), guardarlos
$clipboardSaved = [];

if ($hasClipboardFiles) {
    $allowedExt = ['jpg','jpeg','png','webp','pdf','doc','docx','xls','xlsx','csv'];

    $uploadDir = __DIR__ . '/../../uploads/ticket_messages/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $names = $_FILES['clipboard_files']['name'];
    $tmps  = $_FILES['clipboard_files']['tmp_name'];
    $types = $_FILES['clipboard_files']['type'];
    $errs  = $_FILES['clipboard_files']['error'];

    for ($i = 0; $i < count($names); $i++) {
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

        $origName = $names[$i] ?: ('clipboard_' . ($i+1));
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // si viene sin extensión, intenta inferir por mime (solo imágenes)
        $mimeType = $types[$i] ?? '';
        if ($ext === '' && str_starts_with($mimeType, 'image/')) {
            $ext = explode('/', $mimeType)[1] ?? 'png';
        }

        if ($ext && !in_array($ext, $allowedExt, true)) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'Tipo de archivo no permitido (portapapeles)']);
            exit;
        }

        $safeExt  = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        $newName  = 't' . $ticketId . '_m' . $msgId . '_cb' . $i . '_' . uniqid() . ($safeExt ? '.' . $safeExt : '');
        $destPath = $uploadDir . $newName;

        if (move_uploaded_file($tmps[$i], $destPath)) {
            $relPath = 'uploads/ticket_messages/' . $newName;

            $stmtFile = $pdo->prepare("
                INSERT INTO ticket_message_files (message_id, ticket_id, file_name, file_path, file_type)
                VALUES (:message_id, :ticket_id, :file_name, :file_path, :file_type)
            ");
            $stmtFile->execute([
                ':message_id' => $msgId,
                ':ticket_id'  => $ticketId,
                ':file_name'  => $origName,
                ':file_path'  => $relPath,
                ':file_type'  => $mimeType
            ]);

            $clipboardSaved[] = [
                'name' => $origName,
                'path' => $relPath,
                'type' => $mimeType
            ];
        }
    }
}

    
// ===== NOTIFICACIÓN: Nuevo mensaje - Ticket # =====

// destinatarios
$targets = [];

// si escribe usuario (rol 4) -> notificar analista asignado
if ($rol === 4) {
    if ($ticketAnalystId > 0) $targets[] = $ticketAnalystId;

// si escribe analista (rol 3) -> notificar dueño del ticket
} elseif ($rol === 3) {
    if ($ticketUserId > 0) $targets[] = $ticketUserId;
}

// evita notificarse a sí mismo
$targets = array_values(array_unique(array_filter($targets, fn($id) => $id > 0 && $id !== $userId)));

if ($targets) {
    $title = "Ticket #{$ticketId}";
    $body  = "Nuevo mensaje - Ticket #{$ticketId}";

    // el link ajústalo a TU detalle real si es diferente
    $link  = "/HelpDesk_EQF/modules/dashboard/user/ticket_detail.php?id={$ticketId}";

    $insN = $pdo->prepare("
        INSERT INTO notifications (user_ide, type, title, body, link, is_read, created_at)
        VALUES (:uid, :type, :title, :body, :link, 0, NOW())
    ");

    foreach ($targets as $toId) {
        $insN->execute([
            ':uid'   => $toId,
            ':type'  => 'ticket_chat',
            ':title' => $title,
            ':body'  => $body,
            ':link'  => $link,
        ]);
    }
}

    $pdo->commit();

    echo json_encode([
    'ok'        => true,
    'id'        => $msgId,
    'file'      => $fileInfo,
    'clipboard' => $clipboardSaved
]);

    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Error en send_messages: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Error interno']);
    exit;
}
