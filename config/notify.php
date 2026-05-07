<?php


function notify_user(PDO $pdo, int $userId, string $type, string $title, string $body, ?string $link = null): void {
    $type  = trim($type);
    $title = trim($title);
    $body  = trim($body);

    if ($type === '')  $type = 'system';
    if ($title === '') $title = 'HelpDesk EQF';
    if ($body === '')  $body = 'Tienes una actualización.';

    if (mb_strlen($type)  > 40)  $type  = mb_substr($type, 0, 40);
    if (mb_strlen($title) > 120) $title = mb_substr($title, 0, 120);
    if (mb_strlen($body)  > 255) $body  = mb_substr($body, 0, 255);
    if ($link !== null && mb_strlen($link) > 255) $link = mb_substr($link, 0, 255);

    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_ide, type, title, body, link, is_read, created_at)
        VALUES (:uid, :type, :title, :body, :link, 0, NOW())
    ");
    $stmt->execute([
        ':uid'   => $userId,
        ':type'  => $type,
        ':title' => $title,
        ':body'  => $body,
        ':link'  => $link
    ]);
}

function notify_many(PDO $pdo, array $userIds, string $type, string $title, string $body, ?string $link = null): void {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $userIds = array_filter($userIds, fn($x) => $x > 0);
    if (!$userIds) return;

    foreach ($userIds as $uid) {
        notify_user($pdo, (int)$uid, $type, $title, $body, $link);
    }
}
