<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../config/connectionBD.php';

$conn = Database::getConnection();
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

    $stmt = $conn->prepare("
        SELECT *
        FROM maintenance_requests
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);

    $r = $stmt->fetch(PDO::FETCH_ASSOC);
$stmtFiles = $conn->prepare("
    SELECT
        file_name,
        file_path,
        mime_type
    FROM maintenance_files
    WHERE maintenance_request_id = ?
    ORDER BY id DESC
");

$stmtFiles->execute([$id]);

$files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);
    if (!$r) {

        http_response_code(404);

        echo json_encode([
            'ok' => false,
            'msg' => 'Solicitud no encontrada'
        ]);

        exit;
    }

    ob_start();
    ?>

    <div class="task-card">

        <div class="task-row">
            <div class="task-label">ID</div>
            <div class="task-value">
                #<?php echo (int)$r['id']; ?>
            </div>
        </div>

        <div class="task-row">
            <div class="task-label">Solicitante</div>
            <div class="task-value">
                <?php echo htmlspecialchars($r['requester_email']); ?>
            </div>
        </div>

        <div class="task-row">
            <div class="task-label">Asunto</div>
            <div class="task-value">
                <?php echo htmlspecialchars($r['title']); ?>
            </div>
        </div>

        <div class="task-row">
            <div class="task-label">Estatus</div>
            <div class="task-value">
                <?php echo htmlspecialchars($r['status']); ?>
            </div>
        </div>

        <div class="panel-divider"></div>

        <div class="panel-mini-title">
            Descripción
        </div>

        <div class="task-desc">
            <?php echo nl2br(htmlspecialchars($r['description'])); ?>
        </div>
        <div style="margin-top:10px;">

    <a
        href="/HelpDesk_EQF/modules/dashboard/maintenance/maintenance_generate_pdf.php?id=<?php echo (int)$r['id']; ?>"
        target="_blank"
        class="task-link-blue"
    >
        Solicitud
    </a>

    &nbsp;|&nbsp;

    <a
        href="#"
        class="task-link-blue"
    >
        Reporte
    </a>

</div>
<?php if (!empty($files)): ?>

    <div class="panel-divider"></div>
<div class="panel-mini-title">
    Evidencias
</div>

<?php if (empty($files)): ?>

    <div class="panel-mini-note">
        No hay archivos adjuntos.
    </div>

<?php else: ?>

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

                <?php foreach ($files as $file): ?>

                    <?php
                        $path = $file['file_path'];

                        $name = $file['file_name'] ?? 'Archivo';

                        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    ?>

                    <tr>

                        <td>
                            <?php echo h($name); ?>
                        </td>

                        <td>
                            <?php echo strtoupper(h($ext)); ?>
                        </td>

                        <td class="ta-right">

                            <a
                                href="<?php echo h($path); ?>"
                                target="_blank"
                                class="task-link-blue"
                            >
                                Ver
                            </a>

                            &nbsp;|&nbsp;

                            <a
                                href="<?php echo h($path); ?>"
                                download
                                class="task-link-blue"
                            >
                                Descargar
                            </a>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

<?php endif; ?>
    
    </div>
<?php endif; ?>

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