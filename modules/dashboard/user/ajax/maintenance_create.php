<?php
session_start();

require_once __DIR__ . '/../../../../config/connectionBD.php';
require_once __DIR__ . '/../../../../helpers/Mailer.php';

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
error_reporting(E_ALL);

/* =========================================================
   VALIDAR SESIÓN
========================================================= */

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);

    echo json_encode([
        'ok'  => false,
        'msg' => 'No autenticado'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/* =========================================================
   VALIDAR ROLES
========================================================= */

if (
    !in_array((int)($_SESSION['user_rol'] ?? 0), [2, 3, 4])
) {
    http_response_code(403);

    echo json_encode([
        'ok'  => false,
        'msg' => 'Sin permisos'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$pdo = Database::getConnection();

$userId = (int)$_SESSION['user_id'];

/* =========================================================
   LIMPIAR STRINGS
========================================================= */

function cleanStr($v): string
{
    $s = trim((string)$v);
    $s = preg_replace('/\s+/', ' ', $s);

    return $s;
}
try {

    /* =====================================================
       OBTENER USUARIO
    ===================================================== */

    $stmt = $pdo->prepare("
        SELECT
            email,
            name,
            last_name,
            area
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);

    $me = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$me) {

        http_response_code(400);

        echo json_encode([
            'ok'  => false,
            'msg' => 'Usuario no encontrado'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $email = cleanStr($me['email'] ?? '');

    if (
        $email === '' ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {

        http_response_code(400);

        echo json_encode([
            'ok'  => false,
            'msg' => 'Email inválido'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /* =====================================================
       DATOS FORMULARIO
    ===================================================== */

    $title = cleanStr($_POST['title'] ?? '');

    $description = trim($_POST['description'] ?? '');

    if (
        $title === '' ||
        mb_strlen($title) < 5
    ) {

        http_response_code(400);

        echo json_encode([
            'ok'  => false,
            'msg' => 'El título es obligatorio'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    if (
        $description === '' ||
        mb_strlen($description) < 10
    ) {

        http_response_code(400);

        echo json_encode([
            'ok'  => false,
            'msg' => 'La descripción es obligatoria'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /* =====================================================
       INSERTAR SOLICITUD
    ===================================================== */

    $pdo->beginTransaction();

    $stmtInsert = $pdo->prepare("
        INSERT INTO maintenance_requests (
            requester_user_id,
            requester_email,
            title,
            description,
            status
        )
        VALUES (
            ?, ?, ?, ?, 'ABIERTO'
        )
    ");

    $stmtInsert->execute([
        $userId,
        $email,
        $title,
        $description
    ]);

    $requestId = (int)$pdo->lastInsertId();
/* =====================================================
   GUARDAR ARCHIVOS
===================================================== */



file_put_contents(
    __DIR__ . '/debug_files.txt',
    print_r($_FILES, true)
);

//---------------------------------
if (!empty($_FILES['files']['name'][0])) {

    $uploadDir = __DIR__ . '/../../../../uploads/maintenance/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
if (!is_writable($uploadDir)) {
    throw new Exception('La carpeta uploads/maintenance no tiene permisos de escritura');
}
    $stmtFile = $pdo->prepare("
        INSERT INTO maintenance_files (
            maintenance_request_id,
            file_name,
            file_path,
            mime_type,
            file_size
        )
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($_FILES['files']['tmp_name'] as $i => $tmpName) {

        if (!is_uploaded_file($tmpName)) {
            continue;
        }

        $originalName = $_FILES['files']['name'][$i] ?? 'file';

        $mimeType = $_FILES['files']['type'][$i] ?? '';

        $fileSize = (int)($_FILES['files']['size'][$i] ?? 0);

        $ext = pathinfo($originalName, PATHINFO_EXTENSION);

        $safeName = uniqid('mnt_', true) . '.' . strtolower($ext);
$allowedExtensions = [
    'jpg','jpeg','png','gif','webp',
    'mp4','mov','avi',
    'pdf','doc','docx','xls','xlsx'
];

if (!in_array(strtolower($ext), $allowedExtensions, true)) {
    continue;
}

if ($fileSize > 20 * 1024 * 1024) {
    continue;
}
        $relativePath = '/HelpDesk_EQF/uploads/maintenance/' . $safeName;

        $absolutePath = $uploadDir . $safeName;

        if (!move_uploaded_file($tmpName, $absolutePath)) {
    throw new Exception('No se pudo guardar el archivo: ' . $originalName);
}

$stmtFile->execute([
    $requestId,
    $originalName,
    $relativePath,
    $mimeType,
    $fileSize
]);
    }
}
        $pdo->commit();

    /* =====================================================
       CORREO
    ===================================================== */

    $to = [
        'proyectos@eqf.mx'     => 'Proyectos',
        'aux.proyectos@eqf.mx' => 'Auxiliar Proyectos'
    ];

    $subject = 'Nueva solicitud de mantenimiento';

    $bodyText = "
Nueva solicitud de mantenimiento

ID: #{$requestId}

Solicitante:
{$email}

Título:
{$title}

Descripción:
{$description}
";

    try {

        sendMailEQF(
            $to,
            $subject,
            $bodyText
        );

    } catch (Throwable $e) {
        /* no romper flujo por correo */
    }

    /* =====================================================
       RESPUESTA
    ===================================================== */

    echo json_encode([
        'ok'  => true,
        'id'  => $requestId,
        'msg' => 'Solicitud creada correctamente'
    ], JSON_UNESCAPED_UNICODE);

    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'ok'   => false,
        'msg'  => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => $e->getFile()
    ], JSON_UNESCAPED_UNICODE);

    exit;
}