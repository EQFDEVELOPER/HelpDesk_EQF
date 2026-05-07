<?php
// /HelpDesk_EQF/auth/remember.php
declare(strict_types=1);

function remember_cookie_name(): string {
  return 'eqf_remember';
}

function remember_set_cookie(string $value, int $expiresTs): void {
  $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
  setcookie(remember_cookie_name(), $value, [
    'expires'  => $expiresTs,
    'path'     => '/HelpDesk_EQF/',   // importante: que aplique a tu app
    'domain'   => '',                // deja vacío (host actual). Solo pon dominio si sabes lo que haces.
    'secure'   => $secure,           // en producción ideal HTTPS = true
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

function remember_clear_cookie(): void {
  setcookie(remember_cookie_name(), '', [
    'expires'  => time() - 3600,
    'path'     => '/HelpDesk_EQF/',
    'domain'   => '',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

function remember_create(PDO $pdo, int $userId, int $days = 30): void {
  $selector = bin2hex(random_bytes(12));   // 24 chars
  $token    = bin2hex(random_bytes(32));   // 64 chars
  $hash     = hash('sha256', $token);

  $expiresTs = time() + ($days * 24 * 60 * 60);
  $expiresAt = date('Y-m-d H:i:s', $expiresTs);

  // Limpia tokens expirados del usuario (opcional)
  $pdo->prepare("DELETE FROM auth_remember_tokens WHERE user_id = ? AND expires_at < NOW()")
      ->execute([$userId]);

  $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
  $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);

  $stmt = $pdo->prepare("
    INSERT INTO auth_remember_tokens (user_id, token_hash, selector, expires_at, user_agent, ip)
    VALUES (?, ?, ?, ?, ?, ?)
  ");
  $stmt->execute([$userId, $hash, $selector, $expiresAt, $ua, $ip]);

  // cookie: selector.token
  remember_set_cookie($selector . '.' . $token, $expiresTs);
}

function remember_delete_by_selector(PDO $pdo, string $selector): void {
  $pdo->prepare("DELETE FROM auth_remember_tokens WHERE selector = ?")->execute([$selector]);
}

function remember_delete_all_user(PDO $pdo, int $userId): void {
  $pdo->prepare("DELETE FROM auth_remember_tokens WHERE user_id = ?")->execute([$userId]);
}

function remember_try_login(PDO $pdo): ?array {
  if (!empty($_SESSION['user_id'])) return null;

  $cookie = $_COOKIE[remember_cookie_name()] ?? '';
  if (!$cookie || strpos($cookie, '.') === false) return null;

  [$selector, $token] = explode('.', $cookie, 2);
  if ($selector === '' || $token === '') return null;

  $stmt = $pdo->prepare("
    SELECT rt.user_id, rt.token_hash, rt.expires_at,
           u.id, u.number_sap, u.name, u.last_name, u.email, u.rol, u.area, u.must_change_password
    FROM auth_remember_tokens rt
    JOIN users u ON u.id = rt.user_id
    WHERE rt.selector = ?
    LIMIT 1
  ");
  $stmt->execute([$selector]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    remember_clear_cookie();
    return null;
  }

  if (strtotime($row['expires_at']) < time()) {
    remember_delete_by_selector($pdo, $selector);
    remember_clear_cookie();
    return null;
  }

  $calc = hash('sha256', $token);
  if (!hash_equals($row['token_hash'], $calc)) {
    // posible robo → elimina ese token
    remember_delete_by_selector($pdo, $selector);
    remember_clear_cookie();
    return null;
  }

  session_regenerate_id(true);
  $_SESSION['user_id']    = (int)$row['id'];
  $_SESSION['user_name']  = $row['name'];
  $_SESSION['user_last']  = $row['last_name'];
  $_SESSION['user_email'] = $row['email'];
  $_SESSION['user_rol']   = (int)$row['rol'];
  $_SESSION['user_area']  = $row['area'];
  $_SESSION['number_sap'] = $row['number_sap'];

  remember_delete_by_selector($pdo, $selector);
  remember_create($pdo, (int)$row['id'], 30);

  $pdo->prepare("UPDATE auth_remember_tokens SET last_used_at = NOW() WHERE selector = ?")
      ->execute([$selector]);

  return $row;
}
