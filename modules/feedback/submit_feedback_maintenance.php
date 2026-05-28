<?php

require_once __DIR__ . '/../../config/connectionBD.php';

$pdo = Database::getConnection();

$token = trim((string)($_POST['token'] ?? ''));

$q1 = (int)($_POST['q1'] ?? 0);
$q2 = (int)($_POST['q2'] ?? 0);
$q3 = (int)($_POST['q3'] ?? 0);
$q4 = (int)($_POST['q4'] ?? 0);
$q5 = (int)($_POST['q5'] ?? 0);

$comment = trim((string)($_POST['comment'] ?? ''));

/* =========================================
   VALIDACIÓN
========================================= */

$valid =
    $token !== '' &&
    in_array($q1, [1,2,3,4], true) &&
    in_array($q2, [1,2,3,4], true) &&
    in_array($q3, [1,2,3,4], true) &&
    in_array($q4, [1,2,3,4], true) &&
    in_array($q5, [1,2,3,4], true) &&
    mb_strlen($comment, 'UTF-8') <= 500;

if (!$valid) {

    exit('Datos inválidos.');
}

/* =========================================
   GUARDAR
========================================= */

$stmt = $pdo->prepare("
    UPDATE maintenance_feedback
    SET
        q1_schedule     = ?,
        q2_attention    = ?,
        q3_resolution   = ?,
        q4_productivity = ?,
        q5_service      = ?,
        comment         = ?,
        answered_at     = NOW()
    WHERE token = ?
      AND answered_at IS NULL
");

$stmt->execute([
    $q1,
    $q2,
    $q3,
    $q4,
    $q5,
    $comment,
    $token
]);

if ($stmt->rowCount() <= 0) {

    exit('La encuesta ya fue respondida o el token no es válido.');
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Encuesta enviada</title>

<style>

body{
    margin:0;
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
    font-family:Arial, Helvetica, sans-serif;
    background:#f5f6fa;
}

.box{
    background:#fff;
    padding:30px;
    border-radius:18px;
    box-shadow:0 8px 24px rgba(0,0,0,.08);
    text-align:center;
}

h2{
    color:#16a34a;
    margin-top:0;
}

p{
    color:#555;
}

</style>
</head>
<body>

<div class="box">

    <h2>
        ¡Gracias!
    </h2>

<p>
    Tu encuesta fue enviada correctamente.
    <br><br>
    Esta ventana se cerrará automáticamente...
</p>

</div>
<script>

setTimeout(() => {

    window.close();

    // fallback por si el navegador bloquea window.close()
    window.location.href = '/HelpDesk_EQF/';

}, 1500);

</script>
</body>
</html>