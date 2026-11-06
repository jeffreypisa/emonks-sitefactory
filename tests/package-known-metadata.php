<?php
// Isolated metadata contract tests; no database, network, or customer writes.
require __DIR__.'/../includes/Package.php';
require __DIR__.'/../includes/Online/Publication.php';
function maybe_unserialize($value){return $value;}
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
$method=new ReflectionMethod(Emonks\Factory\Package::class,'layoutMeta');
$n=0;
function verify($ok,$label){global $n;if(!$ok){throw new RuntimeException($label);}++$n;}
$good=[
 ['page','hero_image_opacity_mode',['inherit','custom','']],
 ['page','hero_image_opacity',['','0','100','50']],
 ['attachment','_emonks_capture_date',['2026-10-15','2024-02-29']],
 ['attachment','_emonks_generated_concept',['1','0']],
 ['attachment','_emonks_capture_source',['live','archive']],
];
foreach($good as [$type,$key,$values]){
 verify($method->invoke(null,$type,$key,$values),"Supported $key");
 foreach($values as $value){$encoded=Emonks\Factory\Package::encode($value,null,$key);verify(Emonks\Factory\Package::decode($encoded,[],[])===$value,"Preserve $key");}
}
$bad=[
 ['page','hero_image_opacity_mode',['unexpected']],['page','hero_image_opacity_mode',[['id'=>123]]],
 ['page','hero_image_opacity',['101']],['page','hero_image_opacity',['-1']],['page','hero_image_opacity',[['id'=>123]]],
 ['attachment','hero_image_opacity',['100']],
 ['attachment','_emonks_capture_date',['2026-02-29']],['attachment','_emonks_capture_date',['2026-13-01']],['attachment','_emonks_capture_date',[['date'=>'2026-10-15']]],
 ['attachment','_emonks_generated_concept',['2']],['attachment','_emonks_generated_concept',[true]],
 ['attachment','_emonks_capture_source',['unknown']],['attachment','_emonks_capture_source',[['id'=>123]]],
 ['page','_emonks_capture_source',['live']],['attachment','_emonks_arbitrary',['live']],
];
foreach($bad as [$type,$key,$values]){verify(!$method->invoke(null,$type,$key,$values),"Reject invalid $type/$key");}
$html=Emonks\Factory\Online\Publication::summary(['ok'=>false,'issues'=>['Metadata geblokkeerd'],'operation'=>['status'=>'complete']]);
verify(str_contains($html,'De vorige inhoudspublicatie is voltooid.'),'Previous publication is identified');
verify(str_contains($html,'Deze controle publiceert geen inhoud.'),'Check does not claim publication');
verify(!str_contains($html,'Publicatie voltooid. Open Preview'),'No false success instruction');
verify(str_contains($html,'Metadata geblokkeerd'),'Blocking issue stays visible');
$tracking=new ReflectionMethod(Emonks\Factory\Package::class,'importTrackingMeta');
foreach(['_sdda_logo_hash','_sdda_form_hash'] as $key){
 verify($tracking->invoke(null,'attachment',$key),'Exclude local attachment marker '.$key);
 verify(!$tracking->invoke(null,'page',$key),'Do not suppress page metadata '.$key);
 verify(!$method->invoke(null,'attachment',$key,[str_repeat('a',64)]),'Marker is excluded, not copied as allowed content '.$key);
}
foreach(['_sdda_other_hash','_sdda_logo_hash_extra','_thumbnail_id','_wp_attachment_image_alt'] as $key){verify(!$tracking->invoke(null,'attachment',$key),'Do not suppress unrelated metadata '.$key);}
echo "$n known metadata and status checks passed.\n";
