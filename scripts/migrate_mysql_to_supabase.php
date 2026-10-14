<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/migrate_admin_management.php';
$privatePath=$argv[1]??'';$schema=$argv[2]??'public';
if(!is_file($privatePath)||!preg_match('/^(public|interclasse_admin_test_[a-z0-9_]+)$/',$schema))throw new RuntimeException('Informe uma configuração privada e um esquema autorizado.');
$c=(require $privatePath)['db'];
$target=new PostgresCompatiblePDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']};sslmode=require;connect_timeout=15",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$source=db();$source->includeArchived=true;
if($source->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new RuntimeException('A origem precisa ser a cópia MySQL local.');
$target->exec('CREATE SCHEMA IF NOT EXISTS '.$schema);$target->exec('SET search_path TO '.$schema);
$existing=$target->query("SELECT table_name FROM information_schema.tables WHERE table_schema=current_schema() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
foreach($existing as $table){if(!preg_match('/^[a-z_]+$/',$table))throw new RuntimeException('Esquema de destino contém tabelas não reconhecidas.');if($target->query("SELECT COUNT(*) FROM $table")->fetchColumn()>0)throw new RuntimeException('O destino contém dados; a migração se recusa a sobrescrevê-los.');}
$target->exec(file_get_contents(__DIR__.'/../database/supabase_schema.sql'));
migrate_admin_management($target);
$tables=['classes','users','user_modalities','events','event_modalities','event_group_advancement','teams','players','matches','score_logs','occurrences','class_standings','class_modality_points','suggestions','admin_operation_previews','admin_operations'];
$sourceTables=$source->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$target->includeArchived=true;$report=[];$links=[];$copied=[];$columnTypes=[];
$source->beginTransaction();$target->beginTransaction();
try{
    foreach($tables as $table){if(!in_array($table,$sourceTables,true))continue;
        $meta=$target->prepare('SELECT column_name,data_type FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? ORDER BY ordinal_position');$meta->execute([$table]);$types=array_column($meta->fetchAll(),'data_type','column_name');
        $rows=$source->query("SELECT * FROM $table ORDER BY id FOR UPDATE")->fetchAll();$report[$table]=count($rows);$copied[$table]=$rows;$columnTypes[$table]=$types;
        foreach($rows as $row){$extra=array_diff(array_keys($row),array_keys($types));if($extra)throw new RuntimeException('Há campos sem equivalente em '.$table.': '.implode(',',$extra));
            if($table==='matches'){$links[$row['id']]=[$row['home_source_match_id'],$row['away_source_match_id']];$row['home_source_match_id']=$row['away_source_match_id']=null;}
            foreach($row as $key=>&$value)if($types[$key]==='boolean'&&$value!==null)$value=$value?'true':'false';unset($value);
            $target->prepare("INSERT INTO $table (".implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
        }
        if(isset($types['id'])&&$types['id']==='integer')$target->query("SELECT setval(pg_get_serial_sequence('$table','id'),COALESCE((SELECT MAX(id) FROM $table),1),EXISTS(SELECT 1 FROM $table))");
    }
    $update=$target->prepare('UPDATE matches SET home_source_match_id=?,away_source_match_id=? WHERE id=?');foreach($links as $id=>[$home,$away])$update->execute([$home,$away,$id]);
    foreach($report as $table=>$count)if((int)$target->query("SELECT COUNT(*) FROM $table")->fetchColumn()!==$count)throw new RuntimeException('Contagem divergente em '.$table);
    foreach($copied as $table=>$rows){$destination=$target->query("SELECT * FROM $table ORDER BY id")->fetchAll();foreach($rows as $i=>$row)foreach($row as $column=>$value)if(migration_value($value,$columnTypes[$table][$column])!==migration_value($destination[$i][$column],$columnTypes[$table][$column]))throw new RuntimeException("Conteúdo divergente em $table/$column; transação cancelada.");}
    // Direct PDO is used only by PHP; PostgREST must never expose private tables or active views.
    if($schema==='public'){$target->exec('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM anon,authenticated');$target->exec('REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM anon,authenticated');}
    $target->commit();$source->rollBack();migrate_admin_management($target);
}catch(Throwable $e){if($target->inTransaction())$target->rollBack();if($source->inTransaction())$source->rollBack();throw $e;}
echo json_encode(['schema'=>$schema,'counts'=>$report,'all_source_values_verified'=>true,'credentials_published'=>false],JSON_PRETTY_PRINT)."\n";

function migration_value(mixed $value,string $type):mixed{
    if($value===null)return null;
    if(in_array($type,['json','jsonb'],true))return migration_canonical(json_decode($value,true,512,JSON_THROW_ON_ERROR));
    if(in_array($type,['integer','bigint','smallint'],true))return (int)$value;
    if($type==='boolean')return filter_var($value,FILTER_VALIDATE_BOOLEAN);
    if(str_starts_with($type,'timestamp'))return preg_replace(['/\.0+$/','/(\.\d*?[1-9])0+$/'],['','$1'],(string)$value);
    return (string)$value;
}
function migration_canonical(mixed $value):mixed{if(!is_array($value))return $value;if(!array_is_list($value))ksort($value);foreach($value as &$v)$v=migration_canonical($v);unset($v);return $value;}
