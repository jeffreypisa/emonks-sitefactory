<?php
declare(strict_types=1);
// Standalone regression: no WordPress, database, network, or publication writes.
namespace Emonks\Factory\Online {
    final class Sites {
        public static function requireAdmin(): void {}
        public static function local(): bool { return true; }
        public static function url(int $id,string $tab): string { return '/site/'.$id.'/'.$tab; }
    }
    if (($GLOBALS['argv'][1]??'')==='ajax') {
        final class Admin {
            public static function run(int $id,array $data): array { return $GLOBALS['fixture']; }
        }
    }
}
namespace {
    function add_action($name,$callback) { $GLOBALS['actions'][$name]=$callback; }
    function absint($value) { return abs((int)$value); }
    function check_ajax_referer(...$args) {}
    function wp_unslash($value) { return $value; }
    function esc_html($value) { return htmlspecialchars((string)$value,ENT_QUOTES); }
    function esc_url($value) { return $value; }
    function network_admin_url($value) { return '/network/'.$value; }
    function get_site($id) { return false; }
    function wp_die($html,...$args) { $GLOBALS['response']=['html'=>$html]; }
    function wp_send_json_success($data) { $GLOBALS['response']=$data; }
    function wp_send_json_error($data,...$args) { throw new \RuntimeException('Unexpected error: '.json_encode($data)); }
    set_error_handler(static function($severity,$message,$file,$line) { throw new \ErrorException($message,0,$severity,$file,$line); });
    $root=$argv[2]??dirname(__DIR__).'/includes/Online';
    require $root.'/Publication.php';
    $ajax=($argv[1]??'')==='ajax';
    if($ajax) {
        \Emonks\Factory\Online\Publication::boot();
        $_POST=['site_id'=>2,'site_action'=>'create'];
    } else {
        require $root.'/Admin.php';
        $render=new \ReflectionMethod(\Emonks\Factory\Online\Admin::class,'result');
    }
    $cases=[
        'completed publication'=>[['publication_check'=>true,'operation'=>['status'=>'complete']],false,'Publicatie voltooid.'],
        'status capability'=>[['publication_check'=>true,'protocol'=>4],false,'Publicatie voltooid.'],
        'no report'=>[[],false,'Publicatie voltooid.'],
        'successful preflight'=>[['publication_check'=>['ok'=>true]],true,'Controle afgerond. Bekijk hieronder het resultaat.'],
        'failed preflight'=>[['publication_check'=>['ok'=>false,'issues'=>['Testblokkade']]],true,'Controle afgerond: er zijn nog aandachtspunten.'],
    ];
    foreach($cases as $name=>[$fixture,$hasReport,$message]) {
        $GLOBALS['fixture']=$fixture;
        if($ajax) { $GLOBALS['actions']['wp_ajax_emsf_publish'](); }
        else { $render->invoke(null,2,$fixture); }
        $response=$GLOBALS['response'];
        if(str_contains($response['html'],'Controleresultaat')!==$hasReport) { throw new \RuntimeException($name.': incorrect report rendering'); }
        if($ajax && $response['message']!==$message) { throw new \RuntimeException($name.': incorrect message'); }
        if($name==='failed preflight' && !str_contains($response['html'],'Testblokkade')) { throw new \RuntimeException('Missing preflight issue'); }
        echo ($ajax?'AJAX':'Page').': '.$name." OK\n";
    }
}
