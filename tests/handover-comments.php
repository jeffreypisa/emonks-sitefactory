<?php
// Execute the production comment query on disposable SQL data; no WP/database/network access.
use Emonks\Factory\Handover;
require dirname(__DIR__).'/includes/Handover.php';
define('ARRAY_A','ARRAY_A');
final class CommentFixture {
    public string $comments='fixture_comments';
    public string $posts='fixture_posts';
    public PDO $db;
    public bool $fail=false;
    public function __construct(){
        $this->db=new PDO('sqlite::memory:');$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE fixture_posts (ID INTEGER PRIMARY KEY,post_status TEXT); CREATE TABLE fixture_comments (comment_ID INTEGER PRIMARY KEY,comment_post_ID INTEGER,comment_approved TEXT,comment_type TEXT NOT NULL DEFAULT "")');
        $this->db->exec("INSERT INTO fixture_posts VALUES (1,'publish'),(2,'draft'),(3,'trash')");
    }
    public function get_row($sql,$format){return $this->fail?null:$this->db->query($sql)->fetch(PDO::FETCH_ASSOC);}
    public function insert(string $status,int $post=1,string $type=''): void {$this->db->prepare('INSERT INTO fixture_comments (comment_post_ID,comment_approved,comment_type) VALUES (?,?,?)')->execute([$post,$status,$type]);}
    public function clear(): void {$this->db->exec('DELETE FROM fixture_comments');}
}
$wpdb=new CommentFixture();$checks=0;
function verify($ok,$message){global $checks;if(!$ok){throw new RuntimeException($message);}$checks++;}
function blocked(callable $fn,string $expected){try{$fn();}catch(RuntimeException $e){verify(str_contains($e->getMessage(),$expected),'Expected '.$expected.', received '.$e->getMessage());return;}throw new RuntimeException('Expected block: '.$expected);}
verify(Handover::checkComments()===['blocking'=>0,'excluded'=>0,'order_notes'=>0],'Empty source passes');
foreach(['spam','trash','post-trashed'] as $status){$wpdb->clear();$wpdb->insert($status);verify(Handover::checkComments()===['blocking'=>0,'excluded'=>1,'order_notes'=>0],'Discarded '.$status.' does not block');}
foreach(['1','0'] as $status){$wpdb->clear();$wpdb->insert($status);blocked(static fn()=>Handover::checkComments(),'1 actieve');verify(Handover::commentInventory()===['blocking'=>1,'excluded'=>0,'order_notes'=>0],'Active '.$status.' counted');}
$wpdb->clear();$wpdb->insert('1',3);verify(Handover::checkComments()===['blocking'=>0,'excluded'=>1,'order_notes'=>0],'Comment on trashed post excluded even with old approval state');
$wpdb->clear();$wpdb->insert('1',999);blocked(static fn()=>Handover::checkComments(),'1 actieve');
$wpdb->clear();$wpdb->insert('1',2);blocked(static fn()=>Handover::checkComments(),'1 actieve');
$wpdb->clear();$wpdb->insert('plugin-specific');blocked(static fn()=>Handover::checkComments(),'1 actieve');
$wpdb->clear();foreach(['spam','trash','post-trashed','0','1'] as $status){$wpdb->insert($status);}
$before=$wpdb->db->query('SELECT * FROM fixture_comments ORDER BY comment_ID')->fetchAll(PDO::FETCH_ASSOC);
verify(Handover::commentInventory()===['blocking'=>2,'excluded'=>3,'order_notes'=>0],'Mixed counts distinguish real and discarded comments');
blocked(static fn()=>Handover::checkComments(),'2 actieve');
verify($before===$wpdb->db->query('SELECT * FROM fixture_comments ORDER BY comment_ID')->fetchAll(PDO::FETCH_ASSOC),'Source comments unchanged');
$wpdb->clear();$wpdb->insert('1',1,'order_note');
verify(Handover::commentInventory()===['blocking'=>1,'excluded'=>0,'order_notes'=>1],'Order notes identified separately and remain blocking');
blocked(static fn()=>Handover::checkComments(),'1 WooCommerce-bestelnotities');
$wpdb->insert('0',1);blocked(static fn()=>Handover::checkComments(),'Daarnaast zijn er 1 actieve');
$wpdb->clear();$wpdb->insert('1',999,'order_note');blocked(static fn()=>Handover::checkComments(),'WooCommerce-overdracht');
$wpdb->clear();$wpdb->insert('spam',1,'order_note');$wpdb->insert('1',3,'order_note');
verify(Handover::checkComments()===['blocking'=>0,'excluded'=>2,'order_notes'=>0],'Discarded order notes follow the existing discarded-content policy');
$wpdb->fail=true;blocked(static fn()=>Handover::checkComments(),'niet worden gecontroleerd');
echo "$checks handover comment checks passed.\n";
