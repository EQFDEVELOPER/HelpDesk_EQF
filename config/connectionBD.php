<?php
date_default_timezone_set('America/Mexico_City');

class Database
{
    private static $instance = null;
    private $connection;

    private function __construct()
    {
        $host = 'eqfhelpdesk.com.mx';
        $db   = 'eqfhelpd_eqf_helpdesk';
        $user = 'eqfhelpd_ti';
        $pass = '8[2cYiY5g)c4OD';
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

        try {
            $this->connection = new PDO(
                $dsn,
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );

            $this->connection->exec("SET time_zone = '-06:00'");
            $this->connection->exec("SET NAMES utf8mb4");

        } catch (PDOException $e) {
            die('Error de conexi¨®n a la base de datos: ' . $e->getMessage());
        }
    }

    public static function getConnection()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance->connection;
    }
}
