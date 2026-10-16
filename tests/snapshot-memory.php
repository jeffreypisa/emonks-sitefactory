<?php
/** Standalone regression: synthetic snapshot under 128 MiB, no WordPress database. */
declare(strict_types=1);
namespace { function wp_raise_memory_limit($context) { $GLOBALS['memory_context'] = $context; } }
namespace Emonks\Factory\Online {
final class Privacy { public static function managed(): bool { return true; } }
function get_current_blog_id() { return 2; }
function get_main_site_id() { return 1; }
function wp_generate_uuid4() { return 'memory-test'; }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0700, true); }
ini_set('memory_limit', '128M');
$root = sys_get_temp_dir().'/emsf-memory-'.bin2hex(random_bytes(6));
mkdir($root); mkdir($root.'/web');
define('ABSPATH', $root.'/web/'); define('EMSF_STATE_DIR', $root); define('ARRAY_A', 'ARRAY_A'); define('ARRAY_N', 'ARRAY_N');
require_once dirname(__DIR__).'/includes/Online/Store.php';
require_once dirname(__DIR__).'/includes/Online/Snapshot.php';
$wpdb = new class {
    public string $last_error = '';
    public array $queries = [];
    public function get_blog_prefix($id) { return 'wp_2_'; }
    public function esc_like($value) { return $value; }
    public function prepare($sql, ...$args) { return $sql; }
    public function query($sql) { $this->queries[]=$sql; return 1; }
    public function get_row($sql, $format) { return ['wp_2_posts','CREATE TABLE wp_2_posts (ID bigint)']; }
    public function get_results($sql, $format) {
        if (str_starts_with($sql, 'SHOW')) { return [['Name'=>'wp_2_posts','Engine'=>'InnoDB']]; }
        $rows=[];
        for ($i=0;$i<80000;$i++) { $rows[]=['ID'=>(string)$i,'post_title'=>'Synthetic title '.$i,'post_content'=>str_repeat('x',128)]; }
        return $rows;
    }
};
// Represent memory already occupied by WordPress and the active theme.
$baseline = str_repeat('x', 32*1024*1024);
try {
    $id = Snapshot::create();
    if (($GLOBALS['memory_context']??'') !== 'admin') { throw new \RuntimeException('Admin memory allowance not requested.'); }
    if (end($wpdb->queries) !== 'ROLLBACK') { throw new \RuntimeException('Snapshot transaction not closed.'); }
    unset($baseline);
    $saved=Store::read($id);
    if (count($saved['tables']['wp_2_posts']['rows'])!==80000 || $saved['tables']['wp_2_posts']['rows'][79999]['post_title']!=='Synthetic title 79999') { throw new \RuntimeException('Backup content lost.'); }
    if ((fileperms($root.'/'.$id.'.json') & 0777)!==0600) { throw new \RuntimeException('Backup is not private.'); }
    if (glob($root.'/.journal-*')) { throw new \RuntimeException('Temporary journals leaked.'); }
    echo 'Snapshot verified under 128 MiB; 80,000 rows preserved; peak '.round(memory_get_peak_usage(true)/1024/1024).' MiB. No site deleted.'.PHP_EOL;
} finally {
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) { $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname()); }
    rmdir($root);
}

}
