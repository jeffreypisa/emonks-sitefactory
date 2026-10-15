<?php
declare(strict_types=1);
namespace Emonks\Factory;
$register=static function(string $name,callable $fn){\WP_CLI::add_command('emonks factory '.$name,static function($args,$assoc)use($fn){try{$result=$fn($args,$assoc);\WP_CLI::line(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}catch(\Throwable $e){\WP_CLI::error($e->getMessage());}});};
$register('prospect list',static function(){Factory::local();return Factory::prospects();});
$register('prospect show',static function($a){Factory::local();return Factory::find($a[0]??'');});
foreach(['create','update'] as $action){$register('prospect '.$action,static function($a){$data=json_decode((string)file_get_contents($a[0]??''),true,512,JSON_THROW_ON_ERROR);return Factory::save($data);});}
$register('site create',static fn($a)=>Factory::site($a[0]??''));
$register('schema export',static function($a){Factory::local();$groups=[];foreach(acf_get_field_groups() as $g){$g['fields']=acf_get_fields($g);$groups[]=$g;}$result=['site'=>get_current_blog_id(),'theme'=>wp_get_theme()->get('Version'),'groups'=>$groups,'options_pages'=>acf_get_options_pages()];Package::json($a[0],$result);return ['path'=>$a[0],'groups'=>count($groups)];});
$register('package export',static fn($a)=>Package::export($a[0]));
$register('package validate',static function($a){$p=Package::read($a[0]);return ['valid'=>true,'posts'=>count($p['posts']),'files'=>count($p['files'])];});
$register('package models',static fn($a)=>Package::models($a[0]));
$register('package import',static fn($a,$o)=>Package::import($a[0],isset($o['apply'])));
$register('review',static fn($a)=>Factory::save(['slug'=>$a[0],'status'=>'REVIEW']));
$register('approve',static function($a){$p=Factory::find($a[0]);if(!$p['site_id']||$p['status']!=='REVIEW'){throw new \RuntimeException('Alleen een site in REVIEW kan goedgekeurd worden.');}return Factory::save(['slug'=>$a[0],'status'=>'APPROVED']);});
$register('feedback add',static function($a){Factory::local();$p=Factory::find($a[0]);$feedback=json_decode((string)file_get_contents($a[1]),true,512,JSON_THROW_ON_ERROR);if(($feedback['scope']??'prospect')!=='prospect'){throw new \RuntimeException('Algemene feedback eerst redigeren in de gedeelde regelbestanden.');}$dir=Factory::state().'/prospects/'.$p['slug'];wp_mkdir_p($dir);$feedback['created_at']=gmdate('c');Package::json($dir.'/feedback-'.wp_generate_uuid4().'.json',$feedback);return ['saved'=>true,'scope'=>'prospect'];});

$register('build validate',static fn($a)=>Build::run($a[0],false));
$register('build apply',static fn($a)=>Build::run($a[0],true));

$register('online status', static fn($a)=>Online\Client::status($a[0]??''));
$register('online create', static fn($a,$o)=>Online\Client::push($a[0]??'', 'create','',['name'=>$o['customer-name']??'','username'=>$o['customer-username']??'','email'=>$o['customer-email']??'','confirmed'=>isset($o['customer-confirmed']),'layout_access'=>isset($o['layout-access'])]));
$register('online replace', static function($a,$o){
    if (!isset($o['confirm'])) { throw new \RuntimeException('Online wijzigingen worden vervangen. Vereist --confirm="Exacte online sitenaam".'); }
    return Online\Client::push($a[0]??'', 'replace', $o['confirm']);
});
$register('online manage', static fn($a,$o)=>Online\Client::manage($a[0]??'', $a[1]??'', $o['confirm']??'', ['operation'=>$o['operation']??'', 'email'=>$o['email']??'', 'layout_access'=>isset($o['layout-access']), 'delete_confirm'=>$o['delete-confirm']??'', 'mail_mode'=>$o['mail-mode']??'']));
