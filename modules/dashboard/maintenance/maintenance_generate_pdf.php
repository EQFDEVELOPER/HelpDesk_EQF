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
* {
    box-sizing: border-box;
}
 

/* ===== CONFIGURACIÓN DE PÁGINA ===== */
@page {
size: letter;
    margin: 1cm 1cm 1.5cm 1.5cm;

html, body {
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    font-size: 12px;
    margin: 0;
    padding: 0;
}

/* ===== FOOTER IMAGE ===== */
.pdf-footer {
 position: fixed;
    bottom: 0cm; /* Pegado al margen inferior de la página */
    left: 0;
    right: 0;
    height: 1.5cm;
    text-align: center;
}

.pdf-footer img {
    width: 100%;
    height: auto;
}

/* ===== SIGNATURES ===== */

.signatures {
    width: 100%;
    margin-top: 45px;
    border-collapse: collapse;
}

.signatures td {
    width: 50%;
    text-align: center;
    vertical-align: top;
    padding: 0 15px;
}

.sign-line {
    border-top: 1px solid #000;
    margin-bottom: 4px;
    height: 1px;
}

.sign-title {
    font-size: 10px;
    font-weight: bold;
}

.sign-sub {
    font-size: 9px;
    color: #333;
}

/* ===== MAIN BOX ===== */
.main-box {
   border: 1.5px solid #000;
    margin-top: 0.3cm;
    width: 100%; 
}

/* ===== CONTENIDO INTERNO ===== */
.content-area {
 padding-left: 10px;
    padding-right: 10px;
}

/* ===== HEADER BOX ===== */
.header-box {
    border-bottom: 2px solid #000;
}

.header-box img {
    width: auto;
    max-width: 100%;
    height: auto;
    display: block;
}
 
 
/* ===== SECTION BOXES ===== */
.section-box {
    border-top: 1px solid #000000ff;
    border-bottom: 1px solid #000000ff;
    border-left: 0;
    border-right: 0;
    margin: 0;
    page-break-inside: avoid;
    }

.section-box .section-header {
    background: ' . $lightBlue . ';
    font-weight: bold;
    font-size: 12px;
    padding: 5px 8px;
    border-bottom: 1px solid #333;
}

.section-box .section-body {
    padding: 7px 0;
    min-height: 30px;
    line-height: 1.4;
    font-size: 11px;
}


/* ===== INFO BOX ===== */
.info-box {
    padding: 12px 0;
    border-bottom: 1px solid #000;
}
.info-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
}

.info-table td {
    vertical-align: top;
    padding-bottom: 12px;
}

.info-label {
    font-weight: bold;
    white-space: nowrap;
}

.info-line {
    border-bottom: 1px solid #000;
    padding-left: 5px;
    text-transform: uppercase;
    height: 18px;
}

.images-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
    page-break-inside: auto;

}

.images-table td {
    width: 25%;
    padding: 4px;
    vertical-align: top;
    text-align: center;
}
.images-table tr {
    page-break-inside: avoid;
    page-break-after: auto;
}
.image-wrapper {
    border: 1px solid #999;
    padding: 3px;
}

.image-wrapper img {
    width: 100%;
    height: auto;
    max-height: 140px;
}

/* ===== FOOTER ===== */
.footer-legend {
    margin-top: 25px;
    text-align: center;
    font-size: 7.5px;
    color: #444;
    padding: 0 10px;
}

.footer-line {
    width: 100%;
    margin-top: 5px;
}

.footer-line svg {
    width: 100%;
    height: 10px;
    display: block;
}

</style>
</head>
<body>

<!-- FOOTER (Se repetirá abajo en todas las páginas) -->
<div class="pdf-footer">
    <img src="' . $base64Footer . '">
</div>



<div class="main-box">
    <div class="header-box">
        <img src="' . $base64Header . '">
    </div>

    <!-- ===== INFO BOX ===== -->

<div class="content-area">
    
<div class="info-box">

    <table class="info-table">
        <tr>
            <td width="65%">
                <span class="info-label">
                    Jefe de Sucursal:
                </span>

                <div class="info-line">
                    ' . $solicitante . '
                </div>
            </td>

            <td width="35%">
                <span class="info-label">
                    Fecha de solicitud:
                </span>

                <div class="info-line" style="text-align:center;">
                    ' . $fecha . '
                </div>
            </td>
        </tr>

        <tr>
            <td colspan="2">
                <span class="info-label">
                    Sucursal:
                </span>

                <div class="info-line">
                    ' . $sucursal . '
                </div>
            </td>
        </tr>
    </table>
    </div>
    </div>
<!-- ===== DESCRIPCIÓN ===== -->
<div class="section-box">
    <div class="section-header">Descripción de la solicitud de mantenimiento</div>
    <div class="section-body">' . $descripcion . '</div>
</div>

<!-- ===== OBSERVACIONES ===== -->
<div class="section-box">
    <div class="section-header">Observaciones</div>
    <div class="section-body">

        <table class="images-table">
            ' . $imagesHtml . '
        </table>

    </div>
</div>

<!-- ===== NO AUTORIZACIÓN ===== -->
<div class="section-box">
    <div class="section-header">
        En caso de no autorizarse el mantenimiento describir el ¿Por qué?
    </div>

    <div class="section-body" style="height:35px;"></div>
</div>
<!-- ===== FIRMAS ===== -->

<table class="signatures">
    <tr>

        <td>
            <div class="sign-line"></div>

            <div class="sign-title">
                Solicita
            </div>

            <div class="sign-sub">
                (Fecha y firma)
            </div>
        </td>

        <td>
            <div class="sign-line"></div>

            <div class="sign-title">
                Revisó y Autorizó
            </div>

            <div class="sign-sub">
                (Nombre, fecha y firma)
            </div>
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