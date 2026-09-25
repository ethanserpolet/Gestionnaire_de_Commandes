<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $hostPort = Config::required('DATABASE_HOST');
        $host = $hostPort;
        $port = '3306';
        if (str_contains($hostPort, ':')) {
            [$host, $port] = explode(':', $hostPort, 2);
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            Config::required('DATABASE_NAME')
        );

        try {
            self::$instance = new PDO(
                $dsn,
                Config::required('DATABASE_USER'),
                Config::required('DATABASE_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                ]
            );
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            throw new \RuntimeException('Connexion à la base de données impossible.');
        }

        return self::$instance;
    }
}
