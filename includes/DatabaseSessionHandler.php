<?php
declare(strict_types=1);

/** Shared login/CSRF state across independent serverless instances. */
final class DatabaseSessionHandler implements SessionHandlerInterface
{
    private ?string $lockedId=null;
    public function __construct(private PDO $pdo) {}
    public function open(string $path,string $name):bool{return true;}
    public function close():bool{
        if($this->lockedId!==null){$sql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'?'SELECT pg_advisory_unlock(hashtext(?),91204)':'SELECT RELEASE_LOCK(?)';$this->pdo->prepare($sql)->execute([$this->lockedId]);$this->lockedId=null;}return true;
    }
    public function read(string $id):string|false
    {
        $key=hash('sha256',$id);$pg=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql';$lock=$this->pdo->prepare($pg?'SELECT pg_advisory_lock(hashtext(?),91204)':'SELECT GET_LOCK(?,15)');$lock->execute([$key]);if(!$pg&&(int)$lock->fetchColumn()!==1)return false;$this->lockedId=$key;
        $s=$this->pdo->prepare('SELECT payload FROM app_sessions WHERE id=? AND expires_at>?');$s->execute([$key,time()]);$data=$s->fetchColumn();return $data?base64_decode($data,true):'';
    }
    public function write(string $id,string $data):bool
    {
        $sql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
            ?'INSERT INTO app_sessions(id,payload,expires_at) VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET payload=EXCLUDED.payload,expires_at=EXCLUDED.expires_at'
            :'INSERT INTO app_sessions(id,payload,expires_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at)';
        return $this->pdo->prepare($sql)->execute([hash('sha256',$id),base64_encode($data),time()+max(1800,(int)ini_get('session.gc_maxlifetime'))]);
    }
    public function destroy(string $id):bool{return $this->pdo->prepare('DELETE FROM app_sessions WHERE id=?')->execute([hash('sha256',$id)]);}
    public function gc(int $max_lifetime):int|false{$s=$this->pdo->prepare('DELETE FROM app_sessions WHERE expires_at<?');$s->execute([time()]);return $s->rowCount();}
}
