<?php
require_once __DIR__ . '/../../config/connectionBD.php';

$pdo = Database::getConnection();

$token = $_GET['token'] ?? '';

$stmt = $pdo->prepare("
    SELECT
        f.id,
        f.maintenance_request_id,
        m.title
    FROM maintenance_feedback f
    JOIN maintenance_requests m
        ON m.id = f.maintenance_request_id
    WHERE f.token = ?
      AND f.answered_at IS NULL
");

$stmt->execute([$token]);

$feedback = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$feedback) {
    exit('Encuesta inválida o ya respondida.');
}

$title = $feedback['title'] ?? '';

function ratingGroup($name) {
?>
<div class="rating-group">

  <!-- Inaceptable -->
  <label class="fb-btn"
    style="--tone: var(--eqf-red); --icon: url('/HelpDesk_EQF/assets/img/feedback/feedback_bad.png');">

    <input type="radio" name="<?php echo $name; ?>" value="1" required>
    <span class="fb-icon"></span>
    <span class="fb-label">Inaceptable</span>
  </label>

  <!-- Regular -->
  <label class="fb-btn"
    style="--tone: var(--eqf-orange); --icon: url('/HelpDesk_EQF/assets/img/feedback/feedback_no.png');">

    <input type="radio" name="<?php echo $name; ?>" value="2">
    <span class="fb-icon"></span>
    <span class="fb-label">Regular</span>
  </label>

  <!-- Bueno -->
  <label class="fb-btn"
    style="--tone: var(--eqf-yellow); --icon: url('/HelpDesk_EQF/assets/img/feedback/feedback_reg.png');">

    <input type="radio" name="<?php echo $name; ?>" value="3">
    <span class="fb-icon"></span>
    <span class="fb-label">Bueno</span>
  </label>

  <!-- Excelente -->
  <label class="fb-btn"
    style="--tone: var(--eqf-green); --icon: url('/HelpDesk_EQF/assets/img/feedback/feedback_good.png');">

    <input type="radio" name="<?php echo $name; ?>" value="4">
    <span class="fb-icon"></span>
    <span class="fb-label">Excelente</span>
  </label>

</div>
<?php
}
?>
<?php

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Encuesta de satisfacción</title>
<link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">
<style>

body{
    margin:0;
    background:#f5f6fa;
    font-family:Arial, Helvetica, sans-serif;
}

.fb-wrap{
    max-width:900px;
    margin:30px auto;
    padding:20px;
}

.fb-card{
    background:#fff;
    border-radius:18px;
    padding:25px;
    box-shadow:0 8px 24px rgba(0,0,0,.08);
}

h2{
    margin-top:0;
}

.fb-title{
    color:#666;
    margin-bottom:25px;
}

.fb-question{
    margin-bottom:28px;
}

.fb-question h3{
    font-size:15px;
    margin-bottom:12px;
}

.rating-group{
    display:flex;
    gap:15px;
    flex-wrap:wrap;
}

.fb-btn{
    border:2px solid #ddd;
    border-radius:16px;
    padding:14px;
    min-width:140px;
    cursor:pointer;
    transition:.2s;
    text-align:center;
    background:#fff;
}

.fb-btn:hover{
    transform:translateY(-2px);
}

.fb-btn input{
    display:none;
}

.fb-icon{
    width:48px;
    height:48px;
    background-color: var(--tone);

    -webkit-mask-image: var(--icon);
    -webkit-mask-repeat: no-repeat;
    -webkit-mask-position: center;
    -webkit-mask-size: contain;

    mask-image: var(--icon);
    mask-repeat: no-repeat;
    mask-position: center;
    mask-size: contain;

    margin-bottom:6px;
}

.fb-label{
    display:none;
}

.fb-btn{
    position:relative;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    width:92px;
    height:84px;
    border-radius:18px;
    border:2px solid #ddd;
    background:#fff;
    cursor:pointer;
    transition:
        transform .15s ease,
        box-shadow .15s ease,
        border-color .15s ease,
        background-color .15s ease;
}

.fb-btn:hover{
    transform:translateY(-2px);
    box-shadow:0 10px 20px rgba(15,23,42,.10);
}

.fb-btn.is-checked{
    border-color: var(--tone);
    background: color-mix(in srgb, var(--tone) 12%, white);
    box-shadow: 0 12px 24px rgba(15,23,42,.14);
}
.fb-legend{
    display:flex;
    gap:18px;
    flex-wrap:wrap;
    margin:15px 0 25px;
    font-size:13px;
    color:#555;
}

.fb-legend-item{
    display:flex;
    align-items:center;
    gap:8px;
    font-weight:600;
}

.dot{
    width:12px;
    height:12px;
    border-radius:50%;
}

.dot.red{
    background:var(--eqf-red);
}

.dot.orange{
    background:var(--eqf-orange);
}

.dot.yellow{
    background:var(--eqf-yellow);
}

.dot.green{
    background:var(--eqf-green);
}
textarea{
    width:100%;
    min-height:100px;
    border:1px solid #ccc;
    border-radius:14px;
    padding:12px;
    resize:vertical;
}

button{
    margin-top:20px;
    border:none;
    background:#6e1c5c;
    color:#fff;
    padding:12px 20px;
    border-radius:14px;
    cursor:pointer;
    font-weight:bold;
}

</style>
</head>
<body>

<div class="fb-wrap">

    <div class="fb-card">

        <h2>Encuesta de satisfacción</h2>

        <div class="fb-title">
            <?php echo htmlspecialchars($title); ?>
        </div>
<div class="fb-legend">

  <div class="fb-legend-item">
    <span class="dot red"></span>
    Inaceptable
  </div>

  <div class="fb-legend-item">
    <span class="dot orange"></span>
    Regular
  </div>

  <div class="fb-legend-item">
    <span class="dot yellow"></span>
    Bueno
  </div>

  <div class="fb-legend-item">
    <span class="dot green"></span>
    Excelente
  </div>

</div>
        <form
            id="feedbackForm"
            method="POST"
            action="/HelpDesk_EQF/modules/feedback/submit_feedback_maintenance.php"
        >

            <input
                type="hidden"
                name="token"
                value="<?php echo htmlspecialchars($token); ?>"
            >

            <div class="fb-question">
                <h3>1. Cumple con el horario acordado</h3>
                <?php ratingGroup('q1'); ?>
            </div>

            <div class="fb-question">
                <h3>2. La atención de las solicitudes es rápida y oportuna</h3>
                <?php ratingGroup('q2'); ?>
            </div>

            <div class="fb-question">
                <h3>3. Detecta y corrige los problemas de forma oportuna</h3>
                <?php ratingGroup('q3'); ?>
            </div>

            <div class="fb-question">
                <h3>4. Productividad y eficiencia en el desarrollo de trabajos</h3>
                <?php ratingGroup('q4'); ?>
            </div>

            <div class="fb-question">
                <h3>5. Actitud cooperativa y disposición de servicio</h3>
                <?php ratingGroup('q5'); ?>
            </div>

            <div class="fb-question">
                <h3>Comentarios del cliente</h3>

                <textarea
                    name="comment"
                    maxlength="500"
                ></textarea>
            </div>

            <button type="submit">
                Enviar encuesta
            </button>

        </form>

    </div>

</div>

<script>

document.addEventListener('change', function(e){

    const input = e.target;

    if (!input.matches('input[type="radio"]')) return;

    const name = input.name;

    document
        .querySelectorAll(`input[name="${name}"]`)
        .forEach(r => {
            r.closest('.fb-btn').classList.remove('is-checked');
        });

    input.closest('.fb-btn').classList.add('is-checked');

});

</script>

</body>
</html>