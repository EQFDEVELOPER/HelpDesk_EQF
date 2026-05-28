<?php
session_start();

require_once __DIR__ . '/../../../../config/connectionBD.php';

if (!isset($_SESSION['user_id'])) {
    die('No autorizado');
}

$conn = Database::getConnection();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die('ID inválido');
}

// =========================================
// OBTENER DATOS DEL MANTENIMIENTO
// =========================================
$stmt = $conn->prepare("
    SELECT
        id,
        requester_email,
        created_at
    FROM maintenance_requests
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$id]);

$maintenance = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$maintenance) {
    die('Mantenimiento no encontrado');
}

// =========================================
// OBTENER ARCHIVOS ADJUNTOS
// =========================================
$stmtFiles = $conn->prepare("
    SELECT
        file_name,
        file_path
    FROM maintenance_files
    WHERE maintenance_request_id = ?
    ORDER BY id DESC
");

$stmtFiles->execute([$id]);

$files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

if (!$files) {
    die('No hay archivos adjuntos');
}

// =========================================
// NOMBRE DEL ZIP
// MTTO_SUCURSAL_FECHA
// =========================================
$sucursal = $maintenance['requester_email'] ?? 'SUCURSAL';
$sucursal = str_ireplace('@eqf.mx', '', $sucursal);

$sucursal = preg_replace('/[^A-Za-z0-9]/', '_', $sucursal);
$sucursal = strtoupper($sucursal);

$timestamp = strtotime($maintenance['created_at']);
$meses = [
    1  => 'ENE',
    2  => 'FEB',
    3  => 'MAR',
    4  => 'ABR',
    5  => 'MAY',
    6  => 'JUN',
    7  => 'JUL',
    8  => 'AGO',
    9  => 'SEP',
    10 => 'OCT',
    11 => 'NOV',
    12 => 'DIC'
];

$fecha = date('d', $timestamp)
    . $meses[(int)date('n', $timestamp)]
    . date('Y', $timestamp);

// NOMBRE FINAL
$folderName = "MTTO_{$sucursal}_{$fecha}";
$zipName = $folderName . '.zip';
// =========================================
// CREAR ZIP TEMPORAL
// =========================================
$tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('mtto_', true) . '.zip';

$zip = new ZipArchive();

if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die('No se pudo crear el archivo ZIP');
}

// =========================================
// AGREGAR ARCHIVOS AL ZIP
// =========================================
$filesAdded = 0;

foreach ($files as $file) {

    if (empty($file['file_path'])) {
        continue;
    }

    // =====================================
    // RUTA REAL DEL ARCHIVO
    // =====================================
    $relativePath = ltrim($file['file_path'], '/');

    $absolutePath = $_SERVER['DOCUMENT_ROOT'] . '/' . $relativePath;

    if (!file_exists($absolutePath)) {
        continue;
    }

    // =====================================
    // NOMBRE DEL ARCHIVO
    // =====================================
    $fileName = trim($file['file_name']);

    if ($fileName === '') {
        $fileName = basename($absolutePath);
    }

    // =====================================
    // AGREGAR DENTRO DE LA CARPETA
    // =====================================
    $zip->addFile(
        $absolutePath,
        $folderName . '/' . $fileName
    );

    $filesAdded++;
}

$zip->close();

// =========================================
// VALIDAR QUE HAYA ARCHIVOS
// =========================================
if ($filesAdded <= 0) {

    if (file_exists($tempZip)) {
        unlink($tempZip);
    }

    die('No se encontraron archivos válidos para comprimir');
}

// =========================================
// DESCARGAR ZIP
// =========================================
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tempZip));
header('Pragma: no-cache');
header('Expires: 0');

readfile($tempZip);

// =========================================
// ELIMINAR TEMPORAL
// =========================================
unlink($tempZip);

exit;