<?php
/** Render-only states in the disposable integration fixture; restore the original journal. */
use Emonks\Factory\Standalone;
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration-handover-')||is_multisite()){throw new RuntimeException('Disposable standalone fixture required.');}
wp_set_current_user(1);$original=Standalone::state();if(empty($original['committed'])){throw new RuntimeException('Run after a synthetic import.');}
$n=0;$assert=static function($v,$m)use(&$n){if(!$v){throw new RuntimeException('FAIL: '.$m);}$n++;};
$save=new ReflectionMethod(Standalone::class,'save');$dir=Standalone::storage().'/ux-previews';wp_mkdir_p($dir);
$render=static function($name,$record)use($save,$dir){$save->invoke(null,$record);ob_start();Standalone::page();$html=ob_get_clean();file_put_contents($dir.'/'.$name.'.html',$html);return $html;};
try{
 $html=$render('upload',[]);$assert(str_contains($html,'Pakket uploaden'),'Upload primary action');$assert(str_contains($html,'hosting_private'),'Hosting acknowledgment retained');
 $r=$original;$r['committed']=false;unset($r['error']);$r['state']='software_pending';$r['backup']=['id'=>'synthetic-render-only'];
 $html=$render('software',$r);$assert(strpos($html,'Thema en plugins installeren')<strpos($html,'Herstelkopie downloaden'),'Current action precedes recovery');$assert(!str_contains($html,'name="checks['),'No quality checklist');
 $r['state']='software_ready';$html=$render('content',$r);$assert(str_contains($html,'Website importeren'),'Content import primary');$assert(str_contains($html,'name="replace_confirmed"'),'Existing content requires permission');$assert(!str_contains($html,'<select'),'No unnecessary replacement-mode selector');$assert(str_contains($html,'name="inventory"'),'Inventory fingerprint remains bound');$assert(!str_contains($html,'<details open'),'Secondary information closed');
 $r=$original;$r['state']='imported';unset($r['error']);$html=$render('imported',$r);$assert(str_contains($html,'Website activeren'),'Separate explicit activation');$assert(!str_contains($html,'name="checks['),'Activation needs no quality attestations');$assert(str_contains($html,'mail, integraties en indexering'),'Activation effects stated');$assert(strpos($html,'Website activeren')<strpos($html,'Log en pakketgegevens'),'Primary action precedes details');
 $r['report']=Standalone::report();$r['report']['state']='released';$r['state']='released';$html=$render('released',$r);$assert(str_contains($html,'Tijdelijke bestanden opruimen'),'Completion cleanup action');$assert(!str_contains($html,'value="release"'),'No activation action once completed');
}finally{$save->invoke(null,$original);}
echo "$n importer UX checks passed. Previews: $dir\n";
