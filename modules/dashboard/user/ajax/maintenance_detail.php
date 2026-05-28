<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../config/connectionBD.php';

$conn = Database::getConnection();
$userEmail = strtolower(trim($_SESSION['user_email'] ?? ''));

$isProjectsUser = in_array($userEmail, [
    'proyectos@eqf.mx',
    'aux.proyectos@eqf.mx'
], true);

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'msg' => 'No autorizado'
    ]);
    exit;
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'msg' => 'ID inválido'
    ]);
    exit;
}

try {
    // Obtenemos los datos vinculando la solicitud con su respectivo cierre técnico
    $stmt = $conn->prepare("
        SELECT mr.*, mc.performed_by, mc.report_file_path
        FROM maintenance_requests mr
        LEFT JOIN maintenance_completion mc ON mc.maintenance_request_id = mr.id
        WHERE mr.id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'msg' => 'Solicitud no encontrada'
        ]);
        exit;
    }

    /* =========================================
       ARCHIVOS ADJUNTOS (EVIDENCIAS)
    ========================================== */
    $stmtFiles = $conn->prepare("
        SELECT file_name, file_path, mime_type
        FROM maintenance_files
        WHERE maintenance_request_id = ?
        ORDER BY id DESC
    ");
    $stmtFiles->execute([$id]);
    $files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
?>

<div class="task-card">

    <div class="task-row">
        <div class="task-label">ID</div>
        <div class="task-value">#<?php echo (int)$r['id']; ?></div>
    </div>

    <div class="task-row">
        <div class="task-label">Sucursal</div>
        <div class="task-value"><?php echo h($r['requester_email']); ?></div>
    </div>

    <div class="task-row">
        <div class="task-label">Asunto</div>
        <div class="task-value"><?php echo h($r['title']); ?></div>
    </div>

    <div class="task-row">
        <div class="task-label">Estatus</div>
        <div class="task-value"><?php echo h($r['status']); ?></div>
    </div>

    <div class="panel-divider"></div>

    <div class="panel-mini-title">Descripción</div>
    <div class="task-desc">
        <?php echo nl2br(h($r['description'])); ?>
    </div>

    <div style="margin-top:20px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">

        <?php if ($isProjectsUser): ?>
            <?php if (!empty($r['pdf_generated'])): ?>
                <a href="/HelpDesk_EQF/modules/dashboard/maintenance/maintenance_generate_pdf.php?id=<?php echo (int)$r['id']; ?>"
                   target="_blank" class="task-link-blue">
                    Ver solicitud digital
                </a>
            <?php endif; ?>

            <?php if (!empty($r['scanned_request'])): ?>
                <?php if (!empty($r['pdf_generated'])): ?><span style="color:#999;">|</span><?php endif; ?>
                <a href="/HelpDesk_EQF/<?php echo h($r['scanned_request']); ?>"
                   target="_blank" class="task-link-blue">
                    Ver solicitud escaneada
                </a>
            <?php endif; ?>

            <?php if ($r['status'] !== 'FINALIZADO'): ?>
                <?php if (!empty($r['pdf_generated']) || !empty($r['scanned_request'])): ?><span style="color:#999;">|</span><?php endif; ?>
                <a href="/HelpDesk_EQF/modules/dashboard/maintenance/generate_report.php?id=<?php echo (int)$r['id']; ?>"
                   class="task-link-blue" style="font-weight: bold; color: #28a745;">
                    Generar reporte
                </a>
            <?php endif; ?>

            <?php if (!empty($r['pdf_generated']) || !empty($r['scanned_request']) || $r['status'] !== 'FINALIZADO'): ?>
                <span style="color:#999;">|</span>
            <?php endif; ?>
            <a href="/HelpDesk_EQF/modules/dashboard/user/ajax/download_attachments_zip.php?id=<?php echo (int)$r['id']; ?>"
               class="task-link-blue">
                <i class="fas fa-file-archive"></i> ZIP
            </a>

        <?php else: ?>
            <?php if (empty($r['pdf_generated'])): ?>
                <a href="/HelpDesk_EQF/modules/dashboard/maintenance/maintenance_generate_pdf.php?id=<?php echo (int)$r['id']; ?>"
                   target="_blank" class="task-link-blue">
                    Generar solicitud
                </a>
            <?php else: ?>
                <?php if (empty($r['scanned_request'])): ?>
                    <a href="/HelpDesk_EQF/modules/dashboard/maintenance/maintenance_generate_pdf.php?id=<?php echo (int)$r['id']; ?>"
                       target="_blank" class="task-link-blue">
                        Ver solicitud digital
                    </a>
                    <span style="color:#999;">|</span>
                    
                    <form action="/HelpDesk_EQF/modules/dashboard/maintenance/upload_scanned_request.php"
                          method="POST" enctype="multipart/form-data" style="display:flex; gap:8px; align-items:center;">
                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                        <input type="file" name="scanned_file" accept=".pdf,.jpg,.jpeg,.png" required>
                        <button type="submit" class="btn btn-sm btn-primary">Adjuntar escaneado</button>
                    </form>
                <?php else: ?>
                    <a href="/HelpDesk_EQF/modules/dashboard/maintenance/maintenance_generate_pdf.php?id=<?php echo (int)$r['id']; ?>"
                       target="_blank" class="task-link-blue">
                        Solicitud Digital
                    </a>
                    <span style="color:#999;">|</span>
                    <a href="/HelpDesk_EQF/<?php echo h($r['scanned_request']); ?>"
                       target="_blank" class="task-link-blue">
                        Solicitud Escaneada
                    </a>
                <?php endif; ?>
            <?php endif; ?>

        <?php endif; ?>

        <?php if ($r['status'] === 'FINALIZADO'): ?>
            <span style="color:#999;">|</span>
            <?php if ($r['performed_by'] === 'VSP'): ?>
                <a href="/HelpDesk_EQF/<?php echo h($r['report_file_path']); ?>" target="_blank" class="task-link-blue" style="font-weight: bold; color: #dc3545;">
                    <i class="fas fa-download"></i> Descargar reporte
                </a>
            <?php else: ?>
                <a href="/HelpDesk_EQF/modules/dashboard/maintenance/maintenance_completion_pdf.php?id=<?php echo (int)$r['id']; ?>" target="_blank" class="task-link-blue" style="font-weight: bold; color: #dc3545;">
                    <i class="fas fa-file-pdf"></i> Descargar reporte
                </a>
            <?php endif; ?>
        <?php endif; ?>

    </div>

    <?php if (!empty($files)): ?>
        <div class="panel-divider"></div>
        <div class="panel-mini-title">Evidencias</div>
        <div class="panel-table-wrap">
            <table class="panel-table">
                <thead>
                    <tr>
                        <th>Archivo</th>
                        <th>Tipo</th>
                        <th class="ta-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $file): 
                        $path = $file['file_path'];
                        $name = $file['file_name'] ?? 'Archivo';
                        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    ?>
                        <tr>
                            <td><?php echo h($name); ?></td>
                            <td><?php echo strtoupper(h($ext)); ?></td>
                            <td class="ta-right">
                                <a href="<?php echo h($path); ?>" target="_blank" class="task-link-blue">Ver</a>
                                &nbsp;|&nbsp;
                                <a href="<?php echo h($path); ?>" download class="task-link-blue">Descargar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<?php
    $html = ob_get_clean();

    echo json_encode([
        'ok' => true,
        'html' => $html
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage()
    ]);
}