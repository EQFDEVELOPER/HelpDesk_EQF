<?php

session_start();

require_once __DIR__ . '/../../../config/connectionBD.php';

if (!isset($_SESSION['user_id'])) {
    die('No autorizado');
}

$conn = Database::getConnection();

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    die('ID inválido');
}

if (!isset($_FILES['scanned_file'])) {
    die('No se recibió archivo');
}

$file = $_FILES['scanned_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    die('Error al subir archivo');
}

/* =========================================
   VALIDACIONES
========================================= */

$allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($extension, $allowedExtensions)) {
    die('Formato no permitido');
}

/* 50 MB */
$maxSize = 50 * 1024 * 1024;

if ($file['size'] > $maxSize) {
    die('El archivo excede el tamaño permitido');
}

/* =========================================
   CARPETA DESTINO
========================================= */

$uploadDir = __DIR__ . '/../../../uploads/maintenance/scanned/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

/* =========================================
   NOMBRE ARCHIVO
========================================= */

$fileName = 'maintenance_' . $id . '_' . time() . '.' . $extension;

$destination = $uploadDir . $fileName;

/* =========================================
   MOVER ARCHIVO
========================================= */

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    die('No se pudo guardar el archivo');
}

/* =========================================
   GUARDAR EN BD
========================================= */

$relativePath = 'uploads/maintenance/scanned/' . $fileName;

$stmt = $conn->prepare("
    UPDATE maintenance_requests
    SET scanned_request = ?
    WHERE id = ?
");

$stmt->execute([$relativePath, $id]);

header("Location: " . $_SERVER['HTTP_REFERER']);
exit;