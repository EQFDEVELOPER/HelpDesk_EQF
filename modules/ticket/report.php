<?php
session_start();
require_once __DIR__ . '/../../config/connectionBD.php';

// ==============================
//  CARGAR DOMPDF (Composer o ZIP)
// ==============================
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    // Si usaste composer require dompdf/dompdf
    require_once __DIR__ . '/../../vendor/autoload.php';
} else {
    // Si bajaste dompdf como ZIP a vendor/dompdf
    require_once __DIR__ . '/../../vendor/dompdf/autoload.inc.php';
}

use Dompdf\Dompdf;
use Dompdf\Options;

// ==============================
//  VALIDAR REQUEST Y SESIÓN
// ==============================
if (!isset($_SESSION['user_id'])) {
    header('Location: /HelpDesk_EQF/auth/login.php');
    exit;
}

$ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
if ($ticketId <= 0) {
    die('Ticket inválido');
}

$userId = (int)$_SESSION['user_id'];
$rol    = (int)($_SESSION['user_rol'] ?? 0);

$pdo = Database::getConnection();

// ==============================
//  OBTENER TICKET Y VALIDAR PERMISO
// ==============================
$stmtTicket = $pdo->prepare("
    SELECT 
        t.*,
        u.email AS usuario_email_real,
        u.area  AS usuario_area_real,
        ua.name      AS analista_name,
        ua.last_name AS analista_last,
        ua.email     AS analista_email,
        ua.area      AS analista_area
    FROM tickets t
    LEFT JOIN users u  ON u.id  = t.user_id
    LEFT JOIN users ua ON ua.id = t.asignado_a
    WHERE t.id = :id
    LIMIT 1
");
$stmtTicket->execute([':id' => $ticketId]);
$ticket = $stmtTicket->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    die('Ticket no encontrado');
}

$ticketUserId    = (int)($ticket['user_id'] ?? 0);
$ticketAnalystId = (int)($ticket['asignado_a'] ?? 0);

$allowed = false;

if ($rol === 4 && $userId === $ticketUserId) {
    $allowed = true;
} elseif ($rol === 3 && $userId === $ticketAnalystId) {
    $allowed = true;
} elseif (in_array($rol, [1, 2], true)) {
    $allowed = true;
}

if (!$allowed) {
    die('No tienes permisos para ver este reporte.');
}

// ==============================
//  HELPERS
// ==============================
function problemaLabel(string $p): string {
    return match ($p) {
        'cierre_dia'       => 'Cierre del día',
        'no_legado'        => 'Sin acceso a legado/legacy',
        'no_internet'      => 'Sin internet',
        'no_checador'      => 'No funciona checador',
        'rastreo'          => 'Rastreo de checada',
        'replica'          => 'Replica',
        'no_sap'           => 'Sin acceso a SAP',
        'update_cliente'   => 'Modificación de cliente',
        'alta_cliente'     => 'Alta de cliente',
        'Descuentos'       => 'Descuentos',
        'otro'             => 'Otro',
        default            => $p,
    };
}

function estadoLabel(string $e): string {
    return match ($e) {
        'abierto'     => 'Abierto',
        'en_proceso'  => 'En proceso',
        'resuelto'    => 'Resuelto',
        'cerrado'     => 'Cerrado',
        default       => $e,
    };
}

function prioridadLabel(?string $p): string {
    $p = strtolower((string)$p);
    return match ($p) {
        'alta'  => 'Alta',
        'baja'  => 'Baja',
        default => 'Media',
    };
}

function parseDT(?string $s): ?DateTime {
    if (!$s) return null;
    try {
        return new DateTime($s);
    } catch (Exception $e) {
        return null;
    }
}

// formato humano tipo "1d 3h 20min"
function humanDiff(DateTime $start, DateTime $end): string {
    $diff = $start->diff($end);

    $parts = [];
    if ($diff->d > 0)  $parts[] = $diff->d . 'd';
    if ($diff->h > 0)  $parts[] = $diff->h . 'h';
    if ($diff->i > 0)  $parts[] = $diff->i . 'min';
    if (empty($parts)) {
        $parts[] = $diff->s . 's';
    }
    return implode(' ', $parts);
}

// ==============================
//  OBTENER MENSAJES + ARCHIVOS
// ==============================
$stmtMsg = $pdo->prepare("
    SELECT 
        m.id,
        m.sender_id,
        m.sender_role,
        m.mensaje,
        m.is_internal,
        m.created_at,
        f.file_name,
        f.file_path,
        f.file_type
    FROM ticket_messages m
    LEFT JOIN ticket_message_files f
           ON f.message_id = m.id
    WHERE m.ticket_id = :ticket_id
    ORDER BY m.id ASC
");
$stmtMsg->execute([':ticket_id' => $ticketId]);
$rows = $stmtMsg->fetchAll(PDO::FETCH_ASSOC);

// Agrupar archivos por mensaje
$messages = [];
foreach ($rows as $r) {
    $mid = (int)$r['id'];
    if (!isset($messages[$mid])) {
        $messages[$mid] = [
            'id'          => $mid,
            'sender_id'   => $r['sender_id'],
            'sender_role' => $r['sender_role'],
            'mensaje'     => $r['mensaje'],
            'is_internal' => (int)$r['is_internal'],
            'created_at'  => $r['created_at'],
            'files'       => [],
        ];
    }

    if (!empty($r['file_path'])) {
        $messages[$mid]['files'][] = [
            'name' => $r['file_name'],
            'path' => $r['file_path'],
            'type' => $r['file_type'],
        ];
    }
}

// ==============================
//  CÁLCULO DE TIEMPOS
// ==============================

$dtCreacion    = parseDT($ticket['fecha_envio'] ?? null);
$dtAsignacion  = parseDT($ticket['fecha_asignacion'] ?? null);
$dtResolucion  = parseDT($ticket['fecha_resolucion'] ?? null);

// primera respuesta de analista
$firstAnalystDT = null;
foreach ($messages as $m) {
    if (($m['sender_role'] ?? '') === 'analista') {
        $firstAnalystDT = parseDT($m['created_at']);
        if ($firstAnalystDT) break;
    }
}

$tiempoRespuesta = 'N/D';
if ($dtCreacion && $firstAnalystDT) {
    $tiempoRespuesta = humanDiff($dtCreacion, $firstAnalystDT);
}

$tiempoResolucion = 'N/D';
if ($dtCreacion && $dtResolucion) {
    $tiempoResolucion = humanDiff($dtCreacion, $dtResolucion);
}

// ==============================
//  DATOS PARA EL REPORTE
// ==============================

$ticketTitulo    = 'Ticket #' . $ticketId;
$ticketProblema  = problemaLabel($ticket['problema'] ?? '');
$ticketEstado    = estadoLabel($ticket['estado'] ?? '');
$ticketPrioridad = prioridadLabel($ticket['prioridad'] ?? $ticket['priotidad'] ?? 'media'); // por si quedó el typo
$ticketFecha     = $ticket['fecha_envio'] ?? '';
$ticketNombre    = $ticket['nombre'] ?? '';
$ticketSap       = $ticket['sap'] ?? '';
$ticketArea      = $ticket['area'] ?? '';
$ticketEmail     = $ticket['email'] ?? '';

// Analista
$analistaNombre = trim(($ticket['analista_name'] ?? '') . ' ' . ($ticket['analista_last'] ?? ''));
$analistaEmail  = $ticket['analista_email'] ?? '';
$analistaArea   = $ticket['analista_area'] ?? '';

// URL base (para links a archivos en PDF)
$baseUrl = 'http://localhost/HelpDesk_EQF'; // AJUSTA esto si usas otro dominio

// ==============================
//  ARMAR HTML
// ==============================

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($ticketTitulo); ?></title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111827;
        }
        h1, h2, h3, h4 {
            margin: 0 0 6px 0;
            color: #111827;
        }
        .header {
            border-bottom: 2px solid #1f2937;
            padding-bottom: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .brand {
            font-size: 18px;
            font-weight: bold;
        }
        .brand span.eqf-e { color: #14378A; }
        .brand span.eqf-q { color: #C8002D; }
        .brand span.eqf-f { color: #1E8A4F; }
        .meta {
            font-size: 10px;
            text-align: right;
        }

        .section {
            margin-bottom: 14px;
        }
        .section-title {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        table.info-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
            margin-bottom: 6px;
        }
        table.info-table th,
        table.info-table td {
            border: 1px solid #d1d5db;
            padding: 4px 6px;
            font-size: 10px;
            vertical-align: top;
        }
        table.info-table th {
            background: #f3f4f6;
            text-align: left;
            width: 28%;
        }

        .messages-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        .messages-table th,
        .messages-table td {
            border: 1px solid #e5e7eb;
            padding: 4px 6px;
            font-size: 9px;
            vertical-align: top;
        }
        .messages-table th {
            background: #f9fafb;
        }

        .msg-meta {
            font-size: 9px;
            color: #6b7280;
        }
        .msg-internal {
            color: #b45309;
            font-weight: bold;
        }

        .files-list {
            margin: 2px 0 0 10px;
            padding: 0;
            list-style: disc;
        }
        .files-list li {
            margin-bottom: 2px;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 999px;
            font-size: 9px;
            color: #fff;
        }
        .badge-estado-abierto { background: #ef4444; }
        .badge-estado-en_proceso { background: #f59e0b; }
        .badge-estado-resuelto { background: #10b981; }
        .badge-estado-cerrado { background: #6b7280; }

        .badge-prio-alta { background: #b91c1c; }
        .badge-prio-media { background: #f59e0b; }
        .badge-prio-baja { background: #16a34a; }

        .firma-block {
            margin-top: 12px;
            text-align: left;
            font-size: 10px;
        }
        .firma-line {
            margin-top: 30px;
            border-top: 1px solid #9ca3af;
            width: 220px;
        }

        .footer {
            margin-top: 16px;
            font-size: 8px;
            text-align: center;
            color: #9ca3af;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="brand">
        HelpDesk <span class="eqf-e">E</span><span class="eqf-q">Q</span><span class="eqf-f">F</span><br>
        <span style="font-size: 11px; font-weight: normal;">Reporte de ticket</span>
    </div>
    <div class="meta">
        <strong><?php echo htmlspecialchars($ticketTitulo); ?></strong><br>
        Generado: <?php echo date('Y-m-d H:i:s'); ?>
    </div>
</div>

<!-- INFO TICKET -->
<div class="section">
    <div class="section-title">Información del ticket</div>
    <table class="info-table">
        <tr>
            <th>Ticket</th>
            <td>#<?php echo (int)$ticketId; ?></td>
        </tr>
        <tr>
            <th>Problema</th>
            <td><?php echo htmlspecialchars($ticketProblema); ?></td>
        </tr>
        <tr>
            <th>Estado</th>
            <td>
                <?php $estadoClass = 'badge-estado-' . ($ticket['estado'] ?? 'abierto'); ?>
                <span class="badge <?php echo htmlspecialchars($estadoClass); ?>">
                    <?php echo htmlspecialchars($ticketEstado); ?>
                </span>
            </td>
        </tr>
        <tr>
            <th>Prioridad</th>
            <td>
                <?php
                $prio   = strtolower($ticketPrioridad);
                $prioClass = match ($prio) {
                    'alta'  => 'badge-prio-alta',
                    'baja'  => 'badge-prio-baja',
                    default => 'badge-prio-media',
                };
                ?>
                <span class="badge <?php echo $prioClass; ?>">
                    <?php echo htmlspecialchars(ucfirst($ticketPrioridad)); ?>
                </span>
            </td>
        </tr>
        <tr>
            <th>Fecha de creación</th>
            <td><?php echo htmlspecialchars($ticketFecha); ?></td>
        </tr>
        <tr>
            <th>Fecha de asignación</th>
            <td><?php echo htmlspecialchars($ticket['fecha_asignacion'] ?? 'N/D'); ?></td>
        </tr>
        <tr>
            <th>Fecha de resolución</th>
            <td><?php echo htmlspecialchars($ticket['fecha_resolucion'] ?? 'N/D'); ?></td>
        </tr>
        <tr>
            <th>Descripción inicial</th>
            <td><?php echo nl2br(htmlspecialchars($ticket['descripcion'] ?? '')); ?></td>
        </tr>
    </table>
</div>

<!-- USUARIO -->
<div class="section">
    <div class="section-title">Usuario solicitante</div>
    <table class="info-table">
        <tr>
            <th>Nombre</th>
            <td><?php echo htmlspecialchars($ticketNombre); ?></td>
        </tr>
        <tr>
            <th># SAP</th>
            <td><?php echo htmlspecialchars($ticketSap); ?></td>
        </tr>
        <tr>
            <th>Área</th>
            <td><?php echo htmlspecialchars($ticketArea); ?></td>
        </tr>
        <tr>
            <th>Correo</th>
            <td><?php echo htmlspecialchars($ticketEmail); ?></td>
        </tr>
    </table>
</div>

<!-- ANALISTA + MÉTRICAS -->
<div class="section">
    <div class="section-title">Atención del ticket</div>
    <table class="info-table">
        <tr>
            <th>Analista asignado</th>
            <td>
                <?php echo htmlspecialchars($analistaNombre !== '' ? $analistaNombre : 'N/D'); ?>
                <?php if ($analistaArea): ?>
                    <br><span style="font-size:9px;color:#6b7280;">
                        Área de soporte: <?php echo htmlspecialchars($analistaArea); ?>
                    </span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Correo del analista</th>
            <td><?php echo htmlspecialchars($analistaEmail ?: 'N/D'); ?></td>
        </tr>
        <tr>
            <th>Tiempo hasta primera respuesta</th>
            <td><?php echo htmlspecialchars($tiempoRespuesta); ?></td>
        </tr>
        <tr>
            <th>Tiempo total de resolución</th>
            <td><?php echo htmlspecialchars($tiempoResolucion); ?></td>
        </tr>
    </table>
</div>

<!-- HISTORIAL DE MENSAJES -->
<div class="section">
    <div class="section-title">Historial de mensajes</div>

    <?php if (empty($messages)): ?>
        <p style="font-size: 10px;">No hay mensajes registrados en este ticket.</p>
    <?php else: ?>
        <table class="messages-table">
            <thead>
                <tr>
                    <th style="width: 20%;">Fecha / Emisor</th>
                    <th>Mensaje</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($messages as $m): ?>
                <tr>
                    <td>
                        <div class="msg-meta">
                            <?php echo htmlspecialchars($m['created_at']); ?><br>
                            <?php echo htmlspecialchars($m['sender_role']); ?>
                            <?php if ($m['is_internal']): ?>
                                <span class="msg-internal">(Nota interna)</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php echo nl2br(htmlspecialchars($m['mensaje'])); ?>

                        <?php if (!empty($m['files'])): ?>
                            <div style="margin-top:4px; font-size:9px;">
                                <strong>Archivos adjuntos:</strong>
                                <ul class="files-list">
                                    <?php foreach ($m['files'] as $f): ?>
                                        <?php $url = $baseUrl . '/' . ltrim($f['path'], '/'); ?>
                                        <li>
                                            <a href="<?php echo htmlspecialchars($url); ?>">
                                                <?php echo htmlspecialchars($f['name']); ?>
                                            </a>
                                            <?php if (!empty($f['type'])): ?>
                                                <span style="color:#9ca3af;">
                                                    (<?php echo htmlspecialchars($f['type']); ?>)
                                                </span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- FIRMA / SELLO DEL ÁREA -->
<div class="section firma-block">
    <div>Este ticket fue atendido por el área de soporte:</div>
    <div><strong><?php echo htmlspecialchars($analistaArea ?: $ticketArea ?: 'N/D'); ?></strong></div>

    <div class="firma-line"></div>
    <div style="font-size:9px; margin-top:2px;">
        Firma responsable · HelpDesk EQF
    </div>
</div>

<div class="footer">
    HelpDesk EQF · Reporte generado automáticamente · <?php echo date('Y'); ?>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

// ==============================
//  GENERAR PDF CON DOMPDF
// ==============================
$options = new Options();
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$dompdf->stream('ticket_' . $ticketId . '.pdf', ['Attachment' => true]);
exit;
