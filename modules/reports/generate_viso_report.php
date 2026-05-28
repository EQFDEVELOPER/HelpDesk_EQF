<?php
session_start();

require_once __DIR__ . '/../../config/connectionBD.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

if (!isset($_SESSION['user_id']) || (int)($_SESSION['user_rol'] ?? 0) !== 2) {
    die('No autorizado');
}

$pdo = Database::getConnection();

// Usamos la variable original de este archivo: $area
$area = trim($_SESSION['user_area'] ?? '');

/*
|--------------------------------------------------------------------------
| FILTROS DESDE LA URL (GET)
|--------------------------------------------------------------------------
*/
$estado       = $_GET['estado'] ?? 'todos';
$prioridad    = $_GET['prioridad'] ?? 'todas';
$sinAsignar   = ($_GET['sin_asignar'] ?? '0') === '1';
$fechaInicio  = trim($_GET['desde'] ?? '');
$fechaFin     = trim($_GET['hasta'] ?? '');

/*
|--------------------------------------------------------------------------
| QUERY CORREGIDO (Sin mezclas y con sintaxis segura)
|--------------------------------------------------------------------------
*/
$sql = "
SELECT
    t.id,
    t.email,
    t.equipo_area,
    t.prioridad,
    t.fecha_envio,
    t.fecha_resolucion,
    t.estado,
    t.descripcion,
    CONCAT(COALESCE(u.name, ''), ' ', COALESCE(u.last_name, '')) AS realizado_por,
    (
        SELECT tm.mensaje 
        FROM ticket_messages tm 
        WHERE tm.ticket_id = t.id AND tm.is_internal = 1 
        ORDER BY tm.created_at DESC 
        LIMIT 1
    ) AS mensaje_interno
FROM tickets t
LEFT JOIN users u ON t.asignado_a = u.id
WHERE 1=1 
  AND t.area = :areaX
";

$params = [':areaX' => $area];

/*
|--------------------------------------------------------------------------
| CONDICIONALES DE FILTROS (Con espacios de seguridad para evitar Error 1064)
|--------------------------------------------------------------------------
*/
if ($estado !== 'todos') {
    $sql .= " AND t.estado = :estado ";
    $params[':estado'] = $estado;
}

if ($prioridad !== 'todas') {
    $sql .= " AND t.prioridad = :prioridad ";
    $params[':prioridad'] = $prioridad;
}

if ($sinAsignar) {
    $sql .= " AND (t.asignado_a IS NULL OR t.asignado_a = 0) ";
}

if ($fechaInicio !== '') {
    $sql .= " AND DATE(t.fecha_envio) >= :fecha_inicio ";
    $params[':fecha_inicio'] = $fechaInicio;
}

if ($fechaFin !== '') {
    $sql .= " AND DATE(t.fecha_envio) <= :fecha_fin ";
    $params[':fecha_fin'] = $fechaFin;
}

// Ordenamiento único al final de la consulta
$sql .= " ORDER BY t.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| EXCEL (Spreadsheet)
|--------------------------------------------------------------------------
*/
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Reporte VISO');

$blueColor = '1A448E'; // Azul institucional exacto
$redColor  = 'C00000';
$white     = 'FFFFFF';

/*
|--------------------------------------------------------------------------
| TITULO SUPERIOR
|--------------------------------------------------------------------------
*/
$sheet->mergeCells('A1:J1');
$sheet->setCellValue('A1', 'CONTROL DE INCIDENCIAS TI');

$sheet->getStyle('A1')->applyFromArray([
    'font' => [
        'bold'  => true,
        'size'  => 18,
        'color' => ['rgb' => $white]
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => $blueColor]
    ]
]);
// Se sube a 55 de alto para dar holgura vertical a las dimensiones solicitadas
$sheet->getRowDimension(1)->setRowHeight(55);

/*
|--------------------------------------------------------------------------
| RENDERIZADO DE LOGOS (IZQUIERDO Y DERECHO) CON MEDIDAS EXACTAS
| Medidas: H: 1.5 cm (~57px) | W: 4.8 cm (~181px)
|--------------------------------------------------------------------------
*/
$logoPath = __DIR__ . '/../../assets/img/logo-blanco.png';
if (file_exists($logoPath)) {
    // Logo Izquierdo (Celda A1)
    $drawingLeft = new Drawing();
    $drawingLeft->setName('Logo EQF Izquierdo');
    $drawingLeft->setDescription('Logo Blanco Institucional');
    $drawingLeft->setPath($logoPath);
    
    $drawingLeft->setResizeProportional(false);
    $drawingLeft->setHeight(57);  // 1.5 cm
    $drawingLeft->setWidth(181);  // 4.8 cm
    
    $drawingLeft->setCoordinates('A1');
    $drawingLeft->setOffsetX(15);
    $drawingLeft->setOffsetY(8);
    $drawingLeft->setWorksheet($sheet);

    // Logo Derecho (Celda J1)
    $drawingRight = new Drawing();
    $drawingRight->setName('Logo EQF Derecho');
    $drawingRight->setDescription('Logo Blanco Institucional');
    $drawingRight->setPath($logoPath);
    
    $drawingRight->setResizeProportional(false);
    $drawingRight->setHeight(57); // 1.5 cm
    $drawingRight->setWidth(181); // 4.8 cm
    
    $drawingRight->setCoordinates('J1');
    $drawingRight->setOffsetX(340); // Ajustado para centrarlo estéticamente en la col J
    $drawingRight->setOffsetY(8);
    $drawingRight->setWorksheet($sheet);
}

/*
|--------------------------------------------------------------------------
| ENCABEZADOS DE LA TABLA
|--------------------------------------------------------------------------
*/
$headers = [
    'A2' => 'TICKET #',
    'B2' => 'SUCURSAL / AREA',
    'C2' => 'EQUIPO DE',
    'D2' => 'PRIORIDAD',
    'E2' => 'FECHA Y HORA DE ENTRADA',
    'F2' => 'FECHA Y HORA DE RESOLUCION',
    'G2' => 'TIEMPO DE SOLUCION',
    'H2' => 'REALIZADO POR',
    'I2' => 'ESTADO',
    'J2' => 'DESCRIPCION Y SOLUCION DEL PROBLEMA'
];

foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}

$sheet->getStyle('A2:J2')->applyFromArray([
    'font' => [
        'bold'  => true,
        'size'  => 11,
        'color' => ['rgb' => $white]
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER,
        'wrapText'   => true
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => $redColor]
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN
        ]
    ]
]);
$sheet->getRowDimension(2)->setRowHeight(35);

/*
|--------------------------------------------------------------------------
| PROCESAMIENTO DE FILAS
|--------------------------------------------------------------------------
*/
$rowNum = 3;

foreach ($rows as $r) {

    // 1. SUCURSAL / AREA (Quitar lo que esté después del @ y pasar a Mayúsculas)
    $sucursal = '';
    if (!empty($r['email'])) {
        $correo = trim((string)$r['email']);
        $partes = explode('@', $correo);
        $sucursal = strtoupper($partes[0] ?? '');
    }

    // 2. CALCULAR TIEMPO DE SOLUCION
    $tiempo = '';
    if (!empty($r['fecha_envio']) && !empty($r['fecha_resolucion'])) {
        $inicio = new DateTime($r['fecha_envio']);
        $fin    = new DateTime($r['fecha_resolucion']);
        $diff   = $inicio->diff($fin);

        $partesTiempo = [];
        if ($diff->days > 0) { $partesTiempo[] = $diff->days . ' día(s)'; }
        if ($diff->h > 0)    { $partesTiempo[] = $diff->h . ' hora(s)'; }
        if ($diff->i > 0)    { $partesTiempo[] = $diff->i . ' minuto(s)'; }
        
        $tiempo = (!empty($partesTiempo)) ? implode(', ', $partesTiempo) : '0 minutos';
    } else {
        $tiempo = 'En proceso / Sin resolver';
    }

    // 3. CONSULTA TRIPLE (P. descripción + Salto de línea + S. mensaje interno de ticket_messages)
    $detalle = "P. " . trim((string)$r['descripcion']);
    if (!empty($r['mensaje_interno'])) {
        $detalle .= "\n\nS. " . trim((string)$r['mensaje_interno']);
    }

    // 4. SETEAR VALORES EN CELDAS
    $sheet->setCellValue('A' . $rowNum, $r['id']);
    $sheet->setCellValue('B' . $rowNum, $sucursal);
    $sheet->setCellValue('C' . $rowNum, $r['equipo_area']);
    $sheet->setCellValue('D' . $rowNum, strtoupper((string)$r['prioridad']));
    $sheet->setCellValue('E' . $rowNum, $r['fecha_envio']);
    $sheet->setCellValue('F' . $rowNum, $r['fecha_resolucion'] ?? '—');
    $sheet->setCellValue('G' . $rowNum, $tiempo);
    $sheet->setCellValue('H' . $rowNum, !empty(trim($r['realizado_por'])) ? trim($r['realizado_por']) : 'Sin Asignar');
    $sheet->setCellValue('I' . $rowNum, strtoupper((string)$r['estado']));
    $sheet->setCellValue('J' . $rowNum, $detalle);

    // Estilos de la fila actual
    $sheet->getStyle("A{$rowNum}:J{$rowNum}")->applyFromArray([
        'alignment' => [
            'vertical' => Alignment::VERTICAL_TOP,
            'wrapText' => true
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN
            ]
        ]
    ]);

    $sheet->getRowDimension($rowNum)->setRowHeight(75);
    $rowNum++;
}

/*
|--------------------------------------------------------------------------
| FORMATOS DE COLUMNAS
|--------------------------------------------------------------------------
*/
$sheet->getColumnDimension('A')->setWidth(12);
$sheet->getColumnDimension('B')->setWidth(28);
$sheet->getColumnDimension('C')->setWidth(22);
$sheet->getColumnDimension('D')->setWidth(15);
$sheet->getColumnDimension('E')->setWidth(25);
$sheet->getColumnDimension('F')->setWidth(25);
$sheet->getColumnDimension('G')->setWidth(28);
$sheet->getColumnDimension('H')->setWidth(30);
$sheet->getColumnDimension('I')->setWidth(18);
$sheet->getColumnDimension('J')->setWidth(75);

// Centrar columnas A hasta I
if ($rowNum > 3) {
    $sheet->getStyle('A3:I'.($rowNum-1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A3:I'.($rowNum-1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    
    // Dejar columna J alineada a la izquierda (por los saltos de línea y texto largo)
    $sheet->getStyle('J3:J'.($rowNum-1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
}

$sheet->setAutoFilter('A2:J2');
$sheet->freezePane('A3');

/*
|--------------------------------------------------------------------------
| CARPETA Y GUARDADO: uploads/reportsVISO
|--------------------------------------------------------------------------
*/
$dir = __DIR__ . '/../../uploads/reportsVISO/';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$fileName = 'REPORTE_VISO_' . date('Ymd_His') . '.xlsx';
$fullPath = $dir . $fileName;

$writer = new Xlsx($spreadsheet);
$writer->save($fullPath);

/*
|--------------------------------------------------------------------------
| DESCARGA DIRECTA
|--------------------------------------------------------------------------
*/
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

readfile($fullPath);
exit;