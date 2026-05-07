<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/connectionBD.php';
require_once __DIR__ . '/../auth/remember.php';

$pdo = Database::getConnection();

remember_try_login($pdo);
?>

<header class="eqf-header">
    <div class="eqf-header-left">
        <img src="/HelpDesk_EQF/assets/img/MESADEAYUDA.svg" alt="Helpdesk EQF" class="header-icon">
    </div>

    <div class="eqf-header-right">
        <img src="/HelpDesk_EQF/assets/img/Logo-334x98.png" alt="EQF Logo" class="header-logo">
    </div>
</header>
