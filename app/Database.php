
<?php

class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {

            require_once dirname(__DIR__) . '/config/database.php';

            self::$connection = getDatabaseConnection();
        }

        return self::$connection;
    }
}
