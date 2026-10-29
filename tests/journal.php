<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use WhollyCrypto\Payment\Core\Repository;
$socket = getenv('WHOLLY_TEST_DB_SOCKET');
if (!$socket || !is_file($socket) && filetype($socket) !== 'socket') { throw new RuntimeException('Disposable DB socket required.'); }
$db = new PDO('mysql:unix_socket='.$socket.';dbname=wholly_journal','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$make = static function(PDO $db): Repository {
    return new Repository(
        static function($sql,$args)use($db){$s=$db->prepare($sql);$s->execute($args);return $s->rowCount();},
        static function($sql,$args)use($db){$s=$db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);},
        static function($work)use($db){$db->beginTransaction();try{$r=$work();$db->commit();return $r;}catch(Throwable $e){$db->rollBack();throw $e;}},
        'whollycrypto_journal_fixture'
    );
};
$repo=$make($db);$repo->install([]);
$a=['order_id'=>bin2hex(random_bytes(8)),'invoice_id'=>'33333333-3333-4333-8333-'.bin2hex(random_bytes(6)),'status'=>'new'];
$repo->locked($a['order_id'],function()use($repo,$a){$repo->save($a);});
if($repo->load($a['order_id'])!==$a)throw new RuntimeException('Roundtrip');
$collision=$a;$collision['order_id']=bin2hex(random_bytes(8));
try{$repo->save($collision);throw new LogicException('Invoice collision accepted');}catch(PDOException $e){}
if($repo->load($a['order_id'])!==$a)throw new RuntimeException('Collision changed owner');
try{$repo->atomic(function()use($repo,$a){$r=$a;$r['status']='paid';$repo->save($r);throw new RuntimeException('rollback');});}catch(RuntimeException $e){}
if($repo->load($a['order_id'])!==$a)throw new RuntimeException('Rollback failed');
$other=$make(new PDO('mysql:unix_socket='.$socket.';dbname=wholly_journal','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]));
$repo->locked($a['order_id'],function()use($other,$a){
    try{$other->locked($a['order_id'],function(){throw new LogicException('Concurrent lock accepted');});throw new LogicException('Lock not rejected');}
    catch(RuntimeException $e){}
});
$other->locked($a['order_id'],function(){});
$db->exec('CREATE TABLE IF NOT EXISTS whollycrypto_nontransactional (id INT) ENGINE=MyISAM');
try{$repo->install(['whollycrypto_nontransactional']);throw new LogicException('Nontransactional orders accepted');}catch(RuntimeException $e){}
echo "Journal: persistence, invoice uniqueness, rollback, concurrent exclusion, release and InnoDB guards passed.\n";
