<?php
use Emonks\Factory\FullSite;
function is_serialized($value){return is_string($value)&&preg_match('/^(a|O|s|b|i|d|N)[:;]/',$value);}
$wpdb=new class {function prepare($format,$value){return "'".str_replace("'","''",$value)."'";}};
require dirname(__DIR__).'/includes/FullSite.php';
$n=0;function yes($ok){global $n;if(!$ok){throw new RuntimeException('Check '.($n+1).' failed');}$n++;}function rejects($fn){try{$fn();}catch(Throwable $e){yes(true);return;}throw new RuntimeException('Expected rejection');}
foreach(['posts','wc_orders','wp_25_options'] as $name){yes(FullSite::identifier($name)==='`'.$name.'`');}
foreach(['../orders','orders`; DROP TABLE wp_users','',str_repeat('a',65)] as $name){rejects(fn()=>FullSite::identifier($name));}
foreach(['2026/album.jpg','font.woff2','download.zip'] as $name){yes(FullSite::relative($name)===$name);}
foreach(['../config','a/../../c','/absolute','a\\b','a//b','a/./b','a:b',"a\0b"] as $name){rejects(fn()=>FullSite::relative($name));}
foreach(['woocommerce_currency','_fluentform_mailchimp_details','options_footer','stropstrikkers_provisional_stock_20261102'] as $key){yes(FullSite::optionAllowed($key));}
foreach(['emsf_credentials','_transient_nonce','_site_transient_secret','cron','active_plugins','upload_path'] as $key){yes(!FullSite::optionAllowed($key));}
$map=['https://source.test'=>'https://target.test','/private/source'=>'/private/target'];
$value=['nested'=>['url'=>'https://source.test/a','download'=>'/private/source/x.zip'],'number'=>3,'false'=>false];$encoded=FullSite::rewrite(serialize($value),$map);$restored=unserialize($encoded);yes($restored['nested']['url']==='https://target.test/a');yes($restored['nested']['download']==='/private/target/x.zip');yes($restored['number']===3&&$restored['false']===false);yes(FullSite::rewrite(null,$map)===null);
class UnsupportedPayload {public string $x='y';}
rejects(fn()=>FullSite::rewrite(serialize(new UnsupportedPayload()),$map));
$object=unserialize(FullSite::rewrite(serialize((object)['url'=>'https://source.test/a']),$map));yes($object instanceof stdClass&&$object->url==='https://target.test/a');
$schema=['columns'=>[['Field'=>'id','Type'=>'bigint(20) unsigned','Null'=>'NO','Extra'=>'auto_increment','Default'=>null],['Field'=>'status','Type'=>"enum('enabled','disabled')",'Null'=>'NO','Extra'=>'','Default'=>'enabled']],'indexes'=>[['Column_name'=>'id','Index_type'=>'BTREE','Key_name'=>'PRIMARY','Seq_in_index'=>1,'Sub_part'=>null,'Non_unique'=>0]],'collation'=>'utf8mb4_unicode_ci'];
$sql=FullSite::createSQL('wp_orders',$schema);yes(str_contains($sql,'PRIMARY KEY (`id`)'));yes(str_contains($sql,'ENGINE=InnoDB'));
$bad=$schema;$bad['columns'][1]['Type']='text); DROP TABLE wp_users';rejects(fn()=>FullSite::createSQL('wp_orders',$bad));
$bad=$schema;$bad['collation']='utf8mb4_unicode_ci;DROP';rejects(fn()=>FullSite::createSQL('wp_orders',$bad));
$bad=$schema;$bad['columns'][1]['Extra']='GENERATED AS (SELECT 1)';rejects(fn()=>FullSite::createSQL('wp_orders',$bad));
$bad=$schema;$bad['indexes'][0]['Column_name']='unknown';rejects(fn()=>FullSite::createSQL('wp_orders',$bad));
echo "$n full-site policy checks passed.\n";

require dirname(__DIR__).'/includes/StandaloneContent.php';
$before="CREATE TABLE `wp_comments` (\n `id` bigint NOT NULL,\n PRIMARY KEY (`id`)\n) ENGINE=InnoDB";
$after="CREATE TABLE `wp_comments` (\n `id` bigint NOT NULL,\n PRIMARY KEY (`id`),\n KEY `woo_lookup` (`id`)\n) ENGINE=InnoDB";
yes(\Emonks\Factory\StandaloneContent::compatibleSchema($before,$after));
yes(!\Emonks\Factory\StandaloneContent::compatibleSchema($before,str_replace('bigint','varchar(20)',$after)));
yes(!\Emonks\Factory\StandaloneContent::compatibleSchema($before,str_replace('KEY `woo_lookup`','UNIQUE KEY `woo_lookup`',$after)));
echo "3 restoration schema checks passed.\n";

$existing=['empty'=>false,'samples_only'=>false];
rejects(fn()=>\Emonks\Factory\StandaloneContent::validateChoice($existing,'empty'));
rejects(fn()=>\Emonks\Factory\StandaloneContent::validateChoice($existing,'samples'));
\Emonks\Factory\StandaloneContent::validateChoice($existing,'replace');yes(true);
echo "3 explicit replacement choice checks passed.\n";
