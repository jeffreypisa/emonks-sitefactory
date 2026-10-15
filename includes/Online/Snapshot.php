<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;

/** Same-site recovery only; global users/usermeta and other sites are never included. */
final class Snapshot {
    private static bool $local = false;
    public static function localBackup(): string {
        \Emonks\Factory\Factory::local(); Sites::site(get_current_blog_id());
        self::$local=true;try{return self::create();}finally{self::$local=false;}
    }
    public static function localRestore(string $id): void {
        \Emonks\Factory\Factory::local();Sites::site(get_current_blog_id());
        self::$local=true;try{self::restore($id);}finally{self::$local=false;}
    }
    private static function tables(): array {
        global $wpdb;
        $id = get_current_blog_id();
        if ($id === get_main_site_id() || (!Privacy::managed() && !self::$local)) { throw new \RuntimeException('Alleen beheerde subsites kunnen worden hersteld.'); }
        $prefix = $wpdb->get_blog_prefix($id);
        $rows = $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS WHERE Name LIKE %s', $wpdb->esc_like($prefix).'%'), ARRAY_A);
        if (!$rows || $wpdb->last_error) { throw new \RuntimeException('Tabellen inventariseren mislukt.'); }
        foreach ($rows as $row) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $row['Name']) || strcasecmp($row['Engine'] ?? '', 'InnoDB')) { throw new \RuntimeException('Alle subsite-tabellen moeten InnoDB zijn.'); }
        }
        return array_column($rows, 'Name');
    }
    public static function create(): string {
        global $wpdb;
        $tables = self::tables();
        $id = 'backup-'.get_current_blog_id().'-'.wp_generate_uuid4();
        $backup = ['site'=>get_current_blog_id(), 'created'=>gmdate('c'), 'tables'=>[], 'files'=>[]];
        if ($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') === false) { throw new \RuntimeException('Snapshottransactie mislukt.'); }
        try {
            foreach ($tables as $table) {
                $schema = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
                $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
                if (!$schema || $wpdb->last_error) { throw new \RuntimeException('Tabelback-up mislukt.'); }
                $backup['tables'][$table] = ['schema'=>self::schema($schema[1]), 'rows'=>$rows];
            }
            Store::copyTree(self::$local ? wp_upload_dir()['basedir'] : Store::root().'/uploads/'.get_current_blog_id(), Store::root().'/'.$id.'/uploads');
            Store::copyTree(Store::root().'/models/'.get_current_blog_id(), Store::root().'/'.$id.'/models');
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(Store::root().'/'.$id, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) { $backup['files'][substr($file->getPathname(), strlen(Store::root().'/'.$id.'/'))] = hash_file('sha256', $file->getPathname()); }
            }
            Store::write($id, $backup);
            if (Store::read($id) !== $backup) { throw new \RuntimeException('Snapshot teruglezen mislukt.'); }
        } finally { $wpdb->query('ROLLBACK'); }
        return $id;
    }
    public static function clearFiles(): void {
        if (!Privacy::managed() || get_current_blog_id() === get_main_site_id()) { throw new \RuntimeException('Ongeldige site voor mediawissel.'); }
        $path = Store::root().'/uploads/'.get_current_blog_id();
        if (!is_dir($path)) { return; }
        if (is_link($path) || realpath($path) !== $path) { throw new \RuntimeException('Onverwachte mediamap; handmatige controle vereist.'); }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isLink()) { throw new \RuntimeException('Symlink in mediamap geweigerd.'); }
            $ok = $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            if (!$ok) { throw new \RuntimeException('Mediawissel mislukt; herstel vereist.'); }
        }
    }
    private static function schema(string $sql): string { return preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql); }
    public static function restore(string $id): void {
        global $wpdb;
        Store::key($id);
        $backup = Store::read($id);
        if (($backup['site'] ?? 0) !== get_current_blog_id()) { throw new \RuntimeException('Herstelkopie hoort niet bij deze site.'); }
        $tables = self::tables(); sort($tables); $saved = array_keys($backup['tables']); sort($saved);
        if ($tables !== $saved) { throw new \RuntimeException('Tabellen zijn gewijzigd; handmatig databaseherstel vereist.'); }
        foreach ($backup['tables'] as $table=>$data) {
            $schema = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            if (!$schema || self::schema($schema[1]) !== $data['schema']) { throw new \RuntimeException('Databaseschema gewijzigd; herstel eerst dezelfde pluginversies.'); }
        }
        foreach ($backup['files'] as $relative=>$hash) {
            $file = Store::root().'/'.$id.'/'.$relative;
            if (!is_file($file) || !hash_equals($hash, hash_file('sha256', $file))) { throw new \RuntimeException('Herstelkopie beschadigd; geen wijzigingen toegepast.'); }
        }
        // Copy verified originals first; failure leaves the site offline and can be retried.
        if(!self::$local){self::clearFiles();}
        Store::copyTree(Store::root().'/'.$id.'/uploads', self::$local ? wp_upload_dir()['basedir'] : Store::root().'/uploads/'.get_current_blog_id());
        $models = Store::root().'/models/'.get_current_blog_id();
        foreach (glob($models.'/*.json') ?: [] as $file) { if (!unlink($file)) { throw new \RuntimeException('Projectmodel niet verwijderbaar.'); } }
        Store::copyTree(Store::root().'/'.$id.'/models', $models);
        if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Hersteltransactie mislukt.'); }
        try {
            foreach ($backup['tables'] as $table=>$data) {
                if ($wpdb->query("DELETE FROM `$table`") === false) { throw new \RuntimeException('Herstel wissen mislukt.'); }
                foreach ($data['rows'] as $row) { if ($wpdb->insert($table, $row) === false) { throw new \RuntimeException('Herstel invoegen mislukt.'); } }
            }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Herstel commit mislukt.'); }
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
        finally { wp_cache_flush(); }
    }
    /** Called inside the existing package transaction. Keep users, roles and access options. */
    public static function clearContent(): void {
        global $wpdb;
        foreach (['posts','postmeta','terms','term_taxonomy','term_relationships','termmeta','comments','commentmeta'] as $suffix) {
            $table = $wpdb->prefix.$suffix;
            if ($wpdb->query("DELETE FROM `$table`") === false) { throw new \RuntimeException('Inhoud vervangen mislukt.'); }
        }
        foreach (['fluentform_forms','fluentform_form_meta'] as $suffix) {
            $table = $wpdb->prefix.$suffix;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) && $wpdb->query("DELETE FROM `$table`") === false) { throw new \RuntimeException('Formulieren vervangen mislukt.'); }
        }
        // Submissions are outside the transfer scope: refuse replacement if present.
        foreach ($wpdb->get_col("SELECT option_name FROM {$wpdb->options}") as $key) {
            if ((preg_match('/^_?options_/', $key) && !str_contains($key, 'skeletor_theme_options_roles')) || in_array($key, ['emsf_import_map','theme_mods_skeletor'], true)) { delete_option($key); }
        }
        wp_cache_flush();
    }
}
