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

/* ==========================================================================
   1. CONSULTA UNIFICADA: SOLICITUD + FINALIZACIÓN + FEEDBACK
   ========================================================================== */
$stmt = $conn->prepare("
    SELECT
        mr.*,
        CONCAT(u.name, ' ', u.last_name) AS full_name,
        mc.performed_by,
        mc.external_company,
        mc.maintenance_type,
        mc.activities,
        mc.material_description,
        mc.staff_json,
        mc.completed_at,
        mf.comment AS client_comment,
        mf.q1_schedule,
        mf.q2_attention,
        mf.q3_resolution,
        mf.q4_productivity,
        mf.q5_service
    FROM maintenance_requests mr
    LEFT JOIN users u ON u.id = mr.requester_user_id
    LEFT JOIN maintenance_completion mc ON mc.maintenance_request_id = mr.id
    LEFT JOIN maintenance_feedback mf ON mf.maintenance_request_id = mr.id
    WHERE mr.id = ?
    LIMIT 1
");

$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    die('Solicitud no encontrada');
}
/* ==========================================================================
   2. PROCESAMIENTO DE VARIABLES Y FORMATOS (Acreedor + Actividades)
   ========================================================================== */
$email = $request['requester_email'] ?? '';
$sucursal = strtoupper(explode('@', $email)[0]);

// Fecha en la que se generó/cerró el reporte (si no, usa la de creación)
$fechaReporte = !empty($request['completed_at']) 
    ? date('d/m/Y', strtotime($request['completed_at'])) 
    : date('d/m/Y', strtotime($request['created_at']));

$codigoSolicitud = $request['id'];
$tipoMantenimiento = strtoupper($request['maintenance_type'] ?? 'PREVENTIVO');

// Lógica de Acreedor (VSP o Empresa Externa)
$acreedor = ($request['performed_by'] === 'VSP') ? 'VSP' : strtoupper($request['external_company'] ?? 'N/A');
$textoActividades = !empty($request['activities']) ? $request['activities'] : $request['description'];

// Estructuración solicitada en el cuadro
$contenidoActividadesHtml = '<strong>Acreedor:</strong> ' . htmlspecialchars($acreedor) . '<br><br>';
$contenidoActividadesHtml .= '<strong>Actividades:</strong><br>';
$contenidoActividadesHtml .= nl2br(htmlspecialchars($textoActividades));


// Comentarios del cliente limpios (si está vacío, dejamos renglones en blanco)
$comentariosCliente = !empty($request['client_comment']) 
    ? nl2br(htmlspecialchars($request['client_comment'])) 
    : '&nbsp;';

/**
 * Función auxiliar para pintar 'X' en la celda correspondiente
 * @param int|null $score Valor guardado en la BD (4=Excelente, 3=Bueno, 2=Regular, 1=Inaceptable)
 * @param int $target El casillero que estamos evaluando
 */
function checkCell($score, $target) {
    if ($score !== null && (int)$score === $target) {
        return 'X';
    }
    return '&nbsp;';
}


/* ==========================================================================
   3. PROCESAMIENTO DINÁMICO DE MATERIALES (Múltiples Filas)
   ========================================================================== */
$materialsHtml = '';

// Extraemos el texto JSON guardado en la columna de la BD
$materialsJson = $request['material_description'] ?? '[]';

// Lo decodificamos para convertirlo en un array de PHP
$materialsArray = json_decode($materialsJson, true);

// Tu formato institucional tiene exactamente 8 renglones en esa sección
$totalRowsTarget = 8; 
$rowCount = 0;

// Si el JSON es válido y contiene materiales guardados, los recorremos
if (is_array($materialsArray) && !empty($materialsArray) && $materialsJson !== '[]') {
    foreach ($materialsArray as $mat) {
        $materialsHtml .= '
        <tr>
            <td class="cell-center" style="width: 12%; text-align: center;">' . htmlspecialchars($mat['qty']) . '</td>
            <td class="cell-center" style="width: 18%; text-align: center;">' . htmlspecialchars($mat['unit']) . '</td>
            <td style="width: 70%; text-transform: uppercase;">' . htmlspecialchars($mat['desc']) . '</td>
        </tr>';
        $rowCount++;
    }
}

// Control de Cuadrícula Fija: Si se registraron menos de 8 materiales,
// rellenamos con filas vacías con un espacio en blanco (&nbsp;) para que mantenga el diseño original
while ($rowCount < $totalRowsTarget) {
    $materialsHtml .= '
    <tr>
        <td style="width: 12%; text-align: center;">&nbsp;</td>
        <td style="width: 18%; text-align: center;">&nbsp;</td>
        <td style="width: 70%;">&nbsp;</td>
    </tr>';
    $rowCount++;
}
/* ==========================================================================
   4. PROCESAMIENTO DINÁMICO DE PERSONAL TÉCNICO
   ========================================================================== */
$staffHtml = '';
$staffJson = $request['staff_json'] ?? '[]';
$staffArray = json_decode($staffJson, true);
$staffCount = 0;

if (is_array($staffArray) && !empty($staffArray)) {
    foreach ($staffArray as $person) {
        $staffHtml .= '
        <tr>
            <td style="width:30%; font-weight: bold; text-transform: uppercase;">' . htmlspecialchars($person['position']) . '</td>
            <td style="width:70%; text-transform: uppercase;">' . htmlspecialchars($person['name']) . '</td>
        </tr>';
        $staffCount++;
    }
}

while ($staffCount < 3) {
    $staffHtml .= '
    <tr>
        <td style="width:30%;">&nbsp;</td>
        <td style="width:70%">&nbsp;</td>
    </tr>';
    $staffCount++;
}

/* ==========================================================================
   5. IMÁGENES CORPORATIVAS (HEADER / FOOTER EN BASE64)
   ========================================================================== */
$headerPath = $_SERVER['DOCUMENT_ROOT'] . '/HelpDesk_EQF/assets/img/header_mantenimiento.png';
$footerPath = $_SERVER['DOCUMENT_ROOT'] . '/HelpDesk_EQF/assets/img/footer_mantenimiento.png';
$base64Header = ''; $base64Footer = '';

if (file_exists($headerPath)) {
    $base64Header = 'data:image/' . pathinfo($headerPath, PATHINFO_EXTENSION) . ';base64,' . base64_encode(file_get_contents($headerPath));
}
if (file_exists($footerPath)) {
    $base64Footer = 'data:image/' . pathinfo($footerPath, PATHINFO_EXTENSION) . ';base64,' . base64_encode(file_get_contents($footerPath));
}

/* ==========================================================================
   6. HTML / CSS INTEGRADO COMPATIBLE CON DOMPDF
   ========================================================================== */
$lightBlue = '#D9E1F2'; 

$html = '
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    * { box-sizing: border-box; }
    @page { size: letter; margin: 0.8cm 1cm 1.5cm 1cm; }
    body { font-family: Arial, sans-serif; font-size: 10px; margin: 0; padding: 0; }
    
    .main-container { width: 100%; border: 2px solid #000; }
    .header-img img { width: 100%; display: block; border-bottom: 2px solid #000; }
    
    table { width: 100%; border-collapse: collapse; margin: 0; }
    th, td { border: 1px solid #000; padding: 4px 6px; vertical-align: middle; }
    
    .bg-title { background-color: ' . $lightBlue . '; font-weight: bold; text-align: center; font-size: 11px; }
    .font-bold { font-weight: bold; }
    .cell-center { text-align: center; }
    
    .meta-table td { padding: 3px 6px; }
    .checkbox-box { width: 12px; height: 12px; border: 1px solid #000; display: inline-block; text-align: center; line-height: 10px; font-weight: bold; font-size: 9px; }
    
    .section-comment { height: 45px; }
    
    /* Footer Fijo */
    .footer-container { position: absolute; bottom: -35px; width: 100%; left: 0; }
    .footer-img img { width: 100%; height: auto; display: block; }
</style>
</head>
<body>

<div class="main-container">
    <div class="header-img">
        <img src="' . $base64Header . '">
    </div>

    <table class="meta-table" style="border-top:none; border-left:none; border-right:none;">
        <tr>
            <td class="font-bold" style="width: 20%; background-color: ' . $lightBlue . '; text-align:center;">Tipo de mantenimiento:</td>
            <td style="width: 15%;">Preventivo</td>
            <td class="cell-center" style="width: 8%;">' . ($tipoMantenimiento === 'PREVENTIVO' ? '<span class="checkbox-box">X</span>' : '<span class="checkbox-box"></span>') . '</td>
            <td rowspan="2" style="width: 14%; border-bottom:none; border-top:none;"></td>
            <td class="font-bold" style="width: 10%; background-color: ' . $lightBlue . ';">Sucursal</td>
            <td style="width: 15%; text-transform: uppercase;">' . $sucursal . '</td>
            <td class="font-bold" style="width: 8%; background-color: ' . $lightBlue . ';">Código</td>
            <td class="font-bold cell-center" style="width: 10%; font-size:11px;"> MTTO - ' . $codigoSolicitud . '</td>
        </tr>
        <tr>
            <td class="font-bold" style="background-color: ' . $lightBlue . '; text-align:center;"></td>
            <td>Correctivo</td>
            <td class="cell-center">' . ($tipoMantenimiento === 'CORRECTIVO' ? '<span class="checkbox-box">X</span>' : '<span class="checkbox-box"></span>') . '</td>
            <td class="font-bold" style="background-color: ' . $lightBlue . ';">Fecha</td>
            <td style="text-align:center;">' . $fechaReporte . '</td>
            <td colspan="2" style="background-color:#EFEFEF;"></td>
        </tr>
    </table>

    <table>
        <tr>
            <td class="bg-title">Actividades realizadas</td>
        </tr>
        <tr>
            <td style="height: 160px; vertical-align: top; padding: 10px; line-height: 1.4; font-size: 10.5px;">
                ' . $contenidoActividadesHtml . '
            </td>
        </tr>
    </table>

<table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td colspan="3" class="bg-title" style="background-color: #D9E1F2; font-weight: bold; text-align: center; font-size: 11px; border: 1px solid #000;">ADQUISICIÓN DE MATERIAL</td>
        </tr>
        <tr style="background-color: #F2F2F2; font-weight: bold; text-align: center;">
            <td style="width: 12%; border: 1px solid #000; padding: 4px;">Cantidad</td>
            <td style="width: 18%; border: 1px solid #000; padding: 4px;">Unidad</td>
            <td style="width: 70%; border: 1px solid #000; padding: 4px;">Descripción del material o equipo</td>
        </tr>
        ' . $materialsHtml . '
    </table>

    <table>
        <tr>
            <td class="bg-title">COMENTARIOS DEL CLIENTE</td>
        </tr>
        <tr>
            <td style="height: 45px; vertical-align: top; padding: 6px; line-height: 1.3; font-size: 10px;">
                ' . $comentariosCliente . '
            </td>
        </tr>
    </table>

    <table>
        <tr>
            <td style="width: 60%; font-weight:bold; text-align:center; background-color: ' . $lightBlue . ';">Calidad del servicio</td>
            <td style="width: 10%; font-weight:bold; text-align:center; background-color: ' . $lightBlue . ';">EXCELENTE</td>
            <td style="width: 10%; font-weight:bold; text-align:center; background-color: ' . $lightBlue . ';">BUENO</td>
            <td style="width: 10%; font-weight:bold; text-align:center; background-color: ' . $lightBlue . ';">REGULAR</td>
            <td style="width: 10%; font-weight:bold; text-align:center; background-color: ' . $lightBlue . ';">INACEPTABLE</td>
        </tr>
        <tr>
            <td>Cumple con el horario acordado</td>
            <td class="cell-center">' . checkCell($request['q1_schedule'], 4) . '</td>
            <td class="cell-center">' . checkCell($request['q1_schedule'], 3) . '</td>
            <td class="cell-center">' . checkCell($request['q1_schedule'], 2) . '</td>
            <td class="cell-center">' . checkCell($request['q1_schedule'], 1) . '</td>
        </tr>
        <tr>
            <td>La atención de las solicitudes es rápida y oportuna</td>
            <td class="cell-center">' . checkCell($request['q2_attention'], 4) . '</td>
            <td class="cell-center">' . checkCell($request['q2_attention'], 3) . '</td>
            <td class="cell-center">' . checkCell($request['q2_attention'], 2) . '</td>
            <td class="cell-center">' . checkCell($request['q2_attention'], 1) . '</td>
        </tr>
        <tr>
            <td>Detecta y corrije los problemas de forma oportuna</td>
            <td class="cell-center">' . checkCell($request['q3_resolution'], 4) . '</td>
            <td class="cell-center">' . checkCell($request['q3_resolution'], 3) . '</td>
            <td class="cell-center">' . checkCell($request['q3_resolution'], 2) . '</td>
            <td class="cell-center">' . checkCell($request['q3_resolution'], 1) . '</td>
        </tr>
        <tr>
            <td>Productividad y eficiencia en el desarrollo de trabajos</td>
            <td class="cell-center">' . checkCell($request['q4_productivity'], 4) . '</td>
            <td class="cell-center">' . checkCell($request['q4_productivity'], 3) . '</td>
            <td class="cell-center">' . checkCell($request['q4_productivity'], 2) . '</td>
            <td class="cell-center">' . checkCell($request['q4_productivity'], 1) . '</td>
        </tr>
        <tr>
            <td>Actitud cooperativa y disposición de servicio</td>
            <td class="cell-center">' . checkCell($request['q5_service'], 4) . '</td>
            <td class="cell-center">' . checkCell($request['q5_service'], 3) . '</td>
            <td class="cell-center">' . checkCell($request['q5_service'], 2) . '</td>
            <td class="cell-center">' . checkCell($request['q5_service'], 1) . '</td>
        </tr>
    </table>

    <table>
        <tr>
            <td colspan="2" class="bg-title">Personal de mantenimiento</td>
        </tr>
        <tr style="background-color: #F2F2F2; font-weight:bold;">
            <td style="width: 30%;">Puesto</td>
            <td style="width: 70%;">Nombre</td>
        </tr>
        ' . $staffHtml . '
    </table>
</div>

<div class="footer-container">
    <div class="footer-img">
        <img src="' . $base64Footer . '">
    </div>
</div>

</body>
</html>
';

/* ==========================================================================
   7. RENDERIZACIÓN DE DOMPDF
   ========================================================================== */
$options = new Options();
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

// Marca la solicitud como procesada en PDF
$stmtPdf = $conn->prepare("UPDATE maintenance_requests SET pdf_generated = 1 WHERE id = ?");
$stmtPdf->execute([$id]);

$dompdf->stream(
    'reporte_mantenimiento_EQF_' . $codigoSolicitud . '.pdf',
    ['Attachment' => false]
);