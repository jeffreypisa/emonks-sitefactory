<?php
declare(strict_types=1);
namespace Emonks\Factory\Online {
    final class Store {
        public static array $status=[];
        public static function read(string $key): array {return self::$status;}
    }
}
namespace {
    use Emonks\Factory\ThemeRelease;
    use Emonks\Factory\Online\{Receiver,Admin,Store};
    $root=sys_get_temp_dir().'/skeletor-release-'.bin2hex(random_bytes(6));mkdir($root);mkdir($root.'/skeletor');
    $GLOBALS['fixture_version']='1.1.0';$GLOBALS['fixture_root']=$root;
    function wp_get_theme($name=''): object {return new class {
        public function get(string $key): string {return $GLOBALS['fixture_version'];}
        public function get_stylesheet_directory(): string {return $GLOBALS['fixture_root'].'/skeletor';}
    };}
    function get_theme_root(): string {return $GLOBALS['fixture_root'];}
    function esc_html($text): string {return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');}
    function wp_date($format,$timestamp): string {return gmdate($format,$timestamp);}
    function get_option($name,$default=false) {return $default;}
    require_once __DIR__.'/../includes/ThemeRelease.php';
    require_once __DIR__.'/../includes/Package.php';
    require_once __DIR__.'/../includes/Online/Receiver.php';
    require_once __DIR__.'/../includes/Online/Admin.php';
    $count=0;
    $check=static function(bool $ok,string $message)use(&$count){if(!$ok){throw new RuntimeException($message);}++$count;};
    $validate=new ReflectionMethod(Receiver::class,'validatePackage');
    $package=['dependencies'=>['theme_version'=>'1.1.0','theme_digest'=>'different'],'posts'=>[]];
    try {
        foreach([['1.1.0','1.1.0','current'],['1.9.0','1.10.0','behind'],['2.0.0','1.10.0','ahead'],['','1.1.0','unknown'],['test','1.1.0','unknown'],['1.1','1.1.0','unknown'],['1.1.0-rc.1','1.1.0','behind'],['1.1.0-rc.1','1.1.0-rc.1','current']] as [$installed,$expected,$state]){$check(ThemeRelease::compare($installed,$expected)===$state,'Version comparison '.$installed);}
        $check(ThemeRelease::compare('1.1.0-foo','1.1.0-bar')!=='current','Different prerelease identifiers cannot match');
        $validate->invoke(null,$package);$check(true,'Equal versions accept different digests');
        unset($package['dependencies']['theme_digest']);$validate->invoke(null,$package);$check(true,'Version does not require a digest');
        foreach(['1.0.0','2.0.0','','test'] as $expected){$package['dependencies']['theme_version']=$expected;$failed=false;try{$validate->invoke(null,$package);}catch(RuntimeException $e){$failed=str_contains($e->getMessage(),'Verwacht:');}$check($failed,'Receiver rejects mismatching/invalid expected version');}
        $package['dependencies']=[];$failed=false;try{$validate->invoke(null,$package);}catch(RuntimeException $e){$failed=true;}$check($failed,'Missing version blocks before import');
        $package['dependencies']=['theme_version'=>['bad']];$failed=false;try{$validate->invoke(null,$package);}catch(RuntimeException $e){$failed=true;}$check($failed,'Malformed version is rejected cleanly');
        $check(ThemeRelease::releases()===[],'Older theme without history remains readable');
        file_put_contents($root.'/skeletor/releases.json',json_encode(['format'=>'skeletor-releases-v1','releases'=>[['version'=>'1.0.0','date'=>'2026-10-08','changes'=>['Baseline']],['version'=>'1.1.0','date'=>'2026-10-08','changes'=>['<script>alert(1)</script>']],['version'=>'broken','date'=>'2026-10-08','changes'=>['Invalid']]]]));
        $check(array_column(ThemeRelease::releases(),'version')===['1.1.0','1.0.0'],'Valid history sorted newest first');
        Store::$status=['theme'=>['version'=>'1.0.0','digest'=>'different'],'checked_at'=>''];
        $render=new ReflectionMethod(Admin::class,'themeInfo');ob_start();$render->invoke(null,['uuid'=>'fixture'],[]);$html=ob_get_clean();
        $check(str_contains($html,'Online gebruikt een oudere'),'Admin shows older online version');
        $check(str_contains($html,'Nog niet online'),'Admin highlights missing releases');
        $check(str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>'),'Release notes are escaped');
        $check(str_contains($html,'Wijzigingen per release'),'History is expandable');
        Store::$status=['theme'=>['version'=>'1.1.0','digest'=>'different']];ob_start();$render->invoke(null,['uuid'=>'fixture'],[]);$html=ob_get_clean();
        $check(str_contains($html,'De themaversies komen overeen.')&&str_contains($html,'bestanden verschillen'),'Same version with differing files is diagnostic');
        file_put_contents($root.'/skeletor/releases.json','{broken');$check(ThemeRelease::releases()===[],'Corrupt history does not break status');
        echo "$count release checks passed.\n";
    } finally {
        foreach(glob($root.'/skeletor/*') as $file){unlink($file);}rmdir($root.'/skeletor');rmdir($root);
    }
}
