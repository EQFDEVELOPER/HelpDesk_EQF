<?php
session_start();
require_once __DIR__ . '/../../config/connectionBD.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/* ====== DEBUG (temporal) ======
   Si vuelve a fallar, te mostrará el error real.
   Cuando ya funcione, puedes comentar estas 2 líneas.
*/
ini_set('display_errors', '1');
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
  header('Location:/HelpDesk_EQF/auth/login.php');
  exit;
}

$pdo = Database::getConnection();
$ticketId = (int)($_GET['id'] ?? 0);
if ($ticketId <= 0) {
  http_response_code(400);
  exit('ID inválido');
}

/* ========= helpers ========= */
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fmtDate($dt, string $fallback = '—'): string {
  if (!$dt) return $fallback;
  $ts = strtotime((string)$dt);
  if (!$ts) return $fallback;
  return date('d/m/Y', $ts);
}

function imgDataUri(string $absPath): string {
  if (!is_file($absPath)) return '';
  $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
  $mime = match ($ext) {
    'png' => 'image/png',
    'jpg','jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    default => 'application/octet-stream'
  };
  $data = base64_encode((string)file_get_contents($absPath));
  return "data:$mime;base64,$data";
}

function emailUser(string $email): string {
  $email = trim($email);
  if ($email === '') return '—';
  $pos = strpos($email, '@');
  return ($pos === false) ? $email : substr($email, 0, $pos);
}

/**
 * ✅ NO usa mbstring
 * israel.rico -> Israel Rico
 */
function emailPretty(string $email): string {
  $u = emailUser($email);
  if ($u === '—') return '—';
  $u = str_replace(['.', '_', '-'], ' ', $u);
  $u = preg_replace('/\s+/', ' ', $u);
  $u = trim($u);
  if ($u === '') return '—';
  $u = strtolower($u);
  return ucwords($u);
}

// problema (por si viene como código o como número)
function problemaHuman($p): string {
  if ($p === null || $p === '') return '—';
  if (is_numeric($p)) {
    $id = (int)$p;
    return match ($id) {
      1 => 'Cierre del día',
      2 => 'Sin acceso a legado/legacy',
      3 => 'Sin internet',
      4 => 'No funciona checador',
      5 => 'Rastreo de checada',
      default => (string)$p,
    };
  }
  $p = (string)$p;
  return match ($p) {
    'cierre_dia'   => 'Cierre del día',
    'no_legado'    => 'Sin acceso a legado/legacy',
    'no_internet'  => 'Sin internet',
    'no_checador'  => 'No funciona checador',
    'rastreo'      => 'Rastreo de checada',
    'otro'         => 'Otro',
    default        => $p,
  };
}

try {
  /* ========= traer ticket + usuario + analista ========= */
  $stmt = $pdo->prepare("
    SELECT
      t.*,
      u.email AS requester_email,
      CONCAT(a.name,' ',a.last_name) AS analyst_name
    FROM tickets t
    LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN users a ON a.id = t.asignado_a
    WHERE t.id = ?
    LIMIT 1
  ");
  $stmt->execute([$ticketId]);
  $t = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$t) {
    http_response_code(404);
    exit('Ticket no encontrado');
  }

  /* ========= datos para PDF ========= */
  $fechaReporte = date('d/m/Y');

  $area = $t['area'] ?? ($t['user_area'] ?? ($_SESSION['user_area'] ?? '—'));

  $asignadoPor = emailPretty((string)($t['requester_email'] ?? ''));

  $analista = trim((string)($t['analyst_name'] ?? ''));
  if ($analista === '') $analista = '—';

  $problemaTxt = problemaHuman($t['problema'] ?? '');
  $prioridad   = $t['prioridad'] ?? ($t['priority'] ?? ($t['priority_id'] ?? '—'));

  $fechaEnvio  = $t['fecha_envio'] ?? null;
  $fechaRes    = $t['fecha_resolucion'] ?? null;

  // tiempo en minutos
  $minsTxt = '0';
  if (!empty($fechaEnvio) && !empty($fechaRes)) {
    $stmtD = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, ?, ?) AS m");
    $stmtD->execute([$fechaEnvio, $fechaRes]);
    $minsTxt = (string)((int)($stmtD->fetchColumn() ?? 0));
  }

  $descripcion = (string)($t['descripcion'] ?? '');

  /* ========= imágenes ========= */
  $projectRoot = realpath(__DIR__ . '/..'); // /modules
  $projectRoot = realpath($projectRoot . '/..'); // /HelpDesk_EQF

  $logoPath = $projectRoot . '/assets/img/Logo-334x98.png';
  $logoData = imgDataUri($logoPath);

  /* ========= HTML (FORMATO TAREAS) ========= */
  $year = (int)date('Y');

  $html = '
  <!doctype html>
  <html lang="es">
  <head>
  <meta charset="utf-8">
  <style>
    @page { margin: 20px 26px; }
    body{ font-family: Arial, Helvetica, sans-serif; font-size: 12px; color:#111; }

    .topbar{ width:100%; }
    .logo{ text-align:right; }
    .logo img{ height:42px; }

    .meta-row{ margin-top: 8px; display:flex; justify-content: space-between; align-items:flex-start; }
    .meta-left{ font-size:12px; }
    .meta-right{ text-align:right; font-size:12px; }
    .meta-label{ font-weight:700; }
    .red-line{ height:2px; background:#cf1020; margin:10px 0 14px; }

    .section-title{ font-weight:800; font-size:13px; margin: 0 0 8px; }

    table{ width:100%; border-collapse:collapse; table-layout:fixed; }
    th{
      background:#123a8c; color:#fff; font-weight:800;
      padding:8px 6px; border:1px solid #123a8c; text-align:center; font-size:12px;
    }
    td{ border:1px solid #777; padding:8px 6px; vertical-align:top; font-size:12px; }

    .desc-box{ border:1px solid #777; padding:8px 6px; margin-top:-1px; }
    .desc-label{ font-weight:800; margin-bottom:4px; }
    .desc-text{ white-space:pre-wrap; }

    .grid2{ margin-top: 14px; display:flex; gap:14px; }
    .col{ flex:1; border:1px solid #ddd; padding:10px; min-height:120px; }
    .col-title{ font-weight:800; margin-bottom:6px; }
    .divider-red{ width:2px; background:#cf1020; margin:0 0; }

    /* Firmas: tabla estable en Dompdf */
    .sign-table{ width:100%; border-collapse:collapse; margin-top:190px; }
    .sign-table td{ width:50%; text-align:center; vertical-align:top; border:none; padding:0 30px; }
    .sign-line{ border-top:1px solid #222; width:80%; margin:0 auto 6px; }
    .sign-name{ font-weight:800; font-size:11px; }
    .sign-role{ font-size:10px; color:#444; }

    .footer{
      position:fixed; bottom: 14px; left: 26px; right: 26px;
      text-align:center; font-size:9px; color:#777;
    }
    .footer b{ color:#555; }
  </style>
  </head>
  <body>

    <div class="topbar">
      <div class="logo">'.($logoData ? '<img src="'.$logoData.'" />' : '<b>Equilibrio Farmacéutico</b>').'</div>

      <div class="meta-row">
        <div class="meta-left">
          <span class="meta-label">Área:</span> '.h($area).' &nbsp;&nbsp;|&nbsp;&nbsp;
          <span class="meta-label">Fecha de reporte:</span> '.h($fechaReporte).'
        </div>
        <div class="meta-right">
          <div><span class="meta-label">Asignado por:</span> '.h($asignadoPor).'</div>
          <div><span class="meta-label">Analista:</span> '.h($analista).'</div>
        </div>
      </div>

      <div class="red-line"></div>
    </div>

    <div class="section-title">Detalle del ticket</div>

    <table>
      <thead>
        <tr>
          <th style="width:24%;">Ticket</th>
          <th style="width:16%;">Prioridad</th>
          <th style="width:20%;">Fecha de envío</th>
          <th style="width:20%;">Fecha resuelta</th>
          <th style="width:20%;">Tiempo (min)</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>'.h('#'.$ticketId.' - '.$problemaTxt).'</td>
          <td style="text-align:center;">'.h($prioridad).'</td>
          <td style="text-align:center;">'.h(fmtDate($fechaEnvio)).'</td>
          <td style="text-align:center;">'.h(fmtDate($fechaRes)).'</td>
          <td style="text-align:center;">'.h($minsTxt).'</td>
        </tr>
      </tbody>
    </table>

    <div class="desc-box">
      <div class="desc-label">Descripción:</div>
      <div class="desc-text">'.nl2br(h($descripcion)).'</div>
    </div>

    <div class="grid2">
      <div class="col">
        <div class="col-title">Observaciones y/o Notas</div>
      </div>

      <div class="divider-red"></div>

      <div class="col">
        <div class="col-title">Tiempos</div>
        <div style="color:#777; font-size:11px;">Gráfica (Power BI pendiente)</div>
      </div>
    </div>

    <table class="sign-table">
      <tr>
        <td>
          <div class="sign-line"></div>
          <div class="sign-name">'.h($asignadoPor).'</div>
          <div class="sign-role">Sucursal</div>
        </td>
        <td>
          <div class="sign-line"></div>
          <div class="sign-name">'.h($analista).'</div>
          <div class="sign-role">Analista</div>
        </td>
      </tr>
    </table>

    <div class="footer">
      <div>REPORTE GENERADO AUTOMÁTICAMENTE POR EL SISTEMA HELPDESK EQF</div>
      <div><b>TODOS LOS DERECHOS RESERVADOS ©'.$year.' EQUILIBRIO FARMACÉUTICO</b></div>
    </div>

  </body>
  </html>
  ';

  /* ========= Dompdf ========= */
  $options = new Options();
  $options->set('isRemoteEnabled', true);
  $options->set('isHtml5ParserEnabled', true);

  $dompdf = new Dompdf($options);
  $dompdf->loadHtml($html);
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();
  $dompdf->stream("ticket_{$ticketId}.pdf", ["Attachment" => false]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo "Error PDF: " . $e->getMessage();
  exit;
}
