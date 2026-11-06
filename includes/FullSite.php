<?php
declare(strict_types=1);
namespace Emonks\Factory;

/** Standalone, site-scoped snapshot. IDs are retained; network publishing uses Package. */
final class FullSite {
    public const FORMAT='emonks-full-site-v1';
    public const CHUNK=16*1024*1024;
    public const LIMIT=16*1024*1024*1024;
    private const EPHEMERAL='/^(actionscheduler_|ff_scheduled_actions$|woocommerce_sessions$|wc_reserved_stock$|wc_rate_limits$)/D';
    public static function identifier(string $name): string {
        if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)){throw new \RuntimeException('Ongeldige databasenaam.');}return '`'.$name.'`';
    }
    public static function relative(string $name): string {
        if($name===''||strlen($name)>1024||preg_match('~[\x00-\x1f\\\\:]~',$name)||str_starts_with($name,'/')||array_intersect(explode('/',$name),['','..','.'])){throw new \RuntimeException('Onveilig bestandspad in volledige export.');}return $name;
    }
    public static function optionAllowed(string $key): bool {
        return !preg_match('/^(?:_transient_|_site_transient_|emsf_|cron$|active_plugins$|recently_activated$|auto_updater.lock$|rewrite_rules$|upload_path$|upload_url_path$)/D',$key);
    }
    private static function rows(string $table): \Generator {
        global $wpdb;$table=self::identifier($table);$indexes=$wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name='PRIMARY'",ARRAY_A);
        usort($indexes,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        $order=$indexes?' ORDER BY '.implode(',',array_map(static fn($r)=>self::identifier($r['Column_name']),$indexes)):'';
        for($offset=0;;$offset+=200){$rows=$wpdb->get_results("SELECT * FROM $table$order LIMIT 200 OFFSET $offset",ARRAY_A);if($wpdb->last_error){throw new \RuntimeException('Sitetabel lezen mislukt.');}foreach($rows as $row){yield $row;}if(count($rows)<200){break;}}
    }
    /** Arbitrary SQL is never executed from a package. Rebuild a validated column/index description. */
    public static function createSQL(string $table,array $schema): string {
        global $wpdb;$parts=[];$names=[];
        foreach($schema['columns']??[] as $c){
            $name=self::identifier($c['Field']);$type=strtolower($c['Type']);
            if(!preg_match('/^(?:(?:tinyint|smallint|mediumint|int|bigint|float|double|decimal|numeric|bit)(?:\(\d{1,3}(?:,\d{1,3})?\))?(?: unsigned)?(?: zerofill)?|(?:var)?(?:char|binary)\(\d{1,5}\)|(?:tiny|medium|long)?(?:text|blob)|date|datetime(?:\([0-6]\))?|timestamp(?:\([0-6]\))?|time|year(?:\(4\))?|json|enum\(\x27[A-Za-z0-9 _.-]*\x27(?:,\x27[A-Za-z0-9 _.-]*\x27)*\))$/D',$type)){throw new \RuntimeException('Niet ondersteund kolomtype: '.$type);}
            if(!in_array($c['Null'],['YES','NO'],true)||!in_array(strtolower($c['Extra']),['','auto_increment','on update current_timestamp()','on update current_timestamp'],true)){throw new \RuntimeException('Niet ondersteunde kolomoptie.');}
            $sql=$name.' '.$type.($c['Null']==='YES'?' NULL':' NOT NULL');
            if($c['Default']!==null){$default=(string)$c['Default'];$sql.=' DEFAULT '.(preg_match('/^current_timestamp(?:\(\))?$/iD',$default)?'CURRENT_TIMESTAMP':$wpdb->prepare('%s',$default));}
            elseif($c['Null']==='YES'){$sql.=' DEFAULT NULL';}
            if($c['Extra']){$sql.=' '.$c['Extra'];}$parts[]=$sql;$names[$c['Field']]=true;
        }
        if(!$parts||count($parts)>512){throw new \RuntimeException('Ongeldig tabelschema.');}
        $indexes=[];foreach($schema['indexes']??[] as $index){if(!isset($names[$index['Column_name']])||!in_array($index['Index_type'],['BTREE','FULLTEXT','HASH'],true)){throw new \RuntimeException('Niet ondersteunde tabelindex.');}$indexes[$index['Key_name']][]=$index;}
        foreach($indexes as $name=>$items){usort($items,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);$columns=[];foreach($items as $item){$column=self::identifier($item['Column_name']);if($item['Sub_part']!==null){$n=(int)$item['Sub_part'];if($n<1||$n>65535){throw new \RuntimeException('Ongeldige indexlengte.');}$column.='('.$n.')';}$columns[]=$column;}$kind=$name==='PRIMARY'?'PRIMARY KEY':($items[0]['Index_type']==='FULLTEXT'?'FULLTEXT KEY':((int)$items[0]['Non_unique']===0?'UNIQUE KEY':'KEY')).' '.self::identifier($name);$parts[]=$kind.' ('.implode(',',$columns).')';}
        $collation=$schema['collation']??'';if(!preg_match('/^(utf8mb4|utf8|latin1)_[a-z0-9_]+$/D',$collation,$match)){throw new \RuntimeException('Niet ondersteunde tabeltekenset.');}
        return 'CREATE TABLE IF NOT EXISTS '.self::identifier($table).' ('.implode(",\n",$parts).') ENGINE=InnoDB DEFAULT CHARSET='.$match[1].' COLLATE='.$collation;
    }
    private static function blob(string $path,string $dir,array &$m): string {
        $hash=hash_file('sha256',$path);if(isset($m['blobs'][$hash])){return $hash;}
        $in=fopen($path,'rb');if(!$in){throw new \RuntimeException('Bronbestand lezen mislukt.');}$chunks=[];$size=0;
        try{do{$data=fread($in,self::CHUNK);if($data===false){throw new \RuntimeException('Bronbestand lezen mislukt.');}if($data===''){break;}$id=hash('sha256',$data);$name='snapshot/'.$id.'.bin';$target=$dir.'/files/'.$name;if(!is_file($target)){if(file_put_contents($target,$data)!==strlen($data)||!chmod($target,0600)){throw new \RuntimeException('Pakketbestand schrijven mislukt.');}}$m['files'][$name]=['size'=>strlen($data),'sha256'=>$id];$chunks[]=$name;$size+=strlen($data);}while(!feof($in));}finally{fclose($in);}
        if($size!==filesize($path)){throw new \RuntimeException('Bronbestand is tijdens export gewijzigd.');}
        $m['blobs'][$hash]=['size'=>$size,'chunks'=>$chunks];return $hash;
    }
    public static function export(string $dir): array {
        global $wpdb;
        if(!is_multisite()||is_main_site()){throw new \RuntimeException('Volledige export vereist een afzonderlijke Factory-site.');}
        if(!function_exists('get_mu_plugins')){require_once ABSPATH.'wp-admin/includes/plugin.php';}if(get_mu_plugins()||get_dropins()){throw new \RuntimeException('Deze bron gebruikt mu-plugins of WordPress-drop-ins. Controleer de hostingafhankelijkheden voordat een zelfstandige export wordt gemaakt.');}
        if(!wp_mkdir_p($dir.'/files/snapshot')||!chmod($dir,0700)){throw new \RuntimeException('Private pakketmap maken mislukt.');}
        $upload=wp_upload_dir();$m=['format'=>self::FORMAT,'version'=>1,'created_at'=>gmdate('c'),'source'=>home_url(),'theme'=>'skeletor','source_prefix'=>$wpdb->prefix,'source_uploads'=>['dir'=>$upload['basedir'],'url'=>$upload['baseurl']],'dependencies'=>['theme_version'=>ThemeRelease::version(),'factory_version'=>ThemeRelease::pluginVersion(),'theme_digest'=>Package::theme_digest()],'models'=>[],'posts'=>[],'terms'=>[],'menus'=>[],'forms'=>[],'options'=>[],'files'=>[],'blobs'=>[],'uploads'=>[],'languages'=>[],'downloads'=>[],'tables'=>[],'users'=>[],'unsupported'=>[],'excluded'=>['tables'=>[],'options'=>[],'files'=>[]]];
        $models=array_merge(acf_get_raw_post_types(),acf_get_raw_taxonomies());foreach(glob(Factory::model_dir().'/*.json')?:[] as $file){$models[]=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);}$m['models']=Package::project_models($models);Package::check_models($m);
        // Do not copy network administrators, passwords, sessions or other sites' memberships.
        foreach(get_users(['blog_id'=>get_current_blog_id()]) as $user){if(is_super_admin($user->ID)||in_array('administrator',$user->roles,true)){continue;}$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->users} WHERE ID=%d",$user->ID),ARRAY_A);$meta=[];foreach($wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->usermeta} WHERE user_id=%d",$user->ID),ARRAY_A) as $item){$key=$item['meta_key'];if(in_array($key,['session_tokens','application_passwords','_application_passwords'],true)||preg_match('/^'.preg_quote($wpdb->base_prefix,'/').'\d+_/',$key)&&!str_starts_with($key,$wpdb->prefix)){continue;}if(str_starts_with($key,$wpdb->prefix)){$key='{{prefix}}'.substr($key,strlen($wpdb->prefix));}if(in_array($key,['wp_capabilities','wp_user_level'],true)&&$wpdb->prefix!==$wpdb->base_prefix){continue;}$meta[$key][]=$item['meta_value'];}$m['users'][]=['row'=>$row,'meta'=>$meta];}
        $statuses=$wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS WHERE Name LIKE %s',$wpdb->esc_like($wpdb->prefix).'%'),ARRAY_A);usort($statuses,static fn($a,$b)=>strcmp($a['Name'],$b['Name']));
        if($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT')===false){throw new \RuntimeException('Momentopname starten mislukt.');}
        try{foreach($statuses as $status){
            $table=$status['Name'];$suffix=substr($table,strlen($wpdb->prefix));self::identifier($suffix);if(strcasecmp($status['Engine']??'','InnoDB')){throw new \RuntimeException('Bron gebruikt een niet-transactionele tabel: '.$suffix);}
            $ddl=$wpdb->get_row('SHOW CREATE TABLE '.self::identifier($table),ARRAY_N);if(!$ddl||preg_match('/FOREIGN KEY|\bCONSTRAINT\b/i',$ddl[1])){throw new \RuntimeException('Tabel met expliciete foreign keys vereist een migratieadapter: '.$suffix);}
            $schema=['columns'=>$wpdb->get_results('SHOW FULL COLUMNS FROM '.self::identifier($table),ARRAY_A),'indexes'=>$wpdb->get_results('SHOW INDEX FROM '.self::identifier($table),ARRAY_A),'collation'=>$status['Collation']];self::createSQL($table,$schema);
            $file=tempnam($dir,'rows-');$stream=fopen($file,'wb');$count=0;$empty=(bool)preg_match(self::EPHEMERAL,$suffix);if($empty){$m['excluded']['tables'][]=$suffix;}
            try{if(!$empty){foreach(self::rows($table) as $row){if($suffix==='options'&&!self::optionAllowed($row['option_name'])){continue;}$line=json_encode(array_map(static fn($v)=>$v===null?null:base64_encode((string)$v),$row),JSON_THROW_ON_ERROR)."\n";if(fwrite($stream,$line)!==strlen($line)){throw new \RuntimeException('Tabeldata schrijven mislukt.');}$count++;if($suffix==='posts'){$m['posts'][]=['id'=>(int)$row['ID']];}elseif($suffix==='fluentform_forms'){$m['forms'][]=['id'=>(int)$row['id']];}}}}finally{fclose($stream);}
            $m['tables'][$suffix]=['schema'=>$schema,'rows'=>$count,'blob'=>self::blob($file,$dir,$m)];unlink($file);
        }}finally{$wpdb->query('ROLLBACK');}
        if(is_dir($upload['basedir'])){ $paths=[];foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($upload['basedir'],\FilesystemIterator::SKIP_DOTS)) as $f){if($f->isLink()){throw new \RuntimeException('Uploadsymlink vereist handmatige controle.');}if($f->isFile()){$paths[]=substr($f->getPathname(),strlen($upload['basedir'])+1);}}sort($paths);foreach($paths as $relative){self::relative($relative);if(preg_match('~(?:^|/)(?:wc-logs|cache|\.DS_Store)(?:/|$)|\.(?:log|lock)$~i',$relative)){$m['excluded']['files'][]=$relative;continue;}if(preg_match('/\.(?:php\d*|phtml|phar|cgi|pl|sh)$/i',$relative)){throw new \RuntimeException('Uitvoerbaar bestand in uploads vereist handmatige controle: '.$relative);}$m['uploads'][$relative]=self::blob($upload['basedir'].'/'.$relative,$dir,$m);}}
        if(defined('WP_LANG_DIR')&&is_dir(WP_LANG_DIR)){$locale=get_locale();foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(WP_LANG_DIR,\FilesystemIterator::SKIP_DOTS)) as $f){if(!$f->isFile()||$f->isLink()){continue;}$relative=substr($f->getPathname(),strlen(WP_LANG_DIR)+1);if(preg_match('~^(?:(?:plugins|themes)/[^/]+-)?'.preg_quote($locale,'~').'(?:-[a-f0-9]{32})?\.(?:mo|po|json|l10n\.php)$~D',$relative)){$m['languages'][$relative]=self::blob($f->getPathname(),$dir,$m);}}ksort($m['languages']);}
        foreach($wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_downloadable_files'") as $raw){$items=unserialize($raw,['allowed_classes'=>false]);if(!is_array($items)){continue;}foreach($items as $item){$source=$item['file']??'';if(!$source||isset($m['downloads'][$source])){continue;}$path=str_starts_with($source,'file://')?substr($source,7):$source;if(str_starts_with($source,$upload['baseurl'].'/')){$path=$upload['basedir'].'/'.rawurldecode(substr($source,strlen($upload['baseurl'])+1));}if(preg_match('~^https?://~i',$path)){continue;}$path=realpath($path);if(!$path||!is_file($path)||!preg_match('/\.(zip|mp3|flac|wav|m4a|ogg|aac|pdf|epub)$/i',$path)){throw new \RuntimeException('Lokaal downloadbestand ontbreekt of heeft een niet ondersteund type.');}if(str_starts_with($path,realpath($upload['basedir']).'/')){continue;}$m['downloads'][$source]=['blob'=>self::blob($path,$dir,$m),'name'=>basename($path)];}}
        ksort($m['files']);ksort($m['blobs']);ksort($m['downloads']);if(count($m['files'])>20000||array_sum(array_column($m['files'],'size'))>self::LIMIT){throw new \RuntimeException('Volledige export overschrijdt 16 GiB of 20.000 delen.');}
        Package::json($dir.'/manifest.json',$m);chmod($dir.'/manifest.json',0600);return $m;
    }
    public static function read(string $dir): array {
        $m=json_decode(file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        if(($m['format']??'')!==self::FORMAT||($m['version']??0)!==1||!is_array($m['tables']??null)||!isset($m['tables']['options'],$m['tables']['posts'])){throw new \RuntimeException('Ongeldige volledige site-export.');}
        self::identifier($m['source_prefix']);$bytes=0;
        foreach($m['files'] as $name=>$file){if(!preg_match('~^snapshot/([a-f0-9]{64})\.bin$~D',$name,$match)||$match[1]!==($file['sha256']??'')||!is_int($file['size'])||$file['size']<1||$file['size']>self::CHUNK||is_link($dir.'/files/'.$name)||!is_file($dir.'/files/'.$name)||filesize($dir.'/files/'.$name)!==$file['size']||!hash_equals($file['sha256'],hash_file('sha256',$dir.'/files/'.$name))){throw new \RuntimeException('Volledig pakketbestand ontbreekt of wijkt af.');}$bytes+=$file['size'];}
        if($bytes>self::LIMIT||count($m['files'])>20000){throw new \RuntimeException('Volledig pakket is te groot.');}
        foreach($m['blobs'] as $hash=>$blob){if(!preg_match('/^[a-f0-9]{64}$/D',$hash)||!is_int($blob['size'])||$blob['size']<0){throw new \RuntimeException('Ongeldig bestandsregister.');}$h=hash_init('sha256');$n=0;foreach($blob['chunks'] as $chunk){if(!isset($m['files'][$chunk])){throw new \RuntimeException('Bestandsdeel ontbreekt.');}$in=fopen($dir.'/files/'.$chunk,'rb');$n+=hash_update_stream($h,$in);fclose($in);}if($n!==$blob['size']||!hash_equals($hash,hash_final($h))){throw new \RuntimeException('Samengesteld bestand wijkt af.');}}
        foreach($m['tables'] as $suffix=>$table){self::identifier($suffix);if(in_array($suffix,['users','usermeta','blogs','sitemeta','site','blogmeta'],true)){throw new \RuntimeException('Netwerktabel in site-export.');}self::createSQL('validate_'.$suffix,$table['schema']);if(!isset($m['blobs'][$table['blob']])||!is_int($table['rows'])||$table['rows']<0){throw new \RuntimeException('Ongeldige sitetabel.');}}
        foreach($m['uploads'] as $relative=>$hash){self::relative($relative);if(preg_match('/\.(?:php\d*|phtml|phar|cgi|pl|sh)$/i',$relative)||!isset($m['blobs'][$hash])){throw new \RuntimeException('Onveilig uploadbestand.');}}
        foreach($m['languages']??[] as $name=>$hash){if(!preg_match('~^(?:(?:plugins|themes)/[^/]+-)?[a-z]{2,3}(?:_[A-Za-z0-9]+)*(?:-[a-f0-9]{32})?\.(?:mo|po|json|l10n\.php)$~D',$name)||!isset($m['blobs'][$hash])){throw new \RuntimeException('Ongeldig taalbestand.');}self::relative($name);}
        foreach($m['downloads'] as $download){self::relative($download['name']);if(str_contains($download['name'],'/')||!preg_match('/\.(zip|mp3|flac|wav|m4a|ogg|aac|pdf|epub)$/i',$download['name'])||!isset($m['blobs'][$download['blob']])){throw new \RuntimeException('Ongeldig downloadbestand.');}}
        Package::check_models(array_replace($m,['posts'=>[],'terms'=>[]]));return $m;
    }
    private static function materialize(string $dir,array $m,string $hash,string $target,int $mode): void {
        $ancestor=dirname($target);while($ancestor!==dirname($ancestor)){if(is_link($ancestor)){throw new \RuntimeException('Symbolische link in doelpad.');}$ancestor=dirname($ancestor);}
        if(is_link($target)){throw new \RuntimeException('Onveilig doelbestand.');}if(is_file($target)&&hash_equals($hash,hash_file('sha256',$target))){return;}
        if(!wp_mkdir_p(dirname($target))){throw new \RuntimeException('Doelmap maken mislukt.');}$tmp=tempnam(dirname($target),'.emsf-');$out=fopen($tmp,'wb');
        try{foreach($m['blobs'][$hash]['chunks'] as $chunk){$in=fopen($dir.'/files/'.$chunk,'rb');try{if(stream_copy_to_stream($in,$out)!==$m['files'][$chunk]['size']){throw new \RuntimeException('Bestand overzetten mislukt.');}}finally{fclose($in);}}}finally{fclose($out);}
        if(!hash_equals($hash,hash_file('sha256',$tmp))||!chmod($tmp,$mode)||!rename($tmp,$target)){@unlink($tmp);throw new \RuntimeException('Bestand controleren/plaatsen mislukt.');}
    }
    public static function downloadRoot(): string {
        // Persistent sibling of WordPress, not the temporary package store or public uploads.
        $parent=realpath(dirname(rtrim(ABSPATH,'/')));$root=defined('EMSF_PRIVATE_DOWNLOAD_DIR')?rtrim(EMSF_PRIVATE_DOWNLOAD_DIR,'/'):$parent.'/.emonks-downloads-'.substr(hash('sha256',ABSPATH),0,16);
        if(!$parent||!str_starts_with($root,'/')||is_link($root)||(!is_dir($root)&&!mkdir($root,0700))||!is_writable($root)){throw new \RuntimeException('Maak een blijvende private downloadmap naast WordPress schrijfbaar, of stel EMSF_PRIVATE_DOWNLOAD_DIR in.');}
        $real=realpath($root);foreach([realpath(ABSPATH),realpath(WP_CONTENT_DIR)] as $web){if($web&&($real===$web||str_starts_with($real,$web.'/'))){throw new \RuntimeException('De downloadmap moet buiten de WordPress-webroot staan.');}}
        chmod($root,0700);$protection="Require all denied\nDeny from all\n";$guard=$root.'/.htaccess';if(is_link($guard)||is_file($guard)&&file_get_contents($guard)!==$protection){throw new \RuntimeException('De private downloadmap heeft afwijkende toegangsregels. Gebruik een eigen, lege downloadmap.');}if(!is_file($guard)&&(file_put_contents($guard,$protection)!==strlen($protection)||!chmod($guard,0600))){throw new \RuntimeException('Private downloadbeveiliging schrijven mislukt.');}return $real;
    }
    public static function rewrite($value,array $replacements) {
        if(!is_string($value)){return $value;}
        if(is_serialized($value)){$decoded=unserialize($value,['allowed_classes'=>['stdClass']]);$walk=static function($v,$depth=0)use(&$walk,$replacements){if($depth>128){throw new \RuntimeException('Te diep geneste plugininstelling.');}if(is_object($v)){if(get_class($v)!=='stdClass'){throw new \RuntimeException('Geserialiseerde pluginobjecten vereisen een specifieke migratieadapter.');}$out=new \stdClass();foreach(get_object_vars($v) as $key=>$item){$out->{strtr($key,$replacements)}=$walk($item,$depth+1);}return $out;}if(is_array($v)){$out=[];foreach($v as $k=>$x){$out[is_string($k)?strtr($k,$replacements):$k]=$walk($x,$depth+1);}return $out;}return is_string($v)?strtr($v,$replacements):$v;};return serialize($walk($decoded));}
        return strtr($value,$replacements);
    }
    public static function hostingPlugins(array $active): array {
        return array_values(array_intersect($active,['sg-security/sg-security.php','sg-cachepress/sg-cachepress.php']));
    }
    public static function preflight(array $m): array {
        global $wpdb;Standalone::authorize();$upload=wp_upload_dir();if(!empty($upload['error'])||!is_dir($upload['basedir'])&&!wp_mkdir_p($upload['basedir'])||!is_writable($upload['basedir'])){throw new \RuntimeException('Uploadmap is niet schrijfbaar.');}
        foreach($m['users'] as $user){$row=$user['row'];if($wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d OR user_login=%s OR user_email=%s",$row['ID'],$row['user_login'],$row['user_email']))){throw new \RuntimeException('Een klantaccount botst met een bestaand account. Gebruik een lege installatie met een andere beheerder-ID en een ander beheerdersadres.');}}
         $bytes=array_sum(array_column($m['blobs'],'size'));$free=disk_free_space($upload['basedir']);if($free===false||$free<$bytes+64*1024*1024){throw new \RuntimeException('Onvoldoende ruimte voor de volledige site en downloads.');}if($m['downloads']){self::downloadRoot();}return ['ok'=>true,'posts'=>count($m['posts']),'files'=>count($m['uploads'])+count($m['downloads']),'forms'=>count($m['forms']),'models'=>count($m['models']),'bytes'=>array_sum(array_column($m['blobs'],'size')),'theme_version'=>ThemeRelease::version()];
    }
    /** Multisite uploads may use the network host even when content uses the site's host. */
    public static function importReplacements(array $m,array $upload,string $target): array {
        $source=$m['source_uploads'];
        $map=[$source['dir']=>$upload['basedir'],$source['url']=>$upload['baseurl'],$m['source']=>$target];
        $site=parse_url($m['source']);$path=parse_url($source['url'],PHP_URL_PATH);
        if(isset($site['scheme'],$site['host'])&&is_string($path)&&$path!==''){
            $origin=$site['scheme'].'://'.$site['host'].(isset($site['port'])?':'.$site['port']:'');
            $map[$origin.$path]=$upload['baseurl'];
        }
        return $map;
    }
    public static function import(string $dir,array $m): void {
        global $wpdb;if(!Standalone::importing()){throw new \RuntimeException('Volledige import vereist een bevestigde oplevering.');}self::preflight($m);
        $upload=wp_upload_dir();$target=Standalone::destination();$map=self::importReplacements($m,$upload,$target);
        foreach($m['downloads'] as $source=>$download){$path=self::downloadRoot().'/'.$download['blob'].'-'.sanitize_file_name($download['name']);self::materialize($dir,$m,$download['blob'],$path,0600);$map[$source]=$path;}
        foreach($map as $a=>$b){$map[str_replace('/','\\/',$a)]=str_replace('/','\\/',$b);}uksort($map,static fn($a,$b)=>strlen($b)<=>strlen($a));
        $uploadHashes=[];foreach($m['uploads'] as $relative=>$hash){$path=$upload['basedir'].'/'.$relative;self::materialize($dir,$m,$hash,$path,0644);if(preg_match('/\.(css|json|svg|html|txt)$/iD',$relative)&&filesize($path)<self::CHUNK){$before=file_get_contents($path);$after=self::rewrite($before,$map);if($after!==$before&&file_put_contents($path,$after)!==strlen($after)){throw new \RuntimeException('Mediaverwijzing aanpassen mislukt.');}}$uploadHashes[$relative]=hash_file('sha256',$path);}
        foreach($m['languages']??[] as $relative=>$hash){self::materialize($dir,$m,$hash,WP_LANG_DIR.'/'.$relative,0644);}
        // DDL is deliberately before BEGIN: MySQL CREATE implicitly commits transactions.
        foreach($m['tables'] as $suffix=>$table){if($wpdb->query(self::createSQL($wpdb->prefix.$suffix,$table['schema']))===false){throw new \RuntimeException('Doeltabel maken mislukt: '.$suffix);}}
        $customerIds=array_map(static fn($u)=>(int)$u['row']['ID'],$m['users']);$admin=get_current_user_id();
        if($wpdb->query('START TRANSACTION')===false){throw new \RuntimeException('Importtransactie starten mislukt.');}
        try{
            Standalone::prepareContent();
            foreach($m['tables'] as $suffix=>$table){$targetTable=$wpdb->prefix.$suffix;$id=self::identifier($targetTable);if($suffix!=='options'&&$wpdb->query('DELETE FROM '.$id)===false){throw new \RuntimeException('Doeltabel legen mislukt.');}
                $carry='';foreach($m['blobs'][$table['blob']]['chunks'] as $chunk){$stream=fopen($dir.'/files/'.$chunk,'rb');try{while(!feof($stream)){$carry.=fread($stream,65536);while(($pos=strpos($carry,"\n"))!==false){$line=substr($carry,0,$pos);$carry=substr($carry,$pos+1);self::insertRow($targetTable,$suffix,$line,$m,$map,$customerIds,$admin);}}}finally{fclose($stream);}}if($carry!==''){throw new \RuntimeException('Onvolledige tabelregel.');}
            }
            foreach($m['users'] as $user){$row=$user['row'];if($wpdb->insert($wpdb->users,$row)===false){throw new \RuntimeException('Klantaccount importeren mislukt.');}foreach($user['meta'] as $key=>$values){$key=str_replace('{{prefix}}',$wpdb->prefix,$key);foreach($values as $value){if($wpdb->insert($wpdb->usermeta,['user_id'=>$row['ID'],'meta_key'=>$key,'meta_value'=>self::rewrite($value,$map)])===false){throw new \RuntimeException('Klantgegevens importeren mislukt.');}}}}
            wp_cache_flush();wp_upload_dir(null,false,true);Standalone::installModels($m);
            $active=array_merge(self::hostingPlugins((array)get_option('active_plugins',[])),array_map(static fn($e)=>$e['slug'].'/'.$e['entry'],array_values(array_filter($m['software']['components'],static fn($e)=>$e['kind']==='plugin'))));$active[]=plugin_basename(dirname(__DIR__).'/emonks-site-importer.php');update_option('active_plugins',array_values(array_unique($active)));update_option('home',$target);update_option('siteurl',$target);update_option('blog_public',0);delete_option('rewrite_rules');
            if($m['downloads']){update_option('woocommerce_file_download_method','force');$directories=$wpdb->prefix.'wc_product_download_directories';if(isset($m['tables']['wc_product_download_directories'])){$url='file://'.trailingslashit(self::downloadRoot());if(!$wpdb->get_var($wpdb->prepare('SELECT url FROM '.self::identifier($directories).' WHERE url=%s',$url))&&$wpdb->insert($directories,['url'=>$url,'enabled'=>1])===false){throw new \RuntimeException('Afgeschermde downloadmap goedkeuren mislukt.');}}}
            update_option('emsf_full_site_receipt',['tables'=>array_map(static fn($t)=>$t['rows'],$m['tables']),'upload_hashes'=>$uploadHashes,'postmeta_count'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}"),'users'=>array_map(static fn($u)=>['id'=>(int)$u['row']['ID'],'login'=>$u['row']['user_login'],'email'=>$u['row']['user_email']],$m['users']),'downloads'=>array_values(array_map(static fn($d)=>$d['blob'],$m['downloads']))],false);
            Standalone::commitReceipt();if($wpdb->query('COMMIT')===false){throw new \RuntimeException('Import vastleggen mislukt.');}
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');wp_cache_flush();throw $e;}
        wp_cache_flush();self::invalidateCommerceCaches();flush_rewrite_rules(false);
    }
    /** Plugin bootstrap can cache an empty catalogue before the imported tables exist. */
    public static function invalidateCommerceCaches(): void {
        if(!function_exists('wc_delete_product_transients')){return;}
        global $wpdb;
        delete_transient('wc_attribute_taxonomies');
        if(class_exists('WC_Cache_Helper')){\WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');}
        wc_delete_product_transients();
        // DB transients survive wp_cache_flush(), including children and variation prices.
        foreach($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')") as $id){wc_delete_product_transients((int)$id);}
    }
    private static function insertRow(string $table,string $suffix,string $line,array $m,array $map,array $customers,int $admin): void {
        global $wpdb;$encoded=json_decode($line,true,512,JSON_THROW_ON_ERROR);$row=[];foreach($encoded as $key=>$value){self::identifier($key);$raw=$value===null?null:base64_decode($value,true);if($value!==null&&$raw===false){throw new \RuntimeException('Ongeldige tabelwaarde.');}$row[$key]=self::rewrite($raw,$map);}
        if($suffix==='options'){$key=$row['option_name'];if(!self::optionAllowed($key)){throw new \RuntimeException('Uitgesloten optie in pakket.');}if($key===$m['source_prefix'].'user_roles'){$row['option_name']=$wpdb->prefix.'user_roles';}unset($row['option_id']);if($wpdb->delete($table,['option_name'=>$row['option_name']])===false){throw new \RuntimeException('Instelling vervangen mislukt.');}}
        if($suffix==='posts'&&!in_array((int)$row['post_author'],$customers,true)){$row['post_author']=$admin;}
        if($suffix==='comments'&&(int)$row['user_id']&&!in_array((int)$row['user_id'],$customers,true)){$row['user_id']=$admin;}
        if(in_array($suffix,['wc_orders','woocommerce_payment_tokens','woocommerce_downloadable_product_permissions','wc_customer_lookup','fluentform_submissions'],true)){$column=$suffix==='wc_orders'?'customer_id':'user_id';if(isset($row[$column])&&(int)$row[$column]&&!in_array((int)$row[$column],$customers,true)){$row[$column]=$admin;}}
        if($suffix==='postmeta'&&in_array($row['meta_key']??'',['_customer_user','_edit_last'],true)&&(int)$row['meta_value']&&!in_array((int)$row['meta_value'],$customers,true)){$row['meta_value']=$admin;}
        if($wpdb->insert($table,$row)===false){throw new \RuntimeException('Siteregel importeren mislukt: '.$suffix);}
    }
    public static function report(array $m,array $r): array {
        global $wpdb;$issues=[];$upload=wp_upload_dir();$receipt=get_option('emsf_full_site_receipt',[]);
        foreach($m['tables'] as $suffix=>$table){if($suffix==='options'||$suffix==='posts'||$suffix==='wc_product_download_directories'||preg_match(self::EPHEMERAL,$suffix)){continue;}if((int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::identifier($wpdb->prefix.$suffix))!==($suffix==='postmeta'?($receipt['postmeta_count']??$table['rows']):$table['rows'])){$issues[]='Aantal wijkt af: '.$suffix;}}
        foreach($m['posts'] as $post){if(!get_post($post['id'])){$issues[]='Bericht ontbreekt: '.$post['id'];}}
        foreach($m['uploads'] as $relative=>$hash){$path=$upload['basedir'].'/'.$relative;if(!is_file($path)||!hash_equals($receipt['upload_hashes'][$relative]??$hash,hash_file('sha256',$path))){$issues[]='Media ontbreekt of wijkt af: '.$relative;}}
        foreach($m['languages']??[] as $relative=>$hash){$path=WP_LANG_DIR.'/'.$relative;if(!is_file($path)||!hash_equals($hash,hash_file('sha256',$path))){$issues[]='Taalbestand ontbreekt: '.$relative;}}
        foreach($m['downloads'] as $d){$path=self::downloadRoot().'/'.$d['blob'].'-'.sanitize_file_name($d['name']);if(!is_file($path)||!hash_equals($d['blob'],hash_file('sha256',$path))){$issues[]='Afgeschermd downloadbestand ontbreekt.';}}
        return ['operation'=>$r['operation'],'state'=>'imported','committed'=>true,'ok'=>!$issues,'issues'=>$issues,'source'=>$r['source'],'target'=>$r['target'],'package_target'=>$r['package_target'],'backup'=>$r['backup']['id']??null,'fingerprint'=>$r['fingerprint'],'imported_at'=>$r['imported_at'],'software'=>array_map(static fn($e)=>array_intersect_key($e,array_flip(['role','name','version'])),$m['software']['components']),'counts'=>['posts'=>count($m['posts']),'forms'=>count($m['forms']),'models'=>count($m['models']),'files'=>count($m['uploads']),'tables'=>count($m['tables']),'downloads'=>count($m['downloads']),'customers'=>count($m['users'])],'checked_at'=>gmdate('c')];
    }
}
