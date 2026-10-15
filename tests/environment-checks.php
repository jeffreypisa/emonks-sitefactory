<?php
namespace {
 const ACF_PRO=true; const ACF_VERSION='6.8.1'; const EMSF_PRIVATE_HOSTING_VERIFIED=true;
 $settings=['emsf_theme_slug'=>'skeletor','emsf_remote_theme_slug'=>'skeletor-master'];
 function get_site_option($name,$default=null){global $settings;return $settings[$name]??$default;}
 function acf_get_field($key){return [];}
 function wp_get_theme($slug){return new class($slug){public function __construct(private string $slug){}public function get($key){return $key==='Version'?'1.1.8':'Test theme '.$this->slug;}public function exists(){return in_array($this->slug,['skeletor','skeletor-master','new-brand']);}public function is_allowed($check){return true;}public function get_stylesheet_directory(){return '/tmp/'.$this->slug;}};}
 function esc_html($x){return htmlspecialchars((string)$x,ENT_QUOTES);}
 function esc_url($x){return htmlspecialchars((string)$x,ENT_QUOTES);}
 function wp_date($format,$timestamp){return date($format,$timestamp);}
 require __DIR__.'/../includes/ThemeRelease.php';require __DIR__.'/../includes/Online/Publication.php';
 use Emonks\Factory\ThemeRelease;use Emonks\Factory\Online\Publication;
 $count=0;$assert=static function($ok,$message)use(&$count){if(!$ok)throw new \RuntimeException($message);$count++;};
 $remote=ThemeRelease::environment();$remote['theme']['slug']='skeletor-master';
 $status=['environment'=>$remote,'plugin_version'=>ThemeRelease::pluginVersion()];
 $checks=static fn($s)=>array_column(Publication::environmentChecks($s),null,'label');
 $rows=$checks($status);$assert(!array_filter($rows,static fn($r)=>!$r['ok']),'Different configured folder names are allowed');
 foreach(['skeletor-master','new-brand','another_theme'] as $slug){$assert(ThemeRelease::validateSlug($slug)===$slug,'Valid custom slug');}
 foreach(['../skeletor','/absolute','two words','a.php/other',''] as $slug){$failed=false;try{ThemeRelease::validateSlug($slug);}catch(\RuntimeException $e){$failed=true;}$assert($failed,'Unsafe folder rejected');}
 ThemeRelease::useRequestSlug('new-brand');$assert(ThemeRelease::slug()==='new-brand','Request-selected receiver theme');ThemeRelease::useRequestSlug('skeletor');
 $bad=$status;$bad['environment']['theme']['installed']=false;$rows=$checks($bad);
 $assert(!$rows['Thema']['ok']&&str_contains($rows['Thema']['action'],'skeletor-master'),'Missing theme has upload instruction');
 $assert($rows['ACF Pro']['ok'],'Missing theme does not hide successful ACF check');
 $bad=$status;$bad['environment']['acf']['active']=false;$bad['environment']['acf']['pro']=false;$rows=$checks($bad);
 $assert(!$rows['ACF Pro']['ok']&&str_contains($rows['ACF Pro']['action'],'netwerkbreed'),'Separate ACF action');$assert($rows['Thema']['ok'],'ACF failure does not hide theme result');
 foreach(['Themaversie'=>'theme','Site Factory-pluginversie'=>'plugin'] as $label=>$component){
  $bad=$status;$bad['environment'][$component]['version']='0.1.0';$rows=$checks($bad);$assert(!$rows[$label]['ok']&&str_contains($rows[$label]['action'],'online'),'Older remote version');
  $bad['environment'][$component]['version']='99.0.0';$rows=$checks($bad);$assert(!$rows[$label]['ok']&&str_contains($rows[$label]['action'],'lokale'),'Newer remote version');
 }
 $rows=$checks(['theme'=>['version'=>'1.1.8']]);$assert(!$rows['Site Factory-pluginversie']['ok']&&str_contains($rows['Site Factory-pluginversie']['action'],'bij'),'Legacy receiver must be upgraded');
 $bad=$status;$bad['link']=['site'=>123,'theme_slug'=>'old-theme'];$rows=$checks($bad);$assert(!$rows['Actief thema op deze site']['ok'],'Linked site must use configured theme');
 $report=['ok'=>false,'checks'=>$rows,'issues'=>['<script>bad</script>']];$html=Publication::summary($report);
 $assert(str_contains($html,'Site Factory-pluginversie')&&str_contains($html,'Themaversie'),'Both versions shown independently');
 $assert(str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>'),'Escaped check output');
 echo "$count environment checks passed.\n";
}
