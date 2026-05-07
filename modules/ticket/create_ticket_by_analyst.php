<?php
session_start();
require_once __DIR__ . '/../../config/connectionBD.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'msg' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit;
}

$pdo = Database::getConnection();

try {
  // ✅ ID real del analista que crea (fallback por si tu sistema guarda el id con otra llave)
  $creatorId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
  if ($creatorId <= 0) throw new Exception('No se detectó el ID del analista en sesión.');

  $creatorArea = trim((string)($_SESSION['user_area'] ?? $_SESSION['area'] ?? '')); // TI / SAP / MKT

  $userId       = (int)($_POST['user_id'] ?? 0);
  $descUser     = trim((string)($_POST['descripcion'] ?? ''));
  $ticketParaMi = ((int)($_POST['ticket_para_mi'] ?? 0) === 1);

  if ($userId <= 0) throw new Exception('Usuario inválido.');
  if ($descUser === '') throw new Exception('La descripción es obligatoria.');

  // ====== FORZADOS ======
  $problema  = 'otro';
  $prioridad = 'media';

  // ====== DESTINO POR REGLA ======
  if ($ticketParaMi) {
    $areaDestino = 'TI';
  } else {
    if ($creatorArea === '') throw new Exception('No se detectó el área del analista.');
    $areaDestino = $creatorArea;
  }

  // ====== TRAER DATOS DEL USUARIO (snapshot en tickets) ======
  $stU = $pdo->prepare("SELECT id, number_sap, name, last_name, email, area FROM users WHERE id = ? LIMIT 1");
  $stU->execute([$userId]);
  $u = $stU->fetch(PDO::FETCH_ASSOC);
  if (!$u) throw new Exception('Usuario no encontrado.');

  $sap    = (string)$u['number_sap'];
  $nombre = trim(($u['name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
  $email  = (string)$u['email'];

  // ====== DESCRIPCIÓN (nota automática para asistencia) ======
  if (!$ticketParaMi) {
    $nota = "NOTA: Ticket generado por analista como registro de atención. "
          . "No se considera para KPIs de tiempo (primera respuesta / resolución). "
          . "Solo aplica para KPI de satisfacción.\n\n";
    $descFinal = $nota . $descUser;
  } else {
    $descFinal = $descUser;
  }

  // ====== ESTADO / ENCUESTA ======
  if ($ticketParaMi) {
    $estado        = 'abierto';
    $needsFeedback = 0;
    $fechaResol    = null;
  } else {
    $estado        = 'cerrado';
    $needsFeedback = 1;
    $fechaResol    = date('Y-m-d H:i:s');
  }

  // ✅ ASIGNACIÓN: SIEMPRE AL ANALISTA QUE LO CREÓ
  $asignadoA       = $creatorId;
  $fechaAsignacion = date('Y-m-d H:i:s');

  // ====== DEDUPE (mismo user_id + destino + descripción final en 5s) ======
  $stDup = $pdo->prepare("
    SELECT id
    FROM tickets
    WHERE user_id = :uid
      AND area = :area
      AND descripcion = :desc
      AND fecha_envio >= (NOW() - INTERVAL 5 SECOND)
    ORDER BY id DESC
    LIMIT 1
  ");
  $stDup->execute([':uid' => $userId, ':area' => $areaDestino, ':desc' => $descFinal]);
  $dup = $stDup->fetch(PDO::FETCH_ASSOC);

  if ($dup && !empty($dup['id'])) {
    echo json_encode([
      'ok' => true,
      'ticket_id' => (int)$dup['id'],
      'deduped' => true,
      'asignado_a' => $asignadoA
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ====== INSERT ======
  $sql = "
    INSERT INTO tickets
      (user_id, sap, nombre, area, email, problema, prioridad, descripcion,
       fecha_envio, estado,
       asignado_a, fecha_asignacion,
       fecha_resolucion,
       needs_feedback, feedback_done,
       creado_por_ip, creado_por_navegador)
    VALUES
      (:user_id, :sap, :nombre, :area, :email, :problema, :prioridad, :descripcion,
       NOW(), :estado,
       :asignado_a, :fecha_asignacion,
       :fecha_resolucion,
       :needs_feedback, 0,
       :ip, :ua)
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':user_id'          => $userId,
    ':sap'              => $sap,
    ':nombre'           => $nombre,
    ':area'             => $areaDestino,
    ':email'            => $email,
    ':problema'         => $problema,
    ':prioridad'        => $prioridad,
    ':descripcion'      => $descFinal,

    ':estado'           => $estado,
    ':asignado_a'       => $asignadoA,
    ':fecha_asignacion' => $fechaAsignacion,
    ':fecha_resolucion' => $fechaResol,

    ':needs_feedback'   => $needsFeedback,

    ':ip'               => $_SERVER['REMOTE_ADDR'] ?? null,
    ':ua'               => $_SERVER['HTTP_USER_AGENT'] ?? null,
  ]);

  $ticketId = (int)$pdo->lastInsertId();

  // ====== Si es registro de asistencia (cerrado) -> generar encuesta ======
  if (!$ticketParaMi) {
    $token = bin2hex(random_bytes(32));

    $stF = $pdo->prepare("
      INSERT INTO ticket_feedback (ticket_id, user_id, token, created_at)
      VALUES (:tid, :uid, :tok, NOW())
    ");
    $stF->execute([
      ':tid' => $ticketId,
      ':uid' => $userId,
      ':tok' => $token
    ]);
  }

  echo json_encode([
    'ok' => true,
    'ticket_id' => $ticketId,
    'asignado_a' => $asignadoA
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'msg' => 'Error interno', 'debug' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}