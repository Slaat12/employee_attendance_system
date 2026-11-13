<?php
declare(strict_types=1);

/**
 * Temporary file-backed store so the application works before PostgreSQL is
 * connected. Every other class talks to data ONLY through this adapter, so
 * swapping in PostgreSQL later means replacing this one class / its callers.
 *
 * Safety guarantees:
 *  - One global lock file: readers take a shared lock, writers an exclusive
 *    lock, so nobody ever reads a half-written file.
 *  - update() holds the exclusive lock across read -> modify -> write, so two
 *    people checking in at the same moment cannot overwrite each other.
 *  - A corrupted file raises an error instead of silently reading as empty
 *    (which would wipe real data on the next save).
 */
final class JsonStore {
    private static bool $held = false;

    private static function path(string $name): string {
        return APP_ROOT . '/storage/' . $name . '.json';
    }

    private static function locked(int $mode, callable $fn): mixed {
        if (self::$held) return $fn();
        $dir = APP_ROOT . '/storage';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the storage folder.');
        }
        $fp = fopen($dir . '/.lock', 'c');
        if (!$fp) throw new RuntimeException('Cannot open the storage lock. Check folder permissions on /storage.');
        try {
            if (!flock($fp, $mode)) throw new RuntimeException('Cannot lock the data store.');
            self::$held = true;
            return $fn();
        } finally {
            self::$held = false;
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private static function readRaw(string $name): array {
        $path = self::path($name);
        if (!is_file($path)) return [];
        $raw = file_get_contents($path);
        if ($raw === false) throw new RuntimeException("Cannot read data file '$name'.");
        if (trim($raw) === '') return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new RuntimeException("Data file '$name' is corrupted. Restore it from a backup or delete it to reset.");
        return $data;
    }

    private static function writeRaw(string $name, array $data): void {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new RuntimeException("Cannot encode data for '$name'.");
        $path = self::path($name);
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json) === false) throw new RuntimeException("Cannot write data file '$name'. Check folder permissions on /storage.");
        if (!@rename($tmp, $path)) {            // Windows can refuse rename-over-existing; fall back.
            if (file_put_contents($path, $json) === false) throw new RuntimeException("Cannot write data file '$name'.");
            @unlink($tmp);
        }
    }

    public static function read(string $name): array {
        return self::locked(LOCK_SH, fn() => self::readRaw($name));
    }

    public static function write(string $name, array $data): void {
        self::locked(LOCK_EX, function () use ($name, $data) { self::writeRaw($name, $data); return null; });
    }

    /**
     * Atomic read-modify-write.
     * $fn receives the current data and must return [newData|null, result].
     * Returning null as newData means "no change, do not write".
     */
    public static function update(string $name, callable $fn): mixed {
        return self::locked(LOCK_EX, function () use ($name, $fn) {
            [$new, $result] = $fn(self::readRaw($name));
            if ($new !== null) self::writeRaw($name, $new);
            return $result;
        });
    }

    public static function nextIdIn(array $rows): int {
        return $rows ? ((int) max(array_column($rows, 'id')) + 1) : 1;
    }

    /** Create demo data on first run, and repair demo accounts that have no usable hash. */
    public static function initialize(): void {
        self::locked(LOCK_EX, function () {
            $marker = APP_ROOT . '/storage/.ready-v2';
            $missing = false;
            foreach (['employees','attendance','departments','settings','admins','leaves','throttle'] as $n) {
                if (!is_file(self::path($n))) { $missing = true; break; }
            }
            if (!$missing && is_file($marker)) return null;

            $demoEmployees = [
                [1,'EMP001','Ama','Mensah','ama@example.com','0241234567','Administration','1234'],
                [2,'EMP002','Kofi','Asare','kofi@example.com','0551234567','Finance','2468'],
                [3,'EMP003','Esi','Boateng','esi@example.com','0201234567','IT','1357'],
            ];
            $defaults = [
                'employees'   => fn() => array_map(fn($d) => [
                    'id'=>$d[0],'employee_id'=>$d[1],'first_name'=>$d[2],'last_name'=>$d[3],'email'=>$d[4],'phone'=>$d[5],
                    'department'=>$d[6],'pin_hash'=>password_hash($d[7], PASSWORD_DEFAULT),'status'=>'active','created_at'=>now_string(),
                ], $demoEmployees),
                'attendance'  => fn() => [],
                'departments' => fn() => ['Administration','Finance','IT','Human Resources','Operations'],
                'settings'    => fn() => ['company_name'=>'Northstar Company','start_time'=>'08:00','end_time'=>'17:00','grace_minutes'=>15,'work_days'=>[1,2,3,4,5]],
                'admins'      => fn() => [['id'=>1,'name'=>'System Administrator','email'=>'admin@northstar.local','password_hash'=>password_hash('Admin@12345', PASSWORD_DEFAULT)]],
                'leaves'      => fn() => [],
                'throttle'    => fn() => [],
            ];
            foreach ($defaults as $name => $make) {
                if (!is_file(self::path($name))) self::writeRaw($name, $make());
            }

            // Repair: an older package shipped demo accounts with null hashes, which made sign-in impossible.
            $admins = self::readRaw('admins'); $changed = false;
            foreach ($admins as &$a) {
                if (!is_string($a['password_hash'] ?? null) || $a['password_hash'] === '') {
                    $a['password_hash'] = password_hash('Admin@12345', PASSWORD_DEFAULT); $changed = true;
                }
            }
            unset($a);
            if ($changed) self::writeRaw('admins', $admins);

            $pins = [1=>'1234', 2=>'2468', 3=>'1357'];
            $emps = self::readRaw('employees'); $changed = false;
            foreach ($emps as &$x) {
                if (!is_string($x['pin_hash'] ?? null) || $x['pin_hash'] === '') {
                    $x['pin_hash'] = password_hash($pins[(int)$x['id']] ?? bin2hex(random_bytes(4)), PASSWORD_DEFAULT); $changed = true;
                }
            }
            unset($x);
            if ($changed) self::writeRaw('employees', $emps);

            @file_put_contents(APP_ROOT . '/storage/.htaccess', "Require all denied\n");
            @file_put_contents($marker, date('c'));
            return null;
        });
    }
}
