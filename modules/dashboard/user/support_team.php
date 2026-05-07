<?php
session_start();
require_once __DIR__ . '/../../../config/connectionBD.php';

include __DIR__ . '/../../../template/header.php';
include __DIR__ . '/../../../template/sidebar.php';

// Solo USUARIO (rol = 4)
if (!isset($_SESSION['user_id']) || (int)($_SESSION['user_rol'] ?? 0) !== 4) {
    header('Location: /HelpDesk_EQF/auth/login.php');
    exit;
}

$pdo = Database::getConnection();
date_default_timezone_set('America/Mexico_City');

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Parse shift:
 * - "8_1730" / "08_1900"
 * - "08:00-17:30"
 * - "0800-1730"
 */
function parseShift(?string $shift): ?array {
    $shift = trim((string)$shift);
    if ($shift === '') return null;

    // "8_1730"
    if (preg_match('/^(\d{1,2})_(\d{3,4})$/', $shift, $m)) {
        $h1 = (int)$m[1];
        $end = $m[2];
        if (strlen($end) === 3) $end = '0' . $end;
        $h2 = (int)substr($end, 0, 2);
        $m2 = (int)substr($end, 2, 2);
        return [
            'start' => sprintf('%02d:00', $h1),
            'end'   => sprintf('%02d:%02d', $h2, $m2),
        ];
    }

    // "08:00-17:30"
    if (preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $shift, $m)) {
        return [
            'start' => sprintf('%02d:%02d', (int)$m[1], (int)$m[2]),
            'end'   => sprintf('%02d:%02d', (int)$m[3], (int)$m[4]),
        ];
    }

    // "0800-1730"
    if (preg_match('/^(\d{3,4})\s*-\s*(\d{3,4})$/', $shift, $m)) {
        $a = $m[1]; $b = $m[2];
        if (strlen($a) === 3) $a = '0' . $a;
        if (strlen($b) === 3) $b = '0' . $b;
        return [
            'start' => sprintf('%02d:%02d', (int)substr($a,0,2), (int)substr($a,2,2)),
            'end'   => sprintf('%02d:%02d', (int)substr($b,0,2), (int)substr($b,2,2)),
        ];
    }

    return null;
}

function timeBetween(string $nowTime, string $a, string $b): bool {
    return ($nowTime >= $a && $nowTime < $b);
}

function roleLabel(int $rol): string {
    return match ($rol) {
        2 => 'Admin',
        3 => 'Analista',
        default => 'Soporte',
    };
}

/**
 * Disponibilidad para usuario: solo 2 estados.
 * Prioridad:
 * 1) Override activo y status != AUTO
 * 2) AUTO => horario (shift + lunch + sat_pattern)
 */
function computeUserAvailability(array $row, DateTime $now): array {
    $nowStr  = $now->format('Y-m-d H:i:s');
    $nowTime = $now->format('H:i:s');
    $dow     = (int)$now->format('N'); // 1..7

    // 1) Override
    $ovStatus = trim((string)($row['ov_status'] ?? ''));
    $ovActive = (int)($row['ov_active'] ?? 0) === 1;

    if ($ovActive && $ovStatus !== '' && $ovStatus !== 'AUTO') {
        if ($ovStatus === 'DISPONIBLE') {
            return ['label' => 'Disponible', 'class' => 'status-ok'];
        }
        return ['label' => 'No disponible', 'class' => 'status-no'];
    }

    // 2) AUTO => horario
    if ($dow === 7) { // domingo
        return ['label' => 'No disponible', 'class' => 'status-no'];
    }

    // Sábado usa sat_pattern si existe
    if ($dow === 6) {
        $sat = trim((string)($row['sat_pattern'] ?? ''));
        if ($sat === '') {
            return ['label' => 'No disponible', 'class' => 'status-no'];
        }
        $shiftParsed = parseShift($sat);
    } else {
        $shiftParsed = parseShift((string)($row['shift'] ?? ''));
    }

    if (!$shiftParsed) {
        return ['label' => 'No disponible', 'class' => 'status-no'];
    }

    $shiftStart = $shiftParsed['start'] . ':00';
    $shiftEnd   = $shiftParsed['end']   . ':00';

    $inShift = timeBetween($nowTime, $shiftStart, $shiftEnd);

    $lunchStart = $row['lunch_start'] ?? null; // time (H:i:s)
    $lunchEnd   = $row['lunch_end'] ?? null;

    $inLunch = false;
    if ($lunchStart && $lunchEnd) {
        $inLunch = timeBetween($nowTime, (string)$lunchStart, (string)$lunchEnd);
    }

    if ($inShift && !$inLunch) {
        return ['label' => 'Disponible', 'class' => 'status-ok'];
    }

    return ['label' => 'No disponible', 'class' => 'status-no'];
}

// ============================
// Filtro de área
// ============================
$selectedArea = trim((string)($_GET['area'] ?? ''));

// Áreas permitidas (solo TI, SAP)
// ============================
$allowedAreas = ['TI', 'SAP']; //'MKT',

$selectedArea = trim((string)($_GET['area'] ?? ''));
if ($selectedArea !== '' && !in_array($selectedArea, $allowedAreas, true)) {
    $selectedArea = ''; // si intentan meter otra cosa, lo ignoramos
}

$areas = $allowedAreas; // dropdown fijo


// ============================
// Consulta equipo (Admin + Analista)
// users: name, last_name, email, celular, rol, area
// ============================
$now = new DateTime();
$nowStr = $now->format('Y-m-d H:i:s');

$sql = "
SELECT
    u.id,
    u.name,
    u.last_name,
    u.email,
    u.celular,
    u.area,
    u.rol,

    sch.shift,
    sch.sat_pattern,
    sch.lunch_start,
    sch.lunch_end,

    ov.status AS ov_status,

    CASE
        WHEN ov.id IS NULL THEN 0
        WHEN ov.status = 'AUTO' THEN 0
        WHEN (
            (ov.starts_at IS NOT NULL AND ov.ends_at IS NOT NULL AND ? BETWEEN ov.starts_at AND ov.ends_at)
            OR (ov.starts_at IS NOT NULL AND ov.until_at IS NOT NULL AND ? BETWEEN ov.starts_at AND ov.until_at)
            OR (ov.starts_at IS NULL AND ov.ends_at IS NULL AND ov.until_at IS NULL)
        )
        THEN 1
        ELSE 0
    END AS ov_active

FROM users u
LEFT JOIN analyst_schedules sch
    ON sch.user_id = u.id
LEFT JOIN analyst_status_overrides ov
    ON ov.user_id = u.id
    AND ov.id = (
        SELECT o2.id
        FROM analyst_status_overrides o2
        WHERE o2.user_id = u.id
        ORDER BY o2.created_at DESC
        LIMIT 1
    )
WHERE u.rol IN (2,3)
";

$params = [$nowStr, $nowStr];

if ($selectedArea !== '') {
    $sql .= " AND u.area = ? ";
    $params[] = $selectedArea;
}


$sql .= " AND u.area IN ('TI','SAP') "; //,'MKT'

$team = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $team = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $team = [];
}
?>

<link rel="stylesheet" href="/HelpDesk_EQF/assets/css/style.css?v=<?php echo time(); ?>">

<div class="user-main">

    <div class="user-main-inner">
        <div class="page-title-block">
            <h1 class="page-title">Disponibilidad de los Equipos</h1>
        </div>

        <div class="page-actions">
            <form method="GET" action="" id="filterForm" class="filter-form">
                <select name="area" class="select" onchange="document.getElementById('filterForm').submit()">
                    <option value="">Todas las áreas</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?php echo h($a); ?>" <?php echo ($selectedArea === $a) ? 'selected' : ''; ?>>
                            <?php echo h($a); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <div class="cards-grid">
        <?php if (empty($team)): ?>
            <div class="empty-state">
                No se encontraron analistas/admin<?php echo $selectedArea ? ' para el área <b>' . h($selectedArea) . '</b>' : ''; ?>.
            </div>
        <?php else: ?>
            <?php foreach ($team as $m): ?>
                <?php
                    $fullName = trim(($m['name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                    $role = roleLabel((int)($m['rol'] ?? 0));
                    $avail = computeUserAvailability($m, $now);

                    $initials = '';
                    foreach (preg_split('/\s+/', $fullName) as $part) {
                        if ($part !== '') $initials .= mb_substr($part, 0, 1);
                        if (mb_strlen($initials) >= 2) break;
                    }
                    $initials = mb_strtoupper($initials ?: 'EQ');
                ?>

                <div class="support-card">
                    <div class="support-card__top">
                        <div class="support-card__person">
                            <div class="support-card__avatar"><?php echo h($initials); ?></div>

                            <div class="support-card__info">
                                <div class="support-card__name"><?php echo h($fullName ?: 'Sin nombre'); ?></div>
                                <div class="support-card__meta">
                                    <span class="support-card__role"><?php echo h($role); ?></span>
                                    <span class="support-card__sep">•</span>
                                    <span class="support-card__area"><?php echo h($m['area'] ?? 'Sin área'); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="support-card__status">
                            <span class="status-pill <?php echo h($avail['class']); ?>">
                                <?php echo h($avail['label']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="support-card__body">
                        <div class="support-row">
                            <span class="support-row__label">Correo</span>
                            <?php if (!empty($m['email'])): ?>
                                <a class="support-row__value" href="mailto:<?php echo h($m['email']); ?>">
                                    <?php echo h($m['email']); ?>
                                </a>
                            <?php else: ?>
                                <span class="support-row__value">—</span>
                            <?php endif; ?>
                        </div>

                        <div class="support-row">
                            <span class="support-row__label">Celular</span>
                            <?php if (!empty($m['celular'])): ?>
                                <a class="support-row__value" href="tel:<?php echo h($m['celular']); ?>">
                                    <?php echo h($m['celular']); ?>
                                </a>
                            <?php else: ?>
                                <span class="support-row__value">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script src="/HelpDesk_EQF/assets/js/script.js?v=<?php echo time(); ?>"></script>
<?php include __DIR__ . '/../../../template/footer.php'; ?>
