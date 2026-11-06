<?php
/** Regression: real Admin → Receiver preflight, synthetic sites, no database or transport. */
namespace Emonks\Factory\Online {
    final class Sites {
        public static bool $admin=true;
        public static bool $local=false;
        public static function requireAdmin(): void {if(!self::$admin){throw new \RuntimeException('Denied');}}
        public static function local(): bool {return self::$local;}
        public static function site(int $id): object {
            self::requireAdmin();
            if(!isset($GLOBALS['themes'][$id])){throw new \RuntimeException('Unmanaged site');}
            return (object)['archived'=>false,'deleted'=>false];
        }
        public static function prospect(int $id): array {return ['slug'=>'fixture'];}
    }
    final class Accounts {public static function silent(callable $callback) {return $callback();}}
    final class Store {
        public static function root(): string {return '/unused';}
        public static function locked($key,callable $callback) {return $callback();}
        public static function key(string $key): string {return $key;}
        public static function read($key): array {throw new \RuntimeException('Reached linked-site lookup');}
    }
    final class Client {
        public static function manage(...$args): array {return ['remote_theme'=>\Emonks\Factory\ThemeRelease::remoteSlug()];}
    }
}
namespace {
    define('EMSF_PRIVATE_HOSTING_VERIFIED',true);define('ACF_PRO',true);
    $themes=[2=>'skeletor-master',3=>'another-theme',4=>'missing-theme',5=>'../invalid'];
    $installed=['skeletor-master','another-theme'];$themeReads=0;
    function get_site_option($key,$default=false) {return $key==='emsf_remote_theme_slug'?'skeletor-master':$default;}
    function get_blog_option($id,$key) {++$GLOBALS['themeReads'];return $GLOBALS['themes'][$id];}
    function get_site_meta(...$args) {return 'synthetic-source';}
    function get_current_network_id() {return 1;}
    function is_subdomain_install() {return false;}
    function acf_get_field() {}
    function wp_get_theme($slug) {return new class($slug) {
        public function __construct(private string $slug) {}
        public function exists(): bool {return in_array($this->slug,$GLOBALS['installed'],true);}
    };}
    function wp_json_encode($data) {return json_encode($data);}
    final class WP_REST_Request implements ArrayAccess {
        private array $data=[];
        public function __construct(...$args) {}
        public function set_header(...$args): void {}
        public function set_body($body): void {$this->data=json_decode($body,true);}
        public function offsetExists(mixed $offset): bool {return isset($this->data[$offset]);}
        public function offsetGet(mixed $offset): mixed {return $this->data[$offset]??null;}
        public function offsetSet(mixed $offset,mixed $value): void {$this->data[$offset]=$value;}
        public function offsetUnset(mixed $offset): void {unset($this->data[$offset]);}
    }
    require __DIR__.'/../includes/ThemeRelease.php';
    require __DIR__.'/../includes/Online/Receiver.php';
    require __DIR__.'/../includes/Online/Admin.php';
    use Emonks\Factory\Online\{Admin,Sites};
    use Emonks\Factory\ThemeRelease;
    $count=0;
    $expect=static function($ok,$message)use(&$count) {if(!$ok){throw new RuntimeException($message);}++$count;};
    $run=static function($id,$extra=[]) {
        try{Admin::run($id,['scope'=>'online','site_action'=>'preview_get']+$extra);}
        catch(RuntimeException $e){return $e->getMessage();}
        throw new RuntimeException('Expected fixture stop');
    };
    $expect(ThemeRelease::slug()==='skeletor','Fixture reproduces absent default theme');
    foreach([2,3]as$id){
        $expect($run($id,['theme_slug'=>'attacker-choice'])==='Reached linked-site lookup','Installed active theme passes actual preflight');
        $expect(ThemeRelease::slug()===$themes[$id],'Validated target site determines theme on every call');
    }
    $expect(str_contains($run(4),'De ingestelde themamap ontbreekt: missing-theme'),'A genuinely missing active theme still blocks');
    $expect(str_contains($run(5),'Gebruik voor de themamap'),'Invalid stored theme rejected');
    $before=$themeReads;Sites::$admin=false;
    $expect($run(2)==='Denied'&&$themeReads===$before,'Authorization precedes theme lookup');
    Sites::$admin=true;
    $expect($run(999)==='Unmanaged site'&&$themeReads===$before,'Managed-site validation precedes theme lookup');
    Sites::$local=true;
    $result=Admin::run(2,['scope'=>'online','site_action'=>'preview_get']);
    $expect($result['remote_theme']==='skeletor-master'&&$themeReads===$before,'Local route retains configured remote theme');
    echo "PASS: $count online admin theme checks\n";
}
