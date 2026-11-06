<?php
/** Execute the real privacy gate with synthetic WordPress requests; no network/database. */
namespace Emonks\Factory\Online {
    final class Preview {
        public static function captureShare(): void {}
        public static function allowed(): bool {return false;}
        public static function mode(int $id): string {return $GLOBALS['mode'];}
        public static function frontend(): bool {return !is_admin()&&!wp_doing_ajax()&&!(defined('REST_REQUEST')&&REST_REQUEST);}
        public static function screen(): void {throw new \RuntimeException('preview');}
    }
}
namespace {
    if(($argv[1]??'')==='rest'){define('REST_REQUEST',true);}
    $admin=true;$loggedIn=false;$member=false;$ajax=false;$state='ready';$mode='public';$super=false;
    function get_current_blog_id() {return 2;}
    function get_current_user_id() {return 5;}
    function get_site_meta($id,$key,$single=true) {return $key==='emsf_managed'?true:$GLOBALS['state'];}
    function get_site($id) {return (object)['archived'=>false,'deleted'=>false,'spam'=>false];}
    function is_super_admin() {return $GLOBALS['super'];}
    function is_user_logged_in() {return $GLOBALS['loggedIn'];}
    function is_user_member_of_blog(...$args) {return $GLOBALS['member'];}
    function is_admin() {return $GLOBALS['admin'];}
    function wp_doing_ajax() {return $GLOBALS['ajax'];}
    function wp_doing_cron() {return false;}
    function is_ssl() {return true;}
    function nocache_headers() {}
    function home_url($path) {return 'https://example.test/systole'.$path;}
    function auth_redirect() {throw new RuntimeException('login');}
    function wp_die($message,...$args) {throw new RuntimeException($message);}
    require __DIR__.'/../includes/Online/Privacy.php';
    use Emonks\Factory\Online\Privacy;
    $count=0;
    $run=static function(string $script,string $path): string {
        $_SERVER=['SCRIPT_NAME'=>$script,'REQUEST_URI'=>$path,'REQUEST_METHOD'=>'GET'];
        try{Privacy::gate();return 'allowed';}catch(RuntimeException $e){return $e->getMessage();}
    };
    $expect=static function($actual,$expected)use(&$count) {
        if($actual!==$expected){throw new RuntimeException("Expected $expected, received $actual");}++$count;
    };
    if(defined('REST_REQUEST')){
        $expect($run('/systole/wp-admin/index.php','/systole/wp-json/'),'Dit voorstel is afgeschermd.');
    }else{
        foreach(['public','code','closed']as$mode){
            $expect($run('/systole/wp-admin/index.php','/systole/wp-admin/'),'login');
            $expect($run('/systole/wp-admin/post.php','/systole/wp-admin/post.php?post=123&action=edit'),'login');
        }
        $mode='public';$ajax=true;
        $expect($run('/systole/wp-admin/admin-ajax.php','/systole/wp-admin/admin-ajax.php'),'Dit voorstel is afgeschermd.');
        $ajax=false;
        foreach(['admin-post.php','async-upload.php']as$script){$expect($run('/systole/wp-admin/'.$script,'/systole/wp-admin/'.$script),'Dit voorstel is afgeschermd.');}
        $expect($run('/systole/wp-login.php','/systole/wp-login.php'),'allowed');
        $loggedIn=true;
        $expect($run('/systole/wp-admin/index.php','/systole/wp-admin/'),'Dit voorstel is afgeschermd.');
        $member=true;
        $expect($run('/systole/wp-admin/index.php','/systole/wp-admin/'),'allowed');
        $state='archived';
        $expect($run('/systole/wp-admin/index.php','/systole/wp-admin/'),'Dit voorstel is tijdelijk offline.');
        $loggedIn=false;$member=false;$state='ready';$admin=false;
        $expect($run('/systole/index.php','/systole/'),'allowed');
        $mode='closed';
        $expect($run('/systole/index.php','/systole/'),'preview');
    }
    echo "PASS: $count admin login checks\n";
}
