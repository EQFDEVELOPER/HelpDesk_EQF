<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. Cargar PHPMailer
require $_SERVER['DOCUMENT_ROOT'] . '/HelpDesk_EQF/vendor/autoload.php';

function sendDailyActivitiesEmail($userData) {
    // 2. Importar el archivo de configuración con ruta absoluta
    $mailConfig = require $_SERVER['DOCUMENT_ROOT'] . '/HelpDesk_EQF/config/mailer.php';

    $mail = new PHPMailer(true);

    try {
        // ========================================
        // CONFIGURACIÓN DEL SERVIDOR DESDE MAILER.PHP
        // ========================================
        $mail->isSMTP();
        $mail->Host       = $mailConfig['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $mailConfig['username'];
        $mail->Password   = $mailConfig['password'];
        $mail->Port       = $mailConfig['port'];
        $mail->CharSet    = $mailConfig['charset'] ?? 'UTF-8';

        if (strtolower($mailConfig['encryption']) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        }

        // Regla para evitar bloqueos de certificados en Localhost / XAMPP
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ];

        // ========================================
        // REMITENTE, DESTINATARIO Y REPLY-TO
        // ========================================
        $mail->setFrom($mailConfig['from_email'], $mailConfig['from_name']);
        
        // Destinatario fijo de pruebas (luego lo cambias al del Gerente si es necesario)
        $mail->addAddress('gerente-ti@eqf.mx', 'Gerente de TI / Soporte');

        // CLAVE: Si el Gerente responde, le llegará al analista logueado
        $analystEmail = $userData['user_email'] ?? $mailConfig['from_email'];
        $analystName  = $userData['user_name'] ?? 'Analista';
        $mail->addReplyTo($analystEmail, $analystName);

        // ========================================
        // ASUNTO SOLICITADO
        // ========================================
        $mail->Subject = 'Reporte Diario de Actividades extraTickets - ' . htmlspecialchars($analystName);

        // ========================================
        // CUERPO DEL CORREO (Diseño Azul y Rojo)
        // ========================================
        $mail->isHTML(true);

        // Construir los puntos de la lista de actividades solicitada
        $htmlActivities = '';
        if (!empty($userData['activities']) && is_array($userData['activities'])) {
            foreach ($userData['activities'] as $act) {
                $cleanAct = trim($act);
                if ($cleanAct !== '') {
                    // Viñetas estilizadas con borde rojo y fondo limpio
                    $htmlActivities .= "
                    <li style='margin-bottom: 10px; line-height: 1.5; color: #374151; padding-left: 5px;'>" 
                        . htmlspecialchars($cleanAct) . 
                    "</li>";
                }
            }
        } else {
            $htmlActivities = "<li style='color: #6b7280; font-style: italic;'>No se detallaron actividades.</li>";
        }

        // Estructura limpia y corporativa utilizando Azul (#14378A) y Rojo (#b91c1c)
        $mail->Body = "
        <div style='background-color: #f4f6f9; padding: 30px 15px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
            <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.08); border-top: 5px solid #b91c1c;'>
                
                <div style='background-color: #14378A; padding: 25px 30px;'>
                    <h1 style='color: #ffffff; font-size: 19px; font-weight: 700; margin: 0; letter-spacing: -0.3px;'>Texto Reporte de Actividades Diarias</h1>
                </div>

                <div style='padding: 20px 30px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 14px;'>
                    <p style='margin: 4px 0; color: #475569;'><strong>Analista:</strong> <span style='color: #1e293b;'>" . htmlspecialchars($analystName) . "</span></p>
                    <p style='margin: 4px 0; color: #475569;'><strong>Fecha:</strong> <span style='color: #1e293b;'>" . date('d/m/Y') . "</span></p>
                </div>

                <div style='padding: 30px; background-color: #ffffff;'>
                    <p style='font-size: 15px; color: #1e293b; margin-top: 0; margin-bottom: 16px;'>Buenas tardes,</p>
                    
                    <p style='font-size: 15px; font-weight: 700; color: #14378A; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px;'>Actividades registradas:</p>
                    
                    <ul style='margin: 0; padding-left: 20px; font-size: 14px; color: #374151;'>
                        {$htmlActivities}
                    </ul>
                    
                    <p style='font-size: 14px; color: #475569; line-height: 1.6; margin-top: 26px; padding-top: 15px; border-top: 1px dashed #e2e8f0;'>
                        Quedo a su disposición por si necesita que profundice en alguno de estos puntos o si considera necesario revisar las prioridades.
                    </p>
                </div>

                <div style='padding: 16px 30px; background-color: #f1f5f9; text-align: center; border-top: 1px solid #e2e8f0;'>
                    <p style='font-size: 11px; color: #94a3b8; margin: 0;'>HelpDesk EQF — Módulo de Reportes Extra-Tickets.</p>
                </div>

            </div>
        </div>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Error de PHPMailer: " . $mail->ErrorInfo);
        return false;
    }
}