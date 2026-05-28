<?php
session_start();
header('Content-Type: application/json');

// 1. Corrección de ruta y conexión Singleton
require_once __DIR__ . '/../../../../config/connectionBD.php';
$conn = Database::getConnection(); 

// 2. Validación de sesión
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sesión no válida o expirada.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método de petición no permitido.']);
    exit;
}

$requestId   = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
$performedBy = isset($_POST['performed_by']) ? trim($_POST['performed_by']) : '';
$completedBy = $_SESSION['user_id']; // ID del analista logueado para 'completed_by'

if ($requestId <= 0 || !in_array($performedBy, ['VSP', 'EXTERNO'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'ID de solicitud o parámetro de ejecución inválido.']);
    exit;
}

try {
    $conn->beginTransaction();

    if ($performedBy === 'VSP') {
        // ==========================================
        // FLUJO VSP: VALIDAR Y SUBIR ARCHIVO
        // ==========================================
        if (!isset($_FILES['vspReportFile']) || $_FILES['vspReportFile']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Es necesario adjuntar un documento técnico válido.');
        }

        $file = $_FILES['vspReportFile'];
        $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
        $fileInfo = pathinfo($file['name']);
        $extension = strtolower($fileInfo['extension'] ?? '');

        if (!in_array($extension, $allowedExtensions)) {
            throw new Exception('Formato de archivo no admitido. Formatos válidos: PDF, Word, Excel.');
        }

        $uploadDir = __DIR__ . '/../../../../uploads/maintenance/reports/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $newFileName = 'reporte_mto_' . $requestId . '_' . time() . '.' . $extension;
        $destination = $uploadDir . $newFileName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new Exception('Error interno al mover el archivo al directorio de destino.');
        }

        $filePathDb = 'uploads/maintenance/reports/' . $newFileName;

        // Mapeo exacto a la tabla 'maintenance_completion' para VSP
        $sql = "INSERT INTO maintenance_completion 
                (maintenance_request_id, performed_by, maintenance_type, activities, 
                 report_original_name, report_file_name, report_file_path, report_file_extension, report_file_size, 
                 completed_by, completed_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $requestId, 
            'VSP', 
            'PREVENTIVO', 
            'Reporte técnico cargado por personal VSP.',
            $file['name'],
            $newFileName,
            $filePathDb,
            $extension,
            $file['size'],
            $completedBy
        ]);

    } else {
        // ==========================================
        // FLUJO EXTERNO: CAMPOS, MATERIALES Y PERSONAL DINÁMICO
        // ==========================================
        $externalCompany = isset($_POST['external_company']) ? trim($_POST['external_company']) : '';
        $maintenanceType = isset($_POST['maintenance_type']) ? trim($_POST['maintenance_type']) : 'PREVENTIVO';
        $activities      = isset($_POST['activities']) ? trim($_POST['activities']) : '';
        $materialsJson   = isset($_POST['materials']) ? $_POST['materials'] : '[]';
        $staffJson       = isset($_POST['staff']) ? $_POST['staff'] : '[]';

        if (empty($externalCompany) || empty($maintenanceType) || empty($activities)) {
            throw new Exception('Faltan campos mandatorios por rellenar en el reporte externo.');
        }

        // Validación previa del personal antes de tocar la Base de Datos
        $staffArray = json_decode($staffJson, true);
        if (!is_array($staffArray) || count($staffArray) === 0) {
            throw new Exception('No se detectó el personal que realizó el mantenimiento.');
        }

        // SOLUCIÓN MULTI-MATERIALES: Validamos y limpiamos el arreglo antes de guardar
        $materialsArray = json_decode($materialsJson, true);
        $validMaterials = [];
        if (is_array($materialsArray)) {
            foreach ($materialsArray as $mat) {
                $qty  = isset($mat['qty']) ? intval($mat['qty']) : 1;
                $unit = isset($mat['unit']) ? trim($mat['unit']) : '';
                $desc = isset($mat['desc']) ? trim($mat['desc']) : '';
                
                if ($unit !== '' || $desc !== '') {
                    $validMaterials[] = ['qty' => $qty, 'unit' => $unit, 'desc' => $desc];
                }
            }
        }

        $firstQty  = !empty($validMaterials) ? $validMaterials[0]['qty'] : null;
        $firstUnit = !empty($validMaterials) ? $validMaterials[0]['unit'] : null;
        $allMaterialsEncoded = !empty($validMaterials) ? json_encode($validMaterials, JSON_UNESCAPED_UNICODE) : '[]';

        // Mapeo completo a la tabla 'maintenance_completion'
        $sql = "INSERT INTO maintenance_completion 
                (maintenance_request_id, performed_by, external_company, maintenance_type, activities, 
                 material_qty, material_unit, material_description, staff_json, completed_by, completed_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $requestId, 
            'EXTERNO', 
            $externalCompany, 
            $maintenanceType, 
            $activities, 
            $firstQty, 
            $firstUnit, 
            $allMaterialsEncoded, 
            $staffJson,
            $completedBy
        ]);
    }

    // ==========================================================================
    // 1. OBTENER EL USER_ID DEL SOLICITANTE ORIGINAL (Para ligar el feedback)
    // ==========================================================================
    $stmtUser = $conn->prepare("SELECT requester_user_id FROM maintenance_requests WHERE id = ? LIMIT 1");
    $stmtUser->execute([$requestId]);
    $requestData = $stmtUser->fetch(PDO::FETCH_ASSOC);
    
    if (!$requestData) {
        throw new Exception('No se encontró la solicitud de mantenimiento original.');
    }
    $requesterUserId = (int)$requestData['requester_user_id'];

    // ==========================================================================
    // 2. GENERAR UN TOKEN ÚNICO Y SEGURO PARA LA ENCUESTA
    // ==========================================================================
    $feedbackToken = bin2hex(random_bytes(32));

    // ==========================================================================
    // 3. ACTUALIZAR SOLICITUD: CAMBIAR ESTATUS E INYECTAR EL TOKEN
    // ==========================================================================
    $stmtUpdate = $conn->prepare("UPDATE maintenance_requests SET status = 'FINALIZADO' WHERE id = ?");
    $stmtUpdate->execute([$requestId]);

    // ==========================================================================
    // 4. CREAR EL REGISTRO PREVIO EN LA TABLA MAINTENANCE_FEEDBACK
    // ==========================================================================
  $stmtFeedback = $conn->prepare("
        INSERT INTO maintenance_feedback 
            (maintenance_request_id, user_id, token, created_at) 
        VALUES (?, ?, ?, NOW())
    ");
    $stmtFeedback->execute([
        $requestId,
        $requesterUserId,
        $feedbackToken
    ]);
    // Cerramos la transacción de manera limpia
  $conn->commit();
    
    echo json_encode([
        'ok' => true, 
        'msg' => '¡Mantenimiento cerrado, registrado y encuesta activada con éxito!'
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    if (isset($destination) && file_exists($destination)) {
        unlink($destination);
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}