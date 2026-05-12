<?php

session_start();
ini_set('memory_limit', '512M');
set_time_limit(300);
require_once __DIR__ . '/../../../config/connectionBD.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['user_id'])) {
    die('No autorizado');
}

$conn = Database::getConnection();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die('ID inválido');
}

/* =========================
   SOLICITUD
========================= */

$stmt = $conn->prepare("
    SELECT
        mr.*,
        CONCAT(u.name, ' ', u.last_name) AS full_name
    FROM maintenance_requests mr
    LEFT JOIN users u
        ON u.id = mr.requester_user_id
    WHERE mr.id = ?
    LIMIT 1
");

$stmt->execute([$id]);

$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    die('Solicitud no encontrada');
}

/* =========================
   ARCHIVOS
========================= */

$stmtFiles = $conn->prepare("
    SELECT *
    FROM maintenance_files
    WHERE maintenance_request_id = ?
    ORDER BY id ASC
");

$stmtFiles->execute([$id]);

$files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   DATOS
========================= */

$email = $request['requester_email'] ?? '';

$sucursal = strtoupper(explode('@', $email)[0]);

$fecha = date(
    'd/m/Y',
    strtotime($request['created_at'])
);

$descripcion = nl2br(
    htmlspecialchars($request['description'])
);

$solicitante = htmlspecialchars(
    $request['full_name'] ?? 'N/A'
);

/* =========================
   IMAGENES
========================= */

$imagesHtml = '';

foreach ($files as $file) {

    $path = $file['file_path'] ?? '';

    if (!$path) {
        continue;
    }

    $ext = strtolower(
        pathinfo($path, PATHINFO_EXTENSION)
    );

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowed)) {
        continue;
    }

    $absolutePath = $_SERVER['DOCUMENT_ROOT']
    . $path;

    if (!file_exists($absolutePath)) {
        continue;
    }
    $imageData = base64_encode(
        file_get_contents($absolutePath)
    );

    $src = 'data:image/' . $ext . ';base64,' . $imageData;

    $imagesHtml .= '
        <div class="image-box">
            <img src="' . $src . '">

            <div class="img-name">
                ' . htmlspecialchars($file['file_name']) . '
            </div>
        </div>
    ';
}

/* =========================
   LOGO EQF
========================= */

$logoPath = $_SERVER['DOCUMENT_ROOT']
    . '/HelpDesk_EQF/assets/img/Logo-334x98.png';

$base64Logo = '';

if (file_exists($logoPath)) {

    $logoType = pathinfo($logoPath, PATHINFO_EXTENSION);

    $logoData = base64_encode(
        file_get_contents($logoPath)
    );

    $base64Logo =
        'data:image/' . $logoType . ';base64,' . $logoData;
}
/* =========================
   HTML PDF
========================= */

$html = '
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    body {
        font-family: Arial, sans-serif;
        font-size: 11px;
        color: #333;
        margin: 0;
        padding: 0;
    }

    /* Encabezado Estilo Imagen */
    .header-container {
        width: 100%;
        height: 80px;
        position: relative;
        border-bottom: 2px solid #002b5c;
        margin-bottom: 10px;
    }

    .logo-area {
        float: left;
        width: 40%;
        padding-top: 10px;
    }

    .logo-area img {
        height: 60px;
    }

    .title-area {
    float: right;
    width: 60%;
    background-color: #002b5c;
    color: white;
    height: 60px;
    line-height: 60px;
    text-align: center;
    font-size: 18px;
    font-weight: bold;
}

    /* Tabla de información */
    .info-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 10px;
    }

    .info-table td {
        padding: 5px;
        vertical-align: bottom;
        text-transform: uppercase;
    }

    .line-data {
        border-bottom: 1px solid #333;
        display: inline-block;
        width: 70%;
        padding-left: 5px;
    }

    /* Secciones Azules */
    .section-title {
        background: #002b5c;
        color: white;
        padding: 6px 10px;
        font-weight: bold;
        font-size: 11px;
        margin-top: 5px;
    }

    .content-box {
        border: 1px solid #ccc;
        padding: 10px;
        min-height: 40px;
        line-height: 1.4;
    }

    /* Grid de Imágenes */
    .images-grid {
        width: 100%;
        margin-top: 10px;
    }

    .image-box {
        width: 18%; /* 5 columnas aprox */
        border: 1px solid #ccc;
        padding: 3px;
        margin: 0.5%;
        display: inline-block;
        vertical-align: top;
        text-align: center;
    }

    .image-box img {
    width: 100%;
    height: auto;
    max-height: 100px;
}

    .img-name {
        margin-top: 3px;
        font-size: 9px;
        color: #555;
    }

    /* Firmas */
    .signatures {
        margin-top: 50px;
        width: 100%;
        text-align: center;
    }

    .sign-box {
        width: 45%;
        display: inline-block;
        text-align: center;
    }

    .sign-line {
        width: 80%;
        margin: 0 auto;
        border-top: 1px solid #000;
        margin-bottom: 5px;
    }

    .footer-note {
        margin-top: 30px;
        text-align: center;
        font-style: italic;
        color: #777;
        font-size: 10px;
    }

    .clearfix::after {
        content: "";
        clear: both;
        display: table;
    }

</style>
</head>
<body>

<div class="header-container clearfix">
    <div class="logo-area">
        <img src="' . $base64Logo . '" alt="Logo">
    </div>
    <div class="title-area">
        SOLICITUD DE MANTENIMIENTO
    </div>
</div>

<table class="info-table">
    <tr>
        <td width="60%">
            <strong>JEFE DE SUCURSAL:</strong> 
            <span class="line-data">' . $solicitante . '</span>
        </td>
        <td width="40%">
            <strong>FECHA DE SOLICITUD:</strong> 
            <span class="line-data">' . $fecha . '</span>
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <strong>SUCURSAL:</strong> 
            <span class="line-data" style="width: 82%;">' . $sucursal . '</span>
        </td>
    </tr>
</table>

<div class="section-title">DESCRIPCIÓN DE LA SOLICITUD DE MANTENIMIENTO</div>
<div class="content-box">
    ' . nl2br($descripcion) . '
</div>

<div class="section-title">OBSERVACIONES</div>
<div class="content-box">
    <!-- Aquí puedes poner la variable de observaciones si existe -->
    Anexo evidencia fotográfica.
</div>

<div class="section-title">EN CASO DE NO AUTORIZARSE EL MANTENIMIENTO DESCRIBIR EL ¿POR QUÉ?</div>
<div class="content-box" style="height: 40px;"></div>

<div class="section-title">EVIDENCIA FOTOGRÁFICA (ARCHIVOS ADJUNTOS)</div>
<div class="images-grid">
    ' . $imagesHtml . '
</div>

<div class="signatures clearfix">
    <div class="sign-box">
        <div class="sign-line"></div>
        <strong>Solicita</strong><br>
        (Fecha y firma)
    </div>

    <div class="sign-box">
        <div class="sign-line"></div>
        <strong>Revisó y Autorizó</strong><br>
        (Nombre, fecha y firma)
    </div>
</div>

<div class="footer-note">
    Solicitud generada automáticamente por el sistema HelpDesk R 2026
</div>

</body>
</html>
';

/* =========================
   DOMPDF
========================= */

$options = new Options();

$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);

$dompdf->loadHtml($html);

$dompdf->setPaper('A4', 'portrait');

$dompdf->render();

$dompdf->stream(
    'solicitud_mantenimiento_' . $id . '.pdf',
    ['Attachment' => false]
);