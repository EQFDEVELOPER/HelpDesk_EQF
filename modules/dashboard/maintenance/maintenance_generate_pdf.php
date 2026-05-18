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

$counter = 0;

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

    $absolutePath = $_SERVER['DOCUMENT_ROOT'] . $path;

    if (!file_exists($absolutePath)) {
        continue;
    }

    $imageData = base64_encode(
        file_get_contents($absolutePath)
    );

    $src = 'data:image/' . $ext . ';base64,' . $imageData;

    if ($counter % 4 === 0) {
        $imagesHtml .= '<tr>';
    }

    $imagesHtml .= '
        <td>
            <div class="image-wrapper">
                <img src="' . $src . '">
            </div>
        </td>
    ';

    $counter++;

    if ($counter % 4 === 0) {
        $imagesHtml .= '</tr>';
    }
}

if ($counter % 4 !== 0) {

    while ($counter % 4 !== 0) {

        $imagesHtml .= '<td></td>';

        $counter++;
    }

    $imagesHtml .= '</tr>';
}
 
/* =========================
   HEADER / FOOTER IMAGES
========================= */
 
$headerPath = $_SERVER['DOCUMENT_ROOT']
    . '/HelpDesk_EQF/assets/img/header_mantenimiento.png';
 
$footerPath = $_SERVER['DOCUMENT_ROOT']
    . '/HelpDesk_EQF/assets/img/footer_mantenimiento.png';
 
$base64Header = '';
$base64Footer = '';
 
if (file_exists($headerPath)) {
 
    $headerType = pathinfo($headerPath, PATHINFO_EXTENSION);
 
    $headerData = base64_encode(
        file_get_contents($headerPath)
    );
 
    $base64Header =
        'data:image/' . $headerType . ';base64,' . $headerData;
}
 
if (file_exists($footerPath)) {
 
    $footerType = pathinfo($footerPath, PATHINFO_EXTENSION);
 
    $footerData = base64_encode(
        file_get_contents($footerPath)
    );
 
    $base64Footer =
        'data:image/' . $footerType . ';base64,' . $footerData;
}
/* =========================
   HTML PDF
========================= */
 
$eqfBlue = '#002b5c';
$eqfRed  = '#cf1020';
$lightBlue = '#9BC2E6';
 
$html = '
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    * { box-sizing: border-box; }
    
    @page {
        size: letter;
        margin: 1cm 1.5cm 1.5cm 1.5cm;
    }

    body {
        font-family: Arial, sans-serif;
        font-size: 11px;
        margin: 0;
        padding: 0;
    }

    /* 3. FOOTER POSICIONADO */
    .pdf-footer {
        position: fixed;
        bottom: -1.2cm; 
        left: 0;
        right: 0;
        height: 1.5cm;
        text-align: center;
    }

    .pdf-footer img {
        width: 100%;
        height: auto;
    }

    /* 1. ALINEACIÓN DE INFO (Prefijo y línea en la misma fila) */
    .info-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 5px;
    }

    .info-label {
        font-weight: bold;
        white-space: nowrap;
        padding-right: 5px;
        vertical-align: bottom;
        width: 1%; /* Forzar a que ocupe solo su texto */
    }

    .info-value {
        border-bottom: 1px solid #000;
        text-transform: uppercase;
        vertical-align: bottom;
        padding-left: 5px;
    }

    /* MAIN BOX */
    .main-box {
        border: 1.5px solid #000;
        width: 100%;
        margin-top: 0.3cm;
    }

    .content-area {
        padding: 0 10px;
    }

    .header-box {
        border-bottom: 2px solid #000;
    }

    .header-box img {
        width: 100%;
        display: block;
    }

    /* SECCIONES */
    .section-box {
        border-top: 1px solid #000;
        margin: 0;
        page-break-inside: avoid;
    }

    .section-header {
        background: ' . $lightBlue . ';
        font-weight: bold;
        padding: 5px 8px;
        border-bottom: 1px solid #000;
    }

    .section-body {
        padding: 7px 8px;
        min-height: 30px;
    }

    /* IMÁGENES */
    .images-table {
        width: 100%;
        border-collapse: collapse;
    }

    .images-table td {
        width: 25%;
        padding: 4px;
        text-align: center;
    }

    .image-wrapper {
        border: 1px solid #999;
        padding: 2px;
    }

    .image-wrapper img {
        width: 100%;
        max-height: 120px;
    }

    /* 2. FIRMAS (Fuera del recuadro y abajo) */
    .signatures-container {
        position: absolute;
        bottom: 1.8cm; /* Arriba del footer */
        width: 100%;
        left: 0;
    }

    .signatures-table {
        width: 100%;
        border-collapse: collapse;
    }

    .signatures-table td {
        width: 50%;
        text-align: center;
        padding: 0 40px;
    }

    .sign-line {
        border-top: 1px solid #000;
        margin-bottom: 5px;
    }

    .sign-title { font-weight: bold; font-size: 10px; }
    .sign-sub { font-size: 9px; color: #333; }

</style>
</head>
<body>

    <div class="pdf-footer">
        <img src="' . $base64Footer . '">
    </div>

    <div class="main-box">
        <div class="header-box">
            <img src="' . $base64Header . '">
        </div>

        <div class="content-area">
            <div style="padding: 10px 0;">
                <table class="info-table">
                    <tr>
                        <td class="info-label">Jefe de Sucursal:</td>
                        <td class="info-value">' . $solicitante . '</td>
                        <td class="info-label" style="padding-left:15px;">Fecha de solicitud:</td>
                        <td class="info-value" style="text-align:center; width:15%;">' . $fecha . '</td>
                    </tr>
                </table>
                <table class="info-table">
                    <tr>
                        <td class="info-label">Sucursal:</td>
                        <td class="info-value">' . $sucursal . '</td>
                    </tr>
                </table>
            </div>

            <div class="section-box">
                <div class="section-header">Descripción de la solicitud de mantenimiento</div>
                <div class="section-body">' . $descripcion . '</div>
            </div>

            <div class="section-box">
                <div class="section-header">Observaciones</div>
                <div class="section-body">
                    <table class="images-table">
                        ' . $imagesHtml . '
                    </table>
                </div>
            </div>

            <div class="section-box" style="border-bottom: none;">
                <div class="section-header">En caso de no autorizarse el mantenimiento describir el ¿Por qué?</div>
                <div class="section-body" style="height:50px;"></div>
            </div>
        </div>
    </div>

    <div class="signatures-container">
        <table class="signatures-table">
            <tr>
                <td>
                    <div class="sign-line"></div>
                    <div class="sign-title">Solicita</div>
                    <div class="sign-sub">(Fecha y firma)</div>
                </td>
                <td>
                    <div class="sign-line"></div>
                    <div class="sign-title">Revisó y Autorizó</div>
                    <div class="sign-sub">(Nombre, fecha y firma)</div>
                </td>
            </tr>
        </table>
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
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();
 
$dompdf->stream(
    'solicitud_mantenimiento_' . $id . '.pdf',
    ['Attachment' => false]
);