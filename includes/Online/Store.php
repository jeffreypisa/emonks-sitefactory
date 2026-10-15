<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;

/** Private, durable journals. One receiver per network, on a single filesystem. */
final class Store {
    public static function root(): string {
        if (!defined('EMSF_STATE_DIR')) { throw new \RuntimeException('EMSF_STATE_DIR ontbreekt.'); }
        $root = realpath(EMSF_STATE_DIR);
        $web = realpath(ABSPATH);
        if (!$root || !$web || $root === $web || str_starts_with($root, $web.'/') || !is_writable($root)) {
            throw new \RuntimeException('State-map moet bestaan, schrijfbaar zijn en buiten de WordPress-webroot staan.');
        }
        // A second document root may exist: the hosting administrator must verify this.
        return $root;
    }
    public static function key(string $key): string {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $key)) { throw new \RuntimeException('Ongeldige sleutel.'); }
        return $key;
    }
    public static function read(string $key): array {
        $path = self::root().'/'.self::key($key).'.json';
        return is_file($path) ? json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
    }
    public static function write(string $key, array $value): void {
        $path = self::root().'/'.self::key($key).'.json';
        $temp = tempnam(self::root(), '.journal-');
        if (!$temp || file_put_contents($temp, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false || !rename($temp, $path)) {
            throw new \RuntimeException('Journal opslaan mislukt; stop en controleer opslag.');
        }
        chmod($path, 0600);
    }
    public static function locked(string $key, callable $fn) {
        $handle = fopen(self::root().'/'.self::key($key).'.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Er draait al een bewerking. Controleer de status.'); }
        try { return $fn(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    public static function copyTree(string $from, string $to): void {
        if (!is_dir($to) && !wp_mkdir_p($to)) { throw new \RuntimeException('Map aanmaken mislukt.'); }
        if (!is_dir($from)) { return; }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if ($item->isLink()) { throw new \RuntimeException('Symlink niet toegestaan in snapshot.'); }
            $target = $to.'/'.substr($item->getPathname(), strlen($from)+1);
            if ($item->isDir()) { if (!wp_mkdir_p($target)) { throw new \RuntimeException('Backupmap mislukt.'); } }
            elseif (!copy($item->getPathname(), $target) || !hash_equals(hash_file('sha256', $item->getPathname()), hash_file('sha256', $target))) {
                throw new \RuntimeException('Backupbestand niet verifieerbaar.');
            }
        }
    }
}
