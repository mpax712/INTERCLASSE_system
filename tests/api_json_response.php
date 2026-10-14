<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/db.php';
$pdo=db();$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$namespace=(string)$pdo->query($driver==='pgsql'?'SELECT current_schema()':'SELECT DATABASE()')->fetchColumn();
if(!str_starts_with($namespace,'interclasse_admin_test_'))throw new RuntimeException('Use uma cópia isolada interclasse_admin_test_*.');

if(($argv[1]??'')==='--case'){
    require __DIR__.'/../api/_bootstrap.php';
    switch($argv[2]??''){
        case 'warning':
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO classes(name) VALUES(?)')->execute(['QA JSON rollback']);
            trigger_error('Simulated API warning',E_USER_WARNING);
            break;
        case 'exception': throw new RuntimeException('Simulated API exception');
        case 'startup':
            // The error handler must not fail a second time if rollback cannot connect.
            throw new PDOException('Simulated database startup failure');
        case 'encoding': json_response(['label'=>"invalid \xFF"]);
        default:
            echo '<br /><b>Unexpected output</b>';
            trigger_error('Simulated harmless deprecation',E_USER_DEPRECATED);
            json_response(['ok'=>true]);
    }
    exit;
}
foreach(['output','warning','exception','startup','encoding'] as $case){
    $process=proc_open([PHP_BINARY,__FILE__,'--case',$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
    $payload=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
    if(in_array($case,['warning','exception','startup'],true)&&($payload['ok']??true)!==false)throw new RuntimeException('Error not reported: '.$case);
    if(str_contains($stdout,'<br')||str_contains($stdout,'Simulated'))throw new RuntimeException('PHP output leaked: '.$case);
    echo 'PASS JSON response: '.$case.PHP_EOL;
}
if((int)$pdo->query("SELECT COUNT(*) FROM classes WHERE name='QA JSON rollback'")->fetchColumn()!==0)throw new RuntimeException('Warning did not roll back the transaction.');
echo 'PASS JSON response: transaction rollback'.PHP_EOL;
