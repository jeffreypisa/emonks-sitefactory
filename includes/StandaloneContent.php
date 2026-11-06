<?php
declare(strict_types=1);
namespace Emonks\Factory;

/** Same-installation content replacement. Accounts and credentials are never cleared. */
final class StandaloneContent {
    private const CORE = ['posts','postmeta','terms','term_taxonomy','term_relationships','termmeta','comments','commentmeta'];
    private const FORMS = ['fluentform_forms','fluentform_form_meta','fluentform_submissions','fluentform_submission_meta','fluentform_entry_details','fluentform_logs'];

    private static function exists(string $table): bool {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    }
    private static function rows(string $table): \Generator {
        global $wpdb;
        $primary=$wpdb->get_results("SHOW INDEX FROM `$table` WHERE Key_name='PRIMARY'", ARRAY_A);
        usort($primary,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        $order=$primary?' ORDER BY '.implode(',',array_map(static fn($r)=>'`'.str_replace('`','``',$r['Column_name']).'`',$primary)):'';
        for($offset=0;;$offset+=200){
            $rows=$wpdb->get_results("SELECT * FROM `$table`$order LIMIT 200 OFFSET $offset",ARRAY_A);
            if($wpdb->last_error){throw new \RuntimeException('Inhoud uitlezen mislukt.');}
            foreach($rows as $row){yield $row;}
            if(count($rows)<200){break;}
        }
    }
    private static function normalize(string $text): string {
        return trim((string)preg_replace('/\s+/u',' ',html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8')));
    }
    /** Conservative recognition: a familiar title alone never authorizes removal. */
    private static function sample(array $post): bool {
        global $wpdb;
        if($post['post_excerpt']!=='' || (int)$post['post_parent']!==0){return false;}
        $type=$post['post_type'];$content=self::normalize($post['post_content']);
        foreach($wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d",$post['ID']),ARRAY_A) as $meta){
            if(in_array($meta['meta_key'],['_edit_lock','_edit_last','_wp_trash_meta_status','_wp_trash_meta_time','_wp_desired_post_slug','_wp_suggested_privacy_policy_content'],true)){continue;}
            if($meta['meta_key']==='_wp_page_template' && in_array($meta['meta_value'],['','default'],true)){continue;}
            return false;
        }
        $paragraph=static fn($text)=>"<!-- wp:paragraph -->\n<p>".$text."</p>\n<!-- /wp:paragraph -->";
        $quote=static fn($text)=>"<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">\n".$paragraph($text)."\n</blockquote>\n<!-- /wp:quote -->";
        if($type==='post' && $post['post_title']===__('Hello world!')){
            return $content===self::normalize($paragraph(__('Welcome to WordPress. This is your first post. Edit or delete it, then start writing!')));
        }
        if($type!=='page'){return false;}
        if((int)$post['ID']===(int)get_option('wp_page_for_privacy_policy')){
            require_once ABSPATH.'wp-admin/includes/class-wp-privacy-policy-content.php';
            $normalizePolicy=static fn($text)=>self::normalize(str_replace(array_unique([home_url(),site_url()]),'{{installation}}',$text));
            if($post['post_title']===__('Privacy Policy') && $normalizePolicy($post['post_content'])===$normalizePolicy(\WP_Privacy_Policy_Content::get_default_content())){return true;}
        }
        if($post['post_title']!==__('Sample Page')){return false;}
        $intro=__("This is an example page. It's different from a blog post because it will stay in one place and will show up in your site navigation (in most themes). Most people start with an About page that introduces them to potential site visitors. It might say something like this:");
        $bike=__("Hi there! I'm a bike messenger by day, aspiring actor by night, and this is my website. I live in Los Angeles, have a great dog named Jack, and I like pi&#241;a coladas. (And gettin' caught in the rain.)");
        $company=__('The XYZ Doohickey Company was founded in 1971, and has been providing quality doohickeys to the public ever since. Located in Gotham City, XYZ employs over 2,000 people and does all kinds of awesome things for the Gotham community.');
        $end=sprintf(__('As a new WordPress user, you should go to <a href="%s">your dashboard</a> to delete this page and create new pages for your content. Have fun!'),admin_url());
        return $content===self::normalize(implode("\n\n",[$paragraph($intro),$quote($bike),$paragraph(__('...or something like this:')),$quote($company),$paragraph($end)]));
    }
    public static function inventory(): array {
        Standalone::authorize();global $wpdb;
        $result=['counts'=>['pages'=>0,'posts'=>0,'media'=>0,'other'=>0,'comments'=>0,'terms'=>0,'forms'=>0,'submissions'=>0], 'items'=>[], 'sample_ids'=>[], 'samples_only'=>true];
        $technical=['revision','auto-draft','wp_global_styles','wp_font_family','wp_font_face'];
        $technicalTerms=array_map('intval',$wpdb->get_col("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='wp_theme'"));
        $hash=hash_init('sha256');
        foreach(array_merge(self::CORE,self::FORMS) as $suffix){
            $table=$wpdb->prefix.$suffix;if(!self::exists($table)){continue;}
            hash_update($hash,$suffix);
            foreach(self::rows($table) as $row){
                hash_update($hash,serialize($row));
                if($suffix==='posts'){
                    if($row['post_status']==='auto-draft'||in_array($row['post_type'],$technical,true)){continue;}
                    $kind=['page'=>'pages','post'=>'posts','attachment'=>'media'][$row['post_type']]??'other';
                    $result['counts'][$kind]++;
                    $sample=self::sample($row);
                    if($sample){$result['sample_ids'][]=(int)$row['ID'];}else{$result['samples_only']=false;}
                    $result['items'][]=['kind'=>$kind,'id'=>(int)$row['ID'],'title'=>$row['post_title']?:'(zonder titel)','status'=>$row['post_status'],'sample'=>$sample];
                }elseif($suffix==='comments'){
                    $result['counts']['comments']++;
                    $defaultComment=sprintf(__('Hi, this is a comment.
To get started with moderating, editing, and deleting comments, please visit the Comments screen in the dashboard.
Commenter avatars come from <a href="%s">Gravatar</a>.'),esc_url(__('https://gravatar.com/')));
                    $sample=in_array((int)$row['comment_post_ID'],$result['sample_ids'],true) && $row['comment_author']===__('A WordPress Commenter') && in_array($row['comment_author_email'],['wapuu@wordpress.example','wapuu@wordpress.org'],true) && self::normalize($row['comment_content'])===self::normalize($defaultComment);
                    if(!$sample){$result['samples_only']=false;}
                    $result['items'][]=['kind'=>'comments','id'=>(int)$row['comment_ID'],'title'=>$row['comment_author'],'status'=>$row['comment_approved'],'sample'=>$sample];
                }elseif($suffix==='term_taxonomy'){
                    // The built-in empty category is normal on a fresh installation.
                    if(!in_array($row['taxonomy'],['category','wp_theme'],true)){$result['samples_only']=false;}
                }elseif($suffix==='terms'){
                    if((int)$row['term_id']!==(int)get_option('default_category',1) && !in_array((int)$row['term_id'],$technicalTerms,true)){$result['counts']['terms']++;$result['samples_only']=false;$result['items'][]=['kind'=>'terms','id'=>(int)$row['term_id'],'title'=>$row['name'],'status'=>'term','sample'=>false];}
                }elseif($suffix==='fluentform_forms'){
                    $result['counts']['forms']++;$result['samples_only']=false;
                    $result['items'][]=['kind'=>'forms','id'=>(int)$row['id'],'title'=>$row['title']??'(formulier)','status'=>$row['status']??'','sample'=>false];
                }elseif($suffix==='fluentform_submissions'){$result['counts']['submissions']++;$result['samples_only']=false;}
            }
        }
        $result['empty']=array_sum($result['counts'])===0 && $result['samples_only'];
        $result['fingerprint']=hash_final($hash);
        return $result;
    }
    /** Lock content rows and insertion gaps before comparing the reviewed snapshot. */
    public static function lockInventory(string $fingerprint): void {
        global $wpdb;
        foreach(array_merge(self::CORE,self::FORMS) as $suffix){
            $table=$wpdb->prefix.$suffix;
            if(self::exists($table) && $wpdb->query("SELECT 1 FROM `$table` FOR UPDATE")===false){throw new \RuntimeException('Inhoud vergrendelen mislukt. Probeer opnieuw.');}
        }
        if(!hash_equals($fingerprint,self::inventory()['fingerprint'])){throw new \RuntimeException('De inhoud is sinds de bevestiging gewijzigd. Er is niets verwijderd; bekijk het overzicht opnieuw.');}
    }
    public static function validateChoice(array $inventory,string $mode): void {
        if(!in_array($mode,['empty','samples','replace'],true)){throw new \RuntimeException('Kies hoe de bestaande inhoud wordt behandeld.');}
        if($mode==='empty' && !$inventory['empty']){throw new \RuntimeException('De bestemming bevat inhoud. Kies voorbeeldinhoud opruimen of bestaande inhoud vervangen en bekijk eerst het overzicht.');}
        if($mode==='samples' && !$inventory['samples_only']){throw new \RuntimeException('Er staat meer dan ongewijzigde WordPress-voorbeeldinhoud. Kies bewust Bestaande website-inhoud vervangen.');}
    }
    private static function tables(): array {
        global $wpdb;
        $tables=$wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS WHERE Name LIKE %s',$wpdb->esc_like($wpdb->prefix).'%'),ARRAY_A);
        if(!$tables||$wpdb->last_error){throw new \RuntimeException('Tabellen inventariseren mislukt.');}
        $result=[];
        foreach($tables as $row){
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$row['Name'])||strcasecmp($row['Engine']??'','InnoDB')){throw new \RuntimeException('Alle doeltabellen moeten InnoDB gebruiken.');}
            if(!in_array($row['Name'],[$wpdb->users,$wpdb->usermeta],true)){$result[]=$row['Name'];}
        }
        return $result;
    }
    private static function schema(string $table): string {
        global $wpdb;$schema=$wpdb->get_row("SHOW CREATE TABLE `$table`",ARRAY_N);
        if(!$schema||$wpdb->last_error){throw new \RuntimeException('Tabelschema uitlezen mislukt.');}
        return preg_replace('/ AUTO_INCREMENT=\d+/','',$schema[1]);
    }
    /** WooCommerce may add ordinary lookup indexes at activation; columns/constraints must match. */
    public static function compatibleSchema(string $before,string $after): bool {
        $normalize=static function(string $sql): array {$lines=preg_split('/\r?\n/',$sql);$result=[];foreach($lines as $line){$line=trim($line);if(preg_match('/^KEY `[^`]+` \(/D',$line)){continue;}$result[]=rtrim($line,',');}return $result;};
        return $normalize($before)===$normalize($after);
    }
    private static function files(string $root): \Generator {
        if(!is_dir($root)){return;}
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $file){
            if($file->isLink()){throw new \RuntimeException('Een symlink in uploads vereist handmatige controle.');}
            if($file->isFile()){yield substr($file->getPathname(),strlen($root)+1)=>$file->getPathname();}
        }
    }
    private static function path(string $id): string {
        if(!preg_match('/^backup-[a-f0-9-]{36}$/D',$id)){throw new \RuntimeException('Ongeldige herstelkopie.');}
        $path=Standalone::storage().'/'.$id;
        if(is_link($path)){throw new \RuntimeException('Ongeldige herstelkopie.');}
        return $path;
    }
    public static function backup(): array {
        Standalone::authorize();global $wpdb;
        if(function_exists('wp_raise_memory_limit')){wp_raise_memory_limit('admin');}
        $id='backup-'.wp_generate_uuid4();$dir=self::path($id);
        if(!mkdir($dir,0700)){throw new \RuntimeException('Herstelmap maken mislukt.');}
        $upload=wp_upload_dir();if(!empty($upload['error'])||is_link($upload['basedir'])){throw new \RuntimeException('Uploadmap niet beschikbaar voor herstelkopie.');}
        $free=disk_free_space(Standalone::storage());$bytes=0;foreach(self::files($upload['basedir']) as $file){$bytes+=filesize($file);}
        if($free===false||$free<$bytes*2+64*1024*1024){throw new \RuntimeException('Onvoldoende ruimte voor de herstelkopie.');}
        $backup=['format'=>'emsf-standalone-backup-v1','created'=>gmdate('c'),'site'=>site_url(),'uploads'=>$upload['basedir'],'tables'=>[],'files'=>[]];
        if($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT')===false){throw new \RuntimeException('Herstelkopie starten mislukt.');}
        try{
            foreach(self::tables() as $table){
                $name='table-'.count($backup['tables']).'.rows';$stream=fopen($dir.'/'.$name,'xb');
                if(!$stream){throw new \RuntimeException('Herstelbestand openen mislukt.');}chmod($dir.'/'.$name,0600);
                try{foreach(self::rows($table) as $row){$line=base64_encode(serialize($row))."\n";if(fwrite($stream,$line)!==strlen($line)){throw new \RuntimeException('Tabelherstelkopie schrijven mislukt.');}}}finally{fclose($stream);}
                $backup['tables'][$table]=['schema'=>self::schema($table),'file'=>$name,'sha256'=>hash_file('sha256',$dir.'/'.$name)];
            }
            foreach(self::files($upload['basedir']) as $relative=>$file){
                $target=$dir.'/uploads/'.$relative;if(!wp_mkdir_p(dirname($target))||!copy($file,$target)||!chmod($target,0600)){throw new \RuntimeException('Mediaherstelkopie schrijven mislukt.');}
                $hash=hash_file('sha256',$file);if(!hash_equals($hash,hash_file('sha256',$target))){throw new \RuntimeException('Een mediabestand wijzigde tijdens de herstelkopie.');}
                $backup['files'][$relative]=$hash;
            }
            Package::json($dir.'/backup.json',$backup);chmod($dir.'/backup.json',0600);
        }finally{$wpdb->query('ROLLBACK');}
        return ['id'=>$id,'sha256'=>hash_file('sha256',$dir.'/backup.json')];
    }
    public static function verify(array $receipt): array {
        Standalone::authorize();
        $dir=self::path((string)($receipt['id']??''));$file=$dir.'/backup.json';
        if(!is_file($file)||!hash_equals((string)($receipt['sha256']??''),hash_file('sha256',$file))){throw new \RuntimeException('De herstelkopie is beschadigd.');}
        $backup=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if(($backup['format']??'')!=='emsf-standalone-backup-v1'||$backup['site']!==site_url()||$backup['uploads']!==wp_upload_dir()['basedir']){throw new \RuntimeException('Herstelkopie hoort niet bij deze installatie.');}
        $tables=self::tables();
        foreach($backup['tables'] as $table=>$entry){
            if(!in_array($table,$tables,true)||!self::compatibleSchema($entry['schema'],self::schema($table))||!preg_match('/^table-\d+\.rows$/D',$entry['file'])){throw new \RuntimeException('Databaseschema is gewijzigd; handmatig herstel vereist.');}
            $path=$dir.'/'.$entry['file'];if(!is_file($path)||!hash_equals($entry['sha256'],hash_file('sha256',$path))){throw new \RuntimeException('Tabelherstelkopie is beschadigd.');}
        }
        foreach($backup['files'] as $relative=>$hash){
            if(str_contains($relative,'..')||str_starts_with($relative,'/')||is_link($dir.'/uploads/'.$relative)||!is_file($dir.'/uploads/'.$relative)||!hash_equals($hash,hash_file('sha256',$dir.'/uploads/'.$relative))){throw new \RuntimeException('Mediaherstelkopie is beschadigd.');}
        }
        return $backup;
    }
    /** Called only within Package::import's transaction, after backup and confirmation. */
    public static function clear(string $mode,array $inventory): void {
        Standalone::authorize();global $wpdb;
        if(!Standalone::importing()){throw new \RuntimeException('Verwijderen vereist een actieve, bevestigde opleverimport.');}
        self::validateChoice($inventory,$mode);
        if($mode==='empty'){return;}
        if($mode==='samples'){
            $ids=implode(',',array_map('intval',$inventory['sample_ids']));if(!$ids){return;}
            $queries=["DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN (SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ($ids))","DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ($ids)","DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($ids)","DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($ids)","DELETE FROM {$wpdb->posts} WHERE ID IN ($ids)","UPDATE {$wpdb->term_taxonomy} SET count=(SELECT COUNT(*) FROM {$wpdb->term_relationships} r WHERE r.term_taxonomy_id={$wpdb->term_taxonomy}.term_taxonomy_id)"];
            foreach($queries as $sql){if($wpdb->query($sql)===false){throw new \RuntimeException('Voorbeeldinhoud opruimen mislukt.');}}
        }else{
            foreach(array_merge(self::CORE,self::FORMS) as $suffix){$table=$wpdb->prefix.$suffix;if(self::exists($table)&&$wpdb->query("DELETE FROM `$table`")===false){throw new \RuntimeException('Inhoud vervangen mislukt.');}}
            foreach($wpdb->get_col("SELECT option_name FROM {$wpdb->options}") as $key){if((preg_match('/^_?options_/',$key)&&!str_contains($key,'skeletor_theme_options_roles'))||in_array($key,['emsf_import_map','theme_mods_skeletor','page_on_front','page_for_posts','wp_page_for_privacy_policy'],true)){delete_option($key);}}
        }
        wp_cache_flush();
    }
    /** Old media are removed only after content and the durable receipt have committed. */
    public static function cleanOldUploads(array $receipt,array $package): void {
        $backup=self::verify($receipt);$keep=[];$uploads=wp_upload_dir()['basedir'];
        if(($package['format']??'')===FullSite::FORMAT){$keep=array_fill_keys(array_keys($package['uploads']),true);}
        else{foreach(array_keys($package['files']) as $name){$keep[str_starts_with($name,'fonts/')?'skeletor-fonts/'.substr($name,6):'site-factory/'.$name]=true;}}
        foreach(get_posts(['post_type'=>'attachment','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids']) as $attachment){
            $file=get_post_meta($attachment,'_wp_attached_file',true);if($file){$keep[$file]=true;$meta=wp_get_attachment_metadata($attachment);foreach($meta['sizes']??[] as $size){$keep[(dirname($file)==='.'?'':dirname($file).'/').$size['file']]=true;}if(!empty($meta['original_image'])){$keep[dirname($file).'/'.$meta['original_image']]=true;}}
        }
        foreach($backup['files'] as $relative=>$hash){
            if(isset($keep[$relative])||in_array(basename($relative),['.htaccess','web.config','index.php','index.html'],true)){continue;}
            $path=$uploads.'/'.$relative;
            if(is_file($path)&&(!hash_equals($hash,hash_file('sha256',$path))||!unlink($path))){throw new \RuntimeException('Oude media zijn gewijzigd of konden niet worden opgeruimd. De herstelkopie blijft bewaard.');}
        }
    }
    public static function archive(array $receipt): string {
        Standalone::authorize();$backup=self::verify($receipt);$dir=self::path($receipt['id']);
        $path=Standalone::storage().'/download-'.wp_generate_uuid4().'.zip';$zip=new \ZipArchive();
        if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::EXCL)!==true){throw new \RuntimeException('Herstelkopie downloaden voorbereiden mislukt.');}
        try{
            if(!$zip->addFile($dir.'/backup.json','backup.json')){throw new \RuntimeException('Herstelarchief schrijven mislukt.');}
            foreach($backup['tables'] as $entry){if(!$zip->addFile($dir.'/'.$entry['file'],$entry['file'])){throw new \RuntimeException('Herstelarchief schrijven mislukt.');}}
            foreach($backup['files'] as $relative=>$hash){if(!$zip->addFile($dir.'/uploads/'.$relative,'uploads/'.$relative)){throw new \RuntimeException('Herstelarchief schrijven mislukt.');}}
            if(!$zip->close()){throw new \RuntimeException('Herstelarchief afronden mislukt.');}chmod($path,0600);return $path;
        }catch(\Throwable $e){$zip->close();if(is_file($path)){unlink($path);}throw $e;}
    }
    public static function restore(array $receipt): void {
        Standalone::authorize();global $wpdb;$backup=self::verify($receipt);$dir=self::path($receipt['id']);$fullReceipt=get_option('emsf_full_site_receipt',[]);
        foreach($backup['files'] as $relative=>$hash){$target=$backup['uploads'].'/'.$relative;if(!wp_mkdir_p(dirname($target))||!copy($dir.'/uploads/'.$relative,$target)){throw new \RuntimeException('Media terugzetten mislukt.');}}
        if($wpdb->query('START TRANSACTION')===false){throw new \RuntimeException('Herstel starten mislukt.');}
        try{
            if($fullReceipt){foreach(self::tables() as $table){if(!isset($backup['tables'][$table])&&$wpdb->query('DELETE FROM `'.$table.'`')===false){throw new \RuntimeException('Nieuwe sitetabel opruimen bij herstel mislukt.');}}foreach($fullReceipt['users']??[] as $user){$matches=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d AND user_login=%s AND user_email=%s",$user['id'],$user['login'],$user['email']));if(!$matches){throw new \RuntimeException('Een geïmporteerd klantaccount is gewijzigd. Controleer dit account vóór herstel.');}if($wpdb->delete($wpdb->usermeta,['user_id'=>$user['id']])===false||$wpdb->delete($wpdb->users,['ID'=>$user['id']])===false){throw new \RuntimeException('Geïmporteerd klantaccount opruimen mislukt.');}}}
            foreach($backup['tables'] as $table=>$entry){
                if($wpdb->query("DELETE FROM `$table`")===false){throw new \RuntimeException('Herstel wissen mislukt.');}
                $stream=fopen($dir.'/'.$entry['file'],'rb');if(!$stream){throw new \RuntimeException('Herstelbestand openen mislukt.');}
                try{while(($line=fgets($stream))!==false){$row=unserialize(base64_decode(trim($line),true),['allowed_classes'=>false]);if(!is_array($row)||$wpdb->insert($table,$row)===false){throw new \RuntimeException('Herstel invoegen mislukt.');}}}finally{fclose($stream);}
            }
            if($wpdb->query('COMMIT')===false){throw new \RuntimeException('Herstel vastleggen mislukt.');}
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
        finally{wp_cache_flush();}
        foreach(self::files($backup['uploads']) as $relative=>$file){
            if(!isset($backup['files'][$relative])&&!in_array(basename($relative),['.htaccess','web.config','index.php','index.html'],true)&&!unlink($file)){throw new \RuntimeException('Geïmporteerde media opruimen na herstel mislukt.');}
        }
    }
}
