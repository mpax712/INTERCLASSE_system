<?php
declare(strict_types=1);
require_once __DIR__ . '/GameScoreService.php';

final class AdminOperationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422) { parent::__construct($message); }
}

/** One transactional preview/apply boundary for all structural administrative writes. */
final class AdminCompetitionService
{
    public static bool $planning = false;
    private const TABLES = ['events','event_modalities','event_group_advancement','classes','users','teams','players','matches',
        'class_standings','class_modality_points','occurrences','score_logs'];
    private const STAGES = ['grupo','repescagem','preliminar','dezesseis_avos','oitavas','quartas','semifinal','final','individual'];
    private PDO $pdo;
    private array $warnings = [];
    private array $effects = [];
    private bool $safe = true;
    private int $actorId;
    /** Test-only fault injection; never populated from an HTTP request. */
    public ?Closure $beforeCommit = null;

    public function __construct(?PDO $pdo = null) { $this->pdo = $pdo ?? db(); }

    private function authorize(array $actor): void
    {
        $stmt = $this->pdo->prepare('SELECT role FROM users WHERE id=?');
        $stmt->execute([(int) ($actor['id'] ?? 0)]);
        if ($stmt->fetchColumn() !== 'admin') throw new AdminOperationException('Apenas administradores podem executar esta operação.', 403);
        if (!($this->pdo instanceof PostgresCompatiblePDO) || !$this->pdo->activeViewsEnabled) {
            throw new AdminOperationException('A migração administrativa precisa ser aplicada antes de usar estas ferramentas.', 503);
        }
        $this->actorId = (int) $actor['id'];
    }

    private function raw(callable $callback): mixed
    {
        $previous = $this->pdo->includeArchived;
        $this->pdo->includeArchived = true;
        try { return $callback(); } finally { $this->pdo->includeArchived = $previous; }
    }

    private function active(callable $callback): mixed
    {
        $previous = $this->pdo->includeArchived;
        $this->pdo->includeArchived = false;
        try { return $callback(); } finally { $this->pdo->includeArchived = $previous; }
    }

    public function snapshot(bool $lock = false): array
    {
        return $this->raw(function () use ($lock) {
            $state = [];
            if($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'){
                $queries=[];
                foreach(self::TABLES as $table){
                    if($lock)$this->pdo->query("SELECT id FROM $table ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
                    $columns=$table==='users'?'id,class_id':'*';
                    $queries[]="SELECT '$table' AS entity,COALESCE(json_agg(r ORDER BY r.id),'[]'::json)::text AS rows FROM (SELECT $columns FROM $table) r";
                }
                $jsonColumns=['event_modalities'=>['rules_json'],'matches'=>['score_data','rules_snapshot','qualification_sources','participant_snapshot'],'score_logs'=>['old_score_data','new_score_data']];
                foreach($this->pdo->query(implode(' UNION ALL ',$queries))->fetchAll() as $result){$table=$result['entity'];$state[$table]=[];
                    foreach(json_decode($result['rows'],false,512,JSON_THROW_ON_ERROR) as $object){$row=(array)$object;foreach($jsonColumns[$table]??[] as $key)if(isset($row[$key]))$row[$key]=self::json($row[$key]);$state[$table][$row['id']]=$row;}
                }
                return $state;
            }
            foreach (self::TABLES as $table) {
                // Credentials never enter previews or the administrative history.
                $columns = $table === 'users' ? 'id,class_id' : '*';
                $rows = $this->pdo->query("SELECT $columns FROM $table ORDER BY id" . ($lock ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_ASSOC);
                $state[$table] = array_column($rows, null, 'id');
            }
            return $state;
        });
    }

    private static function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
    private static function canonical(mixed $value):mixed{
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value);
        foreach($value as &$entry)$entry=self::canonical($entry);unset($entry);return $value;
    }
    private static function hash(array $state): string { return hash('sha256', self::json(self::canonical($state))); }
    private function row(string $table, int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE id=?"); $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new AdminOperationException('Registro não encontrado.', 404);
    }
    private function write(string $table, array $row): int
    {
        if (!in_array($table, self::TABLES, true)) throw new AdminOperationException('Entidade inválida.');
        $id = (int) ($row['id'] ?? 0); unset($row['id']);
        if ($id) {
            $sql = "UPDATE $table SET " . implode(',', array_map(fn($key) => "$key=?", array_keys($row))) . ' WHERE id=?';
            $values = array_values($row); $values[] = $id;
        } else {
            $sql = "INSERT INTO $table (" . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
            $values = array_values($row);
        }
        $this->pdo->prepare($sql)->execute($values);
        return $id ?: (int) $this->pdo->lastInsertId();
    }
    private static function diff(array $before, array $after): array
    {
        $diff = [];
        foreach ($before as $table => $rows) foreach (array_unique(array_merge(array_keys($rows), array_keys($after[$table]))) as $id) {
            $old = $rows[$id] ?? null; $new = $after[$table][$id] ?? null;
            if ($old !== $new) $diff[$table][$id] = ['before'=>$old, 'after'=>$new];
        }
        return $diff;
    }

    public function preview(array $command, array $actor): array
    {
        $this->authorize($actor);
        $policy = $command['policy'] ?? 'update';
        if (!in_array($policy, ['update','restart','manual'], true)) throw new AdminOperationException('Escolha como tratar as dependências.');
        $this->warnings = []; $this->effects = []; $this->safe = true;
        $previous = $this->pdo->includeArchived; $this->pdo->includeArchived = true;
        $this->pdo->beginTransaction();
        self::$planning = true;
        try {
            $before = $this->snapshot(true);
            $this->applyCommand($command);
            $this->reconcile($before, $policy);
            $this->validate();
            $this->recalculate($before);
            $after = $this->snapshot();
            $changes = self::diff($before, $after);
            $impact = $this->impact($changes);
            $this->pdo->rollBack();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        } finally { self::$planning = false; $this->pdo->includeArchived = $previous; }
        $id = bin2hex(random_bytes(24));
        $destructive = ($command['operation']??'')==='purge';
        $plan = ['id'=>$id, 'fingerprint'=>self::hash($before), 'operation'=>$command['operation'],
            'command'=>$command, 'policy'=>$policy, 'changes'=>$changes, 'impact'=>$impact,
            'warnings'=>$this->warnings, 'can_execute'=>$this->safe, 'confirmation_word'=>$destructive ? 'EXCLUIR' : null];
        $stmt = $this->pdo->prepare('INSERT INTO admin_operation_previews (id,user_id,created_at,expires_at,plan_json) VALUES (?,?,?,?,?)');
        $stmt->execute([$id,$this->actorId,date('Y-m-d H:i:s'),date('Y-m-d H:i:s',time()+600),self::json($plan)]);
        // Private fields are available only to the actor via the audit; return a concise preview.
        return ['preview_id'=>$id, 'policy'=>$policy, 'impact'=>$impact, 'warnings'=>$this->warnings,
            'can_execute'=>$this->safe, 'confirmation_word'=>$plan['confirmation_word'], 'expires_in'=>600,
            'strategies'=>['update'=>['available'=>!array_filter($this->effects,fn($e)=>$e['requires_choice']??false)], 'restart'=>['available'=>true], 'manual'=>['available'=>true]]];
    }

    public function execute(string $id, string $reason, ?string $word, array $actor): array
    {
        $this->authorize($actor);
        if (mb_strlen(trim($reason)) < 3 || mb_strlen($reason) > 500) throw new AdminOperationException('Explique o motivo da alteração (3 a 500 caracteres).');
        $previous = $this->pdo->includeArchived; $this->pdo->includeArchived = true;
        $this->pdo->beginTransaction();
        try {
            $before = $this->snapshot(true);
            $duplicate = $this->pdo->prepare('SELECT user_id FROM admin_operations WHERE id=?'); $duplicate->execute([$id]);
            $owner = $duplicate->fetchColumn();
            if ($owner !== false) {
                if ((int)$owner !== $this->actorId) throw new AdminOperationException('Operação pertence a outro administrador.',403);
                $this->pdo->commit(); return ['ok'=>true,'duplicate'=>true,'operation_id'=>$id];
            }
            $stmt = $this->pdo->prepare('SELECT * FROM admin_operation_previews WHERE id=? FOR UPDATE'); $stmt->execute([$id]);
            $preview = $stmt->fetch();
            if (!$preview || (int)$preview['user_id'] !== $this->actorId) throw new AdminOperationException('Prévia não encontrada para este administrador.',404);
            if (strtotime($preview['expires_at']) < time()) throw new AdminOperationException('A prévia expirou. Analise novamente os impactos.',409);
            $plan = json_decode($preview['plan_json'],true,512,JSON_THROW_ON_ERROR);
            if (!$plan['can_execute']) throw new AdminOperationException('Há resultados incompatíveis. Escolha reiniciar ou reorganizar manualmente.',409);
            if ($plan['confirmation_word'] !== null && $word !== $plan['confirmation_word']) throw new AdminOperationException('Digite EXCLUIR para confirmar a operação.');
            if (!hash_equals($plan['fingerprint'],self::hash($before))) throw new AdminOperationException('O campeonato mudou desde a prévia. Confira os impactos novamente.',409);
            $this->applyDiff($plan['changes']);
            $this->validate();
            foreach($plan['changes']['matches']??[] as $matchId=>$change){$old=$change['before'];$new=$change['after'];
                if(!$old||!$new||($old['home_score']===$new['home_score']&&$old['away_score']===$new['away_score']&&$old['status']===$new['status']&&$old['score_data']===$new['score_data']))continue;
                $this->write('score_logs',['match_id'=>$matchId,'user_id'=>$this->actorId,'action'=>'admin_'.$plan['operation'],'description'=>mb_substr(trim($reason),0,255),'old_home_score'=>$old['home_score'],'old_away_score'=>$old['away_score'],'new_home_score'=>$new['home_score'],'new_away_score'=>$new['away_score'],'old_status'=>$old['status'],'new_status'=>$new['status'],'old_score_data'=>$old['score_data'],'new_score_data'=>$new['score_data'],'request_id'=>substr(hash('sha256',$id.':'.$matchId),0,64)]);
            }
            if ($this->beforeCommit) ($this->beforeCommit)();
            $after = $this->snapshot();
            $stmt = $this->pdo->prepare('INSERT INTO admin_operations (id,user_id,created_at,operation,reason,changes_json,impact_json) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$id,$this->actorId,date('Y-m-d H:i:s'),$plan['operation'],trim($reason),self::json(self::diff($before,$after)),self::json($plan['impact'])]);
            $this->pdo->commit(); public_cache_forget_all();
            return ['ok'=>true,'duplicate'=>false,'operation_id'=>$id,'impact'=>$plan['impact']];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error;
        } finally { $this->pdo->includeArchived = $previous; }
    }

    private function applyDiff(array $changes): void
    {
        // Recalculated rows replace existing natural keys; remove those children
        // inside the same transaction before inserting their proposed replacements.
        foreach(['class_modality_points','event_group_advancement'] as $table)foreach($changes[$table]??[] as $id=>$change)if($change['after']===null)$this->pdo->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
        // Insert/update parents first; match self-links are installed in a second pass.
        foreach (self::TABLES as $table) foreach ($changes[$table] ?? [] as $id=>$change) {
            $row = $change['after']; if ($row === null) continue;
            if ($table === 'matches') { $row['home_source_match_id']=null; $row['away_source_match_id']=null; }
            if ($change['before'] === null) {
                $cols = array_keys($row);
                $sql = "INSERT INTO $table (".implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')';
                $this->pdo->prepare($sql)->execute(array_values($row));
            } else $this->write($table,$row);
        }
        foreach ($changes['matches'] ?? [] as $id=>$change) if ($change['after'] !== null) {
            $r = $change['after']; $this->pdo->prepare('UPDATE matches SET home_source_match_id=?,away_source_match_id=? WHERE id=?')->execute([$r['home_source_match_id'],$r['away_source_match_id'],$id]);
        }
        foreach (array_reverse(self::TABLES) as $table) foreach ($changes[$table] ?? [] as $id=>$change) {
            if ($change['after'] === null) $this->pdo->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
        }
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            foreach (array_keys($changes) as $table) {
                if (!in_array($table,self::TABLES,true)) continue;
                $this->pdo->query("SELECT setval(pg_get_serial_sequence('$table','id'), COALESCE((SELECT MAX(id) FROM $table),1), true)");
            }
        }
    }

    private function applyCommand(array $command): void
    {
        $operation = $command['operation'] ?? ''; $entity = $command['entity'] ?? ''; $id = (int)($command['id'] ?? 0);
        if ($operation === 'save_match') { $this->saveMatch($command['data'] ?? [],$id); return; }
        if ($operation === 'regenerate') { $this->regenerate($command); return; }
        if ($operation === 'save_advancement') {
            $event=(int)($command['event_id']??0);$mod=(string)($command['modality']??'');$gender=is_mixed_modality($mod)?'masculino':($command['gender']??'masculino');$data=$command['data']??[];
            $this->row('events',$event);if(!modality_definition($mod)||!in_array($gender,['masculino','feminino'],true))throw new AdminOperationException('Modalidade ou gênero inválido.');
            $mode=$data['advancement_mode']??'global';if(!in_array($mode,['global','per_group'],true))throw new AdminOperationException('Critério de classificação inválido.');
            $this->pdo->prepare('UPDATE events SET advancement_mode=?,teams_advancing_per_group=?,best_thirds_count=? WHERE id=?')->execute([$mode,$this->integer($data['teams_advancing_per_group']??2,0,32),$this->integer($data['best_thirds_count']??0,0,32),$event]);
            $this->pdo->prepare('DELETE FROM event_group_advancement WHERE event_id=? AND modality=? AND gender=?')->execute([$event,$mod,$gender]);
            foreach($data['groups']??[] as $group=>$count)$this->write('event_group_advancement',['event_id'=>$event,'modality'=>$mod,'gender'=>$gender,'group_name'=>(string)$group,'teams_advancing'=>$this->integer($count,0,32)]);
            $this->warnings[]='Os critérios globais pertencem ao evento; as quantidades por grupo são desta modalidade e gênero. Vagas já existentes mantêm suas origens. Confira a regeneração para substituir os classificados.';return;
        }
        if ($operation === 'restore_operation') {
            $stmt=$this->pdo->prepare('SELECT changes_json FROM admin_operations WHERE id=?'); $stmt->execute([$command['operation_id'] ?? '']);
            $json=$stmt->fetchColumn(); if (!$json) throw new AdminOperationException('Histórico não encontrado.',404);
            $changes=json_decode($json,true,512,JSON_THROW_ON_ERROR); $inverse=[]; $current=$this->snapshot();
            foreach ($changes as $table=>$rows) foreach ($rows as $key=>$change) {
                $actual=$current[$table][$key]??null;
                if (self::canonical($actual)!==self::canonical($change['after']) && empty($command['overwrite'])) throw new AdminOperationException('Há alterações posteriores. Selecione a restauração com substituição e confira a nova prévia.',409);
                $inverse[$table][$key]=['before'=>$actual,'after'=>$change['before']];
            }
            $this->applyDiff($inverse); $this->warnings[]='A restauração recupera o conjunto de registros desta operação; alterações posteriores afetadas aparecem na prévia.'; return;
        }
        if ($operation === 'save_settings') {
            $eventId=(int)($command['event_id']??0); $modality=(string)($command['modality']??'');
            $stmt=$this->pdo->prepare('SELECT * FROM event_modalities WHERE event_id=? AND modality=?'); $stmt->execute([$eventId,$modality]); $row=$stmt->fetch();
            if (!$row) {
                $this->row('events',$eventId); $definition=modality_definition($modality);
                if(!$definition)throw new AdminOperationException('Modalidade inválida.');
                $newId=$this->write('event_modalities',['event_id'=>$eventId,'modality'=>$modality,'score_type'=>$definition['score_type'],
                    'bracket_type'=>$definition['default_format'],'max_players_per_team'=>game_is_new_modality($modality)?2:$definition['player_limit'],
                    'rules_json'=>isset($definition['rules'])?self::json($definition['rules']):null]);
                $row=$this->row('event_modalities',$newId);
            }
            $data=$command['data']??[];
            if (isset($data['bracket_type'])) { if (!in_array($data['bracket_type'],modality_definition($modality)['formats']??[],true)) throw new AdminOperationException('Formato inválido para esta modalidade.'); $row['bracket_type']=$data['bracket_type']; }
            if (isset($data['max_players_per_team'])) $row['max_players_per_team']=$this->integer($data['max_players_per_team'],1,999);
            foreach(['set_best_of','set_points','quarters_count'] as $key)if(isset($data[$key])&&$data[$key]!=='')$row[$key]=$this->integer($data[$key],1,99);
            if (isset($data['rules'])) $row['rules_json']=self::json(validate_game_rules($modality,$data['rules']));
            $row['structure_revision']++; $this->write('event_modalities',$row); return;
        }
        if (!in_array($entity,['classes','teams','players','matches','events'],true)) throw new AdminOperationException('Entidade inválida.');
        if ($operation === 'save_entity') { $this->saveEntity($entity,$id,$command['data']??[]); return; }
        if (!in_array($operation,['archive','restore','purge','restart','cancel'],true) || !$id) throw new AdminOperationException('Operação inválida.');
        $row=$this->row($entity,$id);
        if ($entity==='matches' && in_array($operation,['restart','cancel'],true)) {
            $row['status']=$operation==='cancel'?'cancelada':'agendada'; $row['home_score']=0; $row['away_score']=0;
            $row['winner_team_id']=null; $row['winner_player_id']=null; $row['score_data']=self::json(default_score_data($row['modality']));
            $row['review_state']='ready'; $row['version']++; $this->write($entity,$row); return;
        }
        if ($operation==='restore') {
            if($entity==='players'&&$this->row('teams',(int)$row['team_id'])['archived_at'])throw new AdminOperationException('Restaure primeiro a inscrição da equipe.');
            if($entity==='teams'){
                if($this->row('events',(int)$row['event_id'])['archived_at'])throw new AdminOperationException('Restaure primeiro o evento.');
                $class=$this->pdo->prepare('SELECT archived_at FROM classes WHERE name=?');$class->execute([$row['class_name']]);if($class->fetchColumn())throw new AdminOperationException('Restaure primeiro a turma global.');
            }
            $archiveTime=$row['archived_at'];$archiveActor=$row['archived_by'];
            $row['archived_at']=null; $row['archived_by']=null; if ($entity==='events') $row['archived']=0;
            $this->write($entity,$row);
            if(in_array($entity,['classes','events','teams'],true)&&$archiveTime!==null){$state=$this->snapshot();$teamIds=[];foreach($state['teams'] as $team)if(($entity==='teams'&&$team['id']==$id)||($entity==='classes'&&$team['class_name']===$row['name'])||($entity==='events'&&$team['event_id']==$id)){if($team['archived_at']===$archiveTime&&$team['archived_by']===$archiveActor){$team['archived_at']=null;$team['archived_by']=null;$this->write('teams',$team);}$teamIds[]=$team['id'];}foreach($state['players'] as $player)if(in_array($player['team_id'],$teamIds,true)&&$player['archived_at']===$archiveTime&&$player['archived_by']===$archiveActor){$player['archived_at']=null;$player['archived_by']=null;$this->write('players',$player);}}
            return;
        }
        if ($operation==='archive' || $operation==='purge') {
            $this->archive($entity,$id);
            if ($operation==='purge') {
                $state=$this->snapshot(); $teams=[]; $players=[];
                if ($entity==='classes') foreach($state['teams'] as $team) if($team['class_name']===$row['name']) $teams[]=$team['id'];
                if ($entity==='events') foreach($state['teams'] as $team) if($team['event_id']==$id) $teams[]=$team['id'];
                if ($entity==='teams') $teams[]=$id;
                foreach($state['players'] as $player) if(in_array($player['team_id'],$teams,true)) $players[]=$player['id'];
                if($entity==='players') $players[]=$id;
                foreach($players as $pid) $this->pdo->prepare('DELETE FROM players WHERE id=?')->execute([$pid]);
                foreach($teams as $tid) $this->pdo->prepare('DELETE FROM teams WHERE id=?')->execute([$tid]);
                $this->pdo->prepare("DELETE FROM $entity WHERE id=?")->execute([$id]);
                $this->warnings[]='Exclusão definitiva: dependências e histórico removidos do banco operacional serão conservados no registro administrativo desta operação.';
            }
        }
    }

    private function archive(string $entity,int $id): void
    {
        $row=$this->row($entity,$id); if($row['archived_at'])return;
        $row['archived_at']=date('Y-m-d H:i:s.u'); $row['archived_by']=$this->actorId;
        if($entity==='events') $row['archived']=1;
        if($entity==='matches') $row['version']++;
        $this->write($entity,$row);
        $state=$this->snapshot();
        if(in_array($entity,['classes','events'],true)) foreach($state['teams'] as $team) {
            if(($entity==='classes'&&$team['class_name']===$row['name'])||($entity==='events'&&$team['event_id']==$id)) $this->archive('teams',(int)$team['id']);
        }
        if($entity==='teams') foreach($state['players'] as $player) if($player['team_id']==$id && !$player['archived_at']) $this->archive('players',(int)$player['id']);
        $this->warnings[]='O registro será arquivado. O histórico permanece recuperável; partidas futuras afetadas serão analisadas.';
    }

    private function integer(mixed $value,int $min=0,int $max=999999): int
    {
        $n=filter_var($value,FILTER_VALIDATE_INT); if($n===false||$n<$min||$n>$max) throw new AdminOperationException('Informe um número inteiro válido.'); return $n;
    }

    private function saveEntity(string $table,int $id,array $data): void
    {
        $allowed=['classes'=>['name'],'teams'=>['event_id','modality','name','class_name','gender','group_name','representative_id','representative_name'],
            'players'=>['team_id','name','email','birth_date','jersey_number','group_name'],
            'events'=>['name','description','start_date','end_date','place','status','group_count','best_thirds_count','teams_advancing_per_group','advancement_mode']];
        if(!isset($allowed[$table])) throw new AdminOperationException('Use o editor de partidas.');
        $old=$id?$this->row($table,$id):[]; $row=['id'=>$id]+array_intersect_key($data,array_flip($allowed[$table]));
        $maxName=$table==='events'?160:120;
        if(isset($row['name']) && (mb_strlen(trim($row['name']))<2||mb_strlen($row['name'])>$maxName)) throw new AdminOperationException('Informe um nome de 2 a '.$maxName.' caracteres.');
        if(!$id && !isset($row['name'])) throw new AdminOperationException('Informe o nome.');
        if($table==='events'&&!$id) $row['created_by']=$this->actorId;
        if($table==='events'&&isset($row['status'])&&!in_array($row['status'],['rascunho','inscricoes','em_andamento','encerrado'],true)) throw new AdminOperationException('Status de evento inválido.');
        if($table==='teams') {
            $candidate=array_replace($old,$row); $event=(int)($candidate['event_id']??0); $mod=$candidate['modality']??'';
            $stmt=$this->pdo->prepare('SELECT id FROM event_modalities WHERE event_id=? AND modality=?'); $stmt->execute([$event,$mod]); if(!$stmt->fetch()) throw new AdminOperationException('Selecione uma modalidade cadastrada no evento.');
            if(isset($candidate['class_name'])&&$candidate['class_name']!=='') {
                $stmt=$this->pdo->prepare('SELECT id FROM classes WHERE name=? AND archived_at IS NULL');$stmt->execute([$candidate['class_name']]);if(!$stmt->fetch()) throw new AdminOperationException('Turma não cadastrada ou arquivada.');
            }
            $candidate['gender']=is_mixed_modality($mod)?'masculino':($candidate['gender']??'masculino');
            if(!in_array($candidate['gender'],['masculino','feminino'],true)) throw new AdminOperationException('Gênero inválido.');
            $row['gender']=$candidate['gender'];
            if(!$id) { $row['event_id']=$event; $row['modality']=$mod; $row['group_name']=$candidate['group_name']??''; }
            if($id && ($old['event_id']!=$event||$old['modality']!==$mod)) throw new AdminOperationException('Para outro evento/modalidade, crie uma nova inscrição; a inscrição histórica mantém sua identidade.');
            $stmt=$this->pdo->prepare('SELECT id FROM teams WHERE event_id=? AND modality=? AND gender=? AND class_name=? AND archived_at IS NULL AND id<>?');$stmt->execute([$event,$mod,$candidate['gender'],$candidate['class_name']??'',$id]);if($stmt->fetch()) throw new AdminOperationException('Esta turma já está inscrita nesta modalidade e gênero.');
        }
        if($table==='players') {
            $team=$this->row('teams',(int)($row['team_id']??$old['team_id']??0)); if($team['archived_at']) throw new AdminOperationException('Restaure a equipe antes de inscrever jogadores.');
            if($id&&isset($row['team_id'])&&$row['team_id']!=$old['team_id']) {
                $origin=$this->row('teams',(int)$old['team_id']);
                if($origin['event_id']!=$team['event_id']||$origin['modality']!==$team['modality']||$origin['gender']!==$team['gender']) throw new AdminOperationException('Transferências mantêm evento, modalidade e gênero. Para outro campeonato, crie uma nova inscrição.');
                $this->warnings[]='A transferência preserva o histórico anterior; confrontos futuros e a classificação serão analisados.';
            }
            $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM players WHERE team_id=? AND archived_at IS NULL');$stmt->execute([$team['id']]);
            $adding=!$id || (isset($row['team_id'])&&$row['team_id']!=$old['team_id']);
            if((int)$stmt->fetchColumn()+($adding?1:0)>player_limit_for_team($team)) $this->warnings[]='A equipe ficará acima do limite de jogadores. A exceção administrativa será registrada com sua justificativa.';
            if(!empty($row['email'])&&!filter_var($row['email'],FILTER_VALIDATE_EMAIL)) throw new AdminOperationException('E-mail inválido.');
            foreach(['birth_date','email','jersey_number'] as $key) if(isset($row[$key])&&$row[$key]==='') $row[$key]=null;
        }
        if($table==='classes'&&$id&&isset($row['name'])&&$row['name']!==$old['name']) {
            $this->pdo->prepare('UPDATE teams SET class_name=? WHERE class_name=?')->execute([$row['name'],$old['name']]);
            $this->pdo->prepare('UPDATE class_standings SET class_name=? WHERE class_name=?')->execute([$row['name'],$old['name']]);
        }
        if($table==='events'&&isset($row['end_date'])&&$row['end_date']==='')$row['end_date']=null;
        $newId=$this->write($table,$row);
        if($table==='events')foreach($data['modalities']??[] as $modality){$definition=modality_definition($modality);if(!$definition)throw new AdminOperationException('Modalidade inválida.');$stmt=$this->pdo->prepare('SELECT id FROM event_modalities WHERE event_id=? AND modality=?');$stmt->execute([$newId,$modality]);if(!$stmt->fetch())$this->write('event_modalities',['event_id'=>$newId,'modality'=>$modality,'score_type'=>$definition['score_type'],'bracket_type'=>$definition['default_format'],'max_players_per_team'=>game_is_new_modality($modality)?2:$definition['player_limit'],'rules_json'=>isset($definition['rules'])?self::json($definition['rules']):null]);}
        if(in_array($table,['teams','players'],true)) $this->warnings[]='A inscrição pode ser mantida sem novos jogos. Use “Gerar faltantes”, reorganização de fase ou regeneração para incluí-la na tabela.';
    }

    private function saveMatch(array $data,int $id): void
    {
        $old=$id?$this->row('matches',$id):[];
        $allowed=['event_id','modality','gender','stage','group_name','home_team_id','away_team_id','home_player_id','away_player_id',
            'home_source_match_id','away_source_match_id','qualification_sources','status','starts_at','place','sort_order','home_score','away_score','score_data','rules_snapshot'];
        $row=array_replace($old,array_intersect_key($data,array_flip($allowed)));
        $eventId=(int)($row['event_id']??0); $modality=(string)($row['modality']??'');
        if(!modality_definition($modality)) throw new AdminOperationException('Modalidade inválida.');
        $stmt=$this->pdo->prepare('SELECT * FROM event_modalities WHERE event_id=? AND modality=?'); $stmt->execute([$eventId,$modality]); $settings=$stmt->fetch();
        if(!$settings) throw new AdminOperationException('Modalidade não pertence ao evento.');
        if($id&&($eventId!=$old['event_id']||$modality!==$old['modality'])) throw new AdminOperationException('A partida mantém seu evento e modalidade; crie outra inscrição para um campeonato diferente.');
        if(!in_array($row['stage']??'',self::STAGES,true)) throw new AdminOperationException('Fase inválida.');
        $row['gender']=is_mixed_modality($modality)?'masculino':($row['gender']??'masculino');
        if(!in_array($row['gender'],['masculino','feminino'],true)) throw new AdminOperationException('Gênero inválido.');
        foreach(['home_team_id','away_team_id','home_player_id','away_player_id','home_source_match_id','away_source_match_id'] as $key) $row[$key]=empty($row[$key])?null:$this->integer($row[$key],1);
        $origins=is_array($row['qualification_sources']??null)?$row['qualification_sources']:(json_decode($row['qualification_sources']??'{}',true)??[]);
        foreach($origins as $side=>$origin){if(!in_array($side,['home','away'],true)||!is_array($origin)||!in_array($origin['type']??'', ['direct','group_rank'],true))throw new AdminOperationException('Origem de classificação inválida.');if($origin['type']==='group_rank'){$this->integer($origin['position']??0,1,999);if(empty($origin['group']))throw new AdminOperationException('Informe o grupo da vaga classificada.');}if($origin['type']==='direct'&&$row[$side.'_source_match_id'])throw new AdminOperationException('Uma vaga direta não pode manter origem automática.');}
        foreach(['home','away'] as $side) {
            $origins=is_array($row['qualification_sources']??null)?$row['qualification_sources']:(json_decode($row['qualification_sources']??'{}',true)??[]);
            if(($origins[$side]['type']??'')==='group_rank') {
                $origin=$origins[$side];$position=$this->integer($origin['position']??1,1,999);
                $ranks=$this->active(fn()=>game_is_new_modality($modality)?game_group_standings($eventId,$modality,$origin['group']??null):calculate_standings($eventId,$modality,$origin['group']??null,$row['gender']));
                $qualified=$ranks[$position-1]??[];$row[$side.'_player_id']=$qualified['player_id']??null;$row[$side.'_team_id']=$qualified['team_id']??null;
                if($row[$side.'_player_id'])$row[$side.'_team_id']=$this->row('players',(int)$row[$side.'_player_id'])['team_id'];
            }
            if($row[$side.'_source_match_id']) {
                $parent=$this->row('matches',$row[$side.'_source_match_id']);
                if($parent['event_id']!=$eventId||$parent['modality']!==$modality||$parent['gender']!==$row['gender'])throw new AdminOperationException('Origem de outra modalidade, evento ou gênero.');
                $row[$side.'_team_id']=$parent['archived_at']||$parent['review_state']!=='ready'?null:$parent['winner_team_id'];
                $row[$side.'_player_id']=$parent['archived_at']||$parent['review_state']!=='ready'?null:$parent['winner_player_id'];
            }
            if($row[$side.'_player_id']) {
                $player=$this->row('players',$row[$side.'_player_id']);$team=$this->row('teams',(int)$player['team_id']);
                if($player['archived_at']||$team['archived_at']) throw new AdminOperationException('Escolha um participante ativo.');
                if(!($id&&in_array($old['status'],['encerrada','wo'],true)&&in_array($row['status']??$old['status'],['encerrada','wo'],true)&&($old[$side.'_player_id']??null)===$row[$side.'_player_id']))$row[$side.'_team_id']=$team['id'];
            }
            if($row[$side.'_team_id']) {
                $team=$this->row('teams',$row[$side.'_team_id']);
                if($team['archived_at']||$team['event_id']!=$eventId||$team['modality']!==$modality||(!is_mixed_modality($modality)&&$team['gender']!==$row['gender'])) throw new AdminOperationException('Participante de outro evento, modalidade ou gênero.');
            }
        }
        foreach(['score_data','rules_snapshot','qualification_sources'] as $key) {
            $v=$row[$key]??null; if(is_array($v)) $row[$key]=self::json($v); elseif($v!==null&&$v!==''&& !is_array(json_decode($v,true))) throw new AdminOperationException('Detalhes do placar ou das vagas inválidos.');
        }
        if(!empty($row['rules_snapshot'])&&game_is_new_modality($modality))$row['rules_snapshot']=self::json(validate_game_rules($modality,json_decode($row['rules_snapshot'],true)));
        $row['score_data']=$row['score_data']??self::json(default_score_data($modality));
        if(game_is_new_modality($modality)&&empty($row['rules_snapshot'])) $row['rules_snapshot']=self::json(game_event_rules($eventId,$modality));
        $row['status']=$row['status']??'agendada';
        if(!in_array($row['status'],['agendada','em_andamento','aguardando_resultado','encerrada','wo','cancelada'],true)) throw new AdminOperationException('Estado de partida inválido.');
        $row['home_score']=$this->integer($row['home_score']??0,0,9999);$row['away_score']=$this->integer($row['away_score']??0,0,9999);
        $row['winner_team_id']=null; $row['winner_player_id']=null;
        $score=json_decode($row['score_data'],true)??[]; $collective=!empty($score['collective_match']);
        $individual=is_individual_modality($modality);
        if($individual && !game_is_new_modality($modality)) {
            $score['bracket_home_player_id']=$row['home_player_id'];$score['bracket_away_player_id']=$row['away_player_id'];
        }
        if(!$collective && $row['status']!=='cancelada') {
            foreach(['home','away'] as $side) if(!$row[$side.'_team_id']&& !$row[$side.'_source_match_id'] && empty((json_decode($row['qualification_sources']??'{}',true)??[])[$side])) throw new AdminOperationException('Defina os participantes ou a origem das vagas.');
            if($individual && !$row['home_player_id'] && !$row['home_source_match_id'] && empty((json_decode($row['qualification_sources']??'{}',true)??[])['home'])) throw new AdminOperationException('Selecione os jogadores da partida individual.');
        }
        if(in_array($row['status'],['encerrada','wo'],true)) {
            $winnerSide=null;
            if($collective) {
                if(!in_array($modality,['quiz','cubo_magico'],true)) throw new AdminOperationException('Formato coletivo inválido.');
                $score=$this->validateCollective($score,$eventId,$modality);
                if($modality==='quiz') { $values=$score['scores']; arsort($values); $keys=array_keys($values); if(count($keys)>1&&$values[$keys[0]]!==$values[$keys[1]]) $row['winner_team_id']=(int)$keys[0]; }
            } elseif($row['status']==='wo') {
                $winnerSide=$data['winner_side']??($row['home_score']>$row['away_score']?'home':'away');
                if(!in_array($winnerSide,['home','away'],true)) throw new AdminOperationException('Selecione o vencedor do W.O.');
                $goals=$modality==='fifa'?game_match_rules($row)['wo_goals']:($modality==='clash_royale'?intdiv(game_match_rules($row)['best_of'],2)+1:3);
                $row['home_score']=$winnerSide==='home'?$goals:0;$row['away_score']=$winnerSide==='away'?$goals:0;$score['wo']=true;
            } elseif($modality==='clash_royale') {
                $wins=game_score_battles($score['battles']??[],game_match_rules($row)['best_of']);
                if(max($wins)!==intdiv(game_match_rules($row)['best_of'],2)+1) throw new AdminOperationException('As batalhas precisam definir o vencedor da série.');
                $row['home_score']=$wins['home'];$row['away_score']=$wins['away'];$winnerSide=$wins['home']>$wins['away']?'home':'away';
            } elseif($modality==='cubo_magico') {
                $score=$this->validateCollective($score,$eventId,$modality);
                $ha=$score['player_averages'][$row['home_player_id']]??null;$aa=$score['player_averages'][$row['away_player_id']]??null;
                if($ha===null||$aa===null||$ha===$aa) throw new AdminOperationException('Registre as tentativas dos dois participantes e resolva o empate.');
                $winnerSide=$ha<$aa?'home':'away';$row['home_score']=$winnerSide==='home'?1:0;$row['away_score']=$winnerSide==='away'?1:0;
            } else {
                if($modality==='volei') {
                    $home=0;$away=0;foreach($score['sets']??[] as $set){$h=$this->integer($set['home']??0);$a=$this->integer($set['away']??0);if($h===$a)throw new AdminOperationException('Cada set finalizado precisa ter vencedor.');$h>$a?$home++:$away++;}
                    $matchRules=json_decode($row['rules_snapshot']??'{}',true)??[];$target=intdiv((int)($matchRules['set_best_of']??($settings['set_best_of']?:3)),2)+1;if(max($home,$away)!==$target||min($home,$away)>=$target)throw new AdminOperationException('Os sets não correspondem ao formato da partida.');$row['home_score']=$home;$row['away_score']=$away;$score['home_sets']=$home;$score['away_sets']=$away;
                }
                $winnerSide=$row['home_score']===$row['away_score']?null:($row['home_score']>$row['away_score']?'home':'away');
                if(!$winnerSide&&$modality==='fifa'&&$row['stage']!=='grupo') {
                    $h=$this->integer($score['penalties']['home']??-1,0,99);$a=$this->integer($score['penalties']['away']??-1,0,99);if($h===$a)throw new AdminOperationException('Os pênaltis precisam definir um vencedor.');$winnerSide=$h>$a?'home':'away';$score['decided_by']='penalties';
                }
                if(!$winnerSide&&$row['stage']!=='grupo') throw new AdminOperationException('Resolva o empate antes de concluir a eliminatória.');
                if($modality==='fifa'&&$winnerSide&&empty($score['penalties']))$score['decided_by']='regular';
            }
            if($winnerSide) {
                if(!$row[$winnerSide.'_team_id']||($individual&&!$row[$winnerSide.'_player_id']))throw new AdminOperationException('O vencedor precisa ser um participante válido.');
                $row['winner_team_id']=$row[$winnerSide.'_team_id'];$row['winner_player_id']=$individual?$row[$winnerSide.'_player_id']:null;
            }
            if(!$collective && (!$row['home_team_id']||!$row['away_team_id']||($individual&&(!$row['home_player_id']||!$row['away_player_id']))))throw new AdminOperationException('Uma partida disputada precisa ter os dois participantes.');
        }
        $row['score_data']=self::json($score);$row['review_state']='ready';$row['version']=(int)($old['version']??0)+1;
        $row['archived_at']=$old['archived_at']??null;$row['archived_by']=$old['archived_by']??null;
        $row['starts_at']=empty($row['starts_at'])?null:str_replace('T',' ',$row['starts_at']);
        if($row['starts_at']!==null&&!strtotime($row['starts_at']))throw new AdminOperationException('Data e horário inválidos.');
        $row['sort_order']=$this->integer($row['sort_order']??0);
        $snapshot=[];foreach(['home','away'] as $side){$team=$row[$side.'_team_id']?$this->row('teams',$row[$side.'_team_id']):[];$player=$row[$side.'_player_id']?$this->row('players',$row[$side.'_player_id']):[];$snapshot[$side]=['player_id'=>$row[$side.'_player_id'],'team_id'=>$row[$side.'_team_id'],'name'=>$player['name']??$team['name']??null,'class_name'=>$team['class_name']??null];}
        $row['participant_snapshot']=self::json($snapshot);$row['id']=$id;$this->write('matches',$row);
    }

    private function validateCollective(array $score,int $event,string $mod): array
    {
        $state=$this->snapshot();$teams=array_filter($state['teams'],fn($t)=>$t['event_id']==$event&&$t['modality']===$mod&&!$t['archived_at']);
        if($mod==='quiz') { foreach($score['scores']??[] as $id=>$value){if(!isset($teams[$id]))throw new AdminOperationException('Equipe inválida no resultado coletivo.');$score['scores'][$id]=$this->integer($value,0,99999);} if(empty($score['scores']))throw new AdminOperationException('Informe a pontuação das equipes.'); }
        else { $averages=[];foreach($score['player_attempts']??[] as $id=>$attempts){$player=$state['players'][$id]??null;if(!$player||$player['archived_at']||!isset($teams[$player['team_id']]))throw new AdminOperationException('Jogador inválido nas tentativas.');if(!is_array($attempts)||!count($attempts))throw new AdminOperationException('Informe as tentativas.');$valid=[];foreach($attempts as $time){if(!is_numeric($time)||(float)$time<0||!is_finite((float)$time))throw new AdminOperationException('Tempo inválido.');$valid[]=round((float)$time,2);} $score['player_attempts'][$id]=$valid;$averages[$id]=round(array_sum($valid)/count($valid),2);}if(count($averages)<2)throw new AdminOperationException('Registre tentativas de pelo menos dois participantes.');$score['player_averages']=$averages; }
        return $score;
    }

    private function reconcile(array $before,string $policy): void
    {
        $after=$this->snapshot();$changedGroups=[];$changedScopes=[];
        foreach(self::diff($before,$after) as $table=>$changes)foreach($changes as $change){
            foreach(['before','after'] as $side){$r=$change[$side];if(!$r)continue;
                if($table==='players')$r=$after['teams'][$r['team_id']]??$before['teams'][$r['team_id']]??[];
                if(in_array($table,['matches','teams','players','event_group_advancement','event_modalities'],true)&&isset($r['event_id'],$r['modality']))foreach(isset($r['gender'])?[$r['gender']]:['masculino','feminino'] as $g)$changedScopes[$r['event_id'].':'.$r['modality'].':'.$g]=true;
            }
            if($table==='matches'&&$change['before']===null&&$change['after']['stage']==='grupo'){$r=$change['after'];$changedGroups[$r['event_id'].':'.$r['modality'].':'.$r['gender']]=true;}
        }
        foreach($before['matches'] as $id=>$old){$new=$after['matches'][$id]??null;
            if(!$new||$old['winner_team_id']!==$new['winner_team_id']||$old['winner_player_id']!==$new['winner_player_id']||$old['archived_at']!==$new['archived_at']||$old['home_score']!==$new['home_score']||$old['away_score']!==$new['away_score']){
                $key=$old['event_id'].':'.$old['modality'].':'.$old['gender'];$changedScopes[$key]=true;if($old['stage']==='grupo')$changedGroups[$key]=true;
            }
        }
        foreach($after['matches'] as $id=>$row) {
            if($row['archived_at'])continue;$old=$before['matches'][$id]??$row;
            // Explicit links are retained even when a parent is archived or purged.
            foreach(['home','away'] as $side) {
                $key=$side.'_source_match_id';
                $scope=$row['event_id'].':'.$row['modality'].':'.$row['gender'];
                if(isset($changedScopes[$scope]) && !$row[$key] && !$row['qualification_sources'] && !isset($changedGroups[$scope])) {
                    $candidate=[];foreach($before['matches'] as $parent)if(isset($after['matches'][$parent['id']])&&$parent['event_id']==$row['event_id']&&$parent['modality']===$row['modality']&&$parent['gender']===$row['gender']&&knockout_next_stage($parent['stage'])===$row['stage']&&$parent['winner_team_id']!==null&&$parent['winner_team_id']==$old[$side.'_team_id']&&(!is_individual_modality($row['modality'])||$parent['winner_player_id']===$old[$side.'_player_id']))$candidate[]=$parent['id'];
                    if(count($candidate)===1)$row[$key]=$candidate[0];
                }
            }
            if($row!==$after['matches'][$id])$this->write('matches',$row);
        }
        // Acyclic links allow repeated propagation until every descendant is reconciled.
        for($pass=0;$pass<=count($after['matches']);$pass++) {
            $state=$this->snapshot();$dirty=false;
            foreach($state['matches'] as $id=>$row) {
                if($row['archived_at'])continue;$original=$row;$incompatible=false;$ambiguous=false;$ineligibleOrigin=false;
                if(!isset($changedScopes[$row['event_id'].':'.$row['modality'].':'.$row['gender']]))continue;
                $sources=json_decode($row['qualification_sources']??'{}',true)??[];
                foreach(['home','away'] as $side) {
                    $sourceId=$row[$side.'_source_match_id'];$expectedTeam=null;$expectedPlayer=null;$hasOrigin=false;
                    if($sourceId) {
                        $hasOrigin=true;$parent=$state['matches'][$sourceId]??null;
                        if($parent&&!$parent['archived_at']&&$parent['review_state']==='ready'){$expectedTeam=$parent['winner_team_id'];$expectedPlayer=$parent['winner_player_id'];}
                    } elseif(($sources[$side]['type']??'')==='group_rank') {
                        $hasOrigin=true;$origin=$sources[$side];$ranks=$this->active(fn()=>game_is_new_modality($row['modality'])?game_group_standings((int)$row['event_id'],$row['modality'],$origin['group']??null):calculate_standings((int)$row['event_id'],$row['modality'],$origin['group']??null,$row['gender']));$qualified=$ranks[max(0,(int)($origin['position']??1)-1)]??null;$expectedPlayer=$qualified['player_id']??null;$expectedTeam=$qualified['team_id']??($expectedPlayer?($state['players'][$expectedPlayer]['team_id']??null):null);
                    }
                    if($hasOrigin && $expectedPlayer && !in_array($row['status'],['encerrada','wo'],true)) {
                        $qualifiedPlayer=$state['players'][$expectedPlayer]??null;
                        $qualifiedTeam=$state['teams'][$expectedTeam]??null;
                        if(!$qualifiedPlayer||$qualifiedPlayer['archived_at']||!$qualifiedTeam||$qualifiedTeam['archived_at']||$qualifiedPlayer['team_id']!=$expectedTeam){$expectedTeam=null;$expectedPlayer=null;$ineligibleOrigin=true;}
                    }
                    $team=$state['teams'][$row[$side.'_team_id']]??null;$player=$state['players'][$row[$side.'_player_id']]??null;
                    $played=in_array($row['status'],['encerrada','wo'],true);
                    if($team&&($team['event_id']!=$row['event_id']||$team['modality']!==$row['modality']||(!is_mixed_modality($row['modality'])&&$team['gender']!==$row['gender']))) {$hasOrigin=true;$expectedTeam=null;$expectedPlayer=null;}
                    // Archiving/transferring an enrolment does not rewrite a valid past match.
                    $oldSide=$before['matches'][$id][$side.'_team_id']??null;
                    $oldSource=$before['matches'][$id][$side.'_source_match_id']??null;$oldPlayer=$before['matches'][$id][$side.'_player_id']??null;
                    if(($oldSource&&!isset($state['matches'][$oldSource])&&!$sourceId&&empty($sources[$side]))||($oldPlayer&&!isset($state['players'][$oldPlayer]))){$hasOrigin=true;$expectedTeam=null;$expectedPlayer=null;$incompatible=true;$ambiguous=true;}
                    if(($oldSide!==null&&$row[$side.'_team_id']===null) || (!$played && (($team&&$team['archived_at'])||($player&&($player['archived_at']||$player['team_id']!=$row[$side.'_team_id']))))) {$hasOrigin=true;$expectedTeam=null;$expectedPlayer=null;if($oldSide!==null&&$row[$side.'_team_id']===null&&$played){$incompatible=true;$ambiguous=true;}}
                    if($hasOrigin && ($row[$side.'_team_id']!=$expectedTeam||$row[$side.'_player_id']!=$expectedPlayer)) {
                        $incompatible=true;$row[$side.'_team_id']=$expectedTeam;$row[$side.'_player_id']=$expectedPlayer;
                    }
                }
                $scope=$row['event_id'].':'.$row['modality'].':'.$row['gender'];
                if(isset($changedGroups[$scope])&&$row['review_state']==='ready'&&$row['stage']!=='grupo'&&!$row['home_source_match_id']&&!$row['away_source_match_id']&&!$row['qualification_sources']){$ambiguous=true;$incompatible=true;$this->warnings[]='Há vagas antigas sem origem inequívoca. Os confrontos não podem preservar resultados incompatíveis; revise as origens.';}
                if($incompatible) {
                    $hasResult=in_array($original['status'],['em_andamento','aguardando_resultado','encerrada','wo'],true);
                    if($policy==='update'&&($hasResult||$ambiguous))$this->safe=false;
                    $row['home_score']=0;$row['away_score']=0;$row['winner_team_id']=null;$row['winner_player_id']=null;
                    $row['score_data']=self::json(default_score_data($row['modality']));$row['status']='agendada';
                    $row['review_state']=($original['review_state']==='needs_review'||$ineligibleOrigin||$policy==='manual'||$ambiguous||($policy==='update'&&$hasResult))?'needs_review':'ready';
                    if(!$row['home_team_id']&&!$row['home_source_match_id']&&empty($sources['home']))$row['review_state']='needs_review';
                    if(!$row['away_team_id']&&!$row['away_source_match_id']&&empty($sources['away']))$row['review_state']='needs_review';
                    $this->effects[$id]=['id'=>$id,'event_id'=>$row['event_id'],'modality'=>$row['modality'],'phase'=>$row['stage'],
                        'action'=>$row['review_state']==='needs_review'?'Revisão necessária':($hasResult?'Reiniciar resultado':'Atualizar participantes'), 'had_result'=>$hasResult,
                        'requires_choice'=>$hasResult||$ambiguous||($this->effects[$id]['requires_choice']??false)];
                }
                if($row!==$original){$row['version']++;$this->write('matches',$row);$dirty=true;}
            }
            if(!$dirty)break;
        }
        if(!$this->safe)$this->warnings[]='A atualização automática não é segura para resultados já registrados. Escolha reiniciar ou reorganizar manualmente.';
    }

    private function validate(): void
    {
        $state=$this->snapshot();$matches=$state['matches'];$seen=[];$visiting=[];$walk=function($id)use(&$walk,&$seen,&$visiting,$matches){if(isset($seen[$id]))return;if(isset($visiting[$id]))throw new AdminOperationException('As origens das vagas formam um ciclo.');$visiting[$id]=true;foreach(['home','away'] as $side){$parentId=$matches[$id][$side.'_source_match_id'];if($parentId){if(!isset($matches[$parentId]))throw new AdminOperationException('A vaga aponta para uma partida inexistente.');$parent=$matches[$parentId];if($parent['event_id']!=$matches[$id]['event_id']||$parent['modality']!==$matches[$id]['modality']||$parent['gender']!==$matches[$id]['gender'])throw new AdminOperationException('Origem de outro campeonato ou gênero.');$walk($parentId);}}unset($visiting[$id]);$seen[$id]=true;};
        $occupied=[];$pairs=[];
        foreach($matches as $id=>$r){$walk($id);if($r['archived_at']||$r['review_state']!=='ready'||$r['status']==='cancelada')continue;
            $individual=is_individual_modality($r['modality']);$ids=[];
            foreach(['home','away'] as $side){$team=$state['teams'][$r[$side.'_team_id']]??null;$player=$state['players'][$r[$side.'_player_id']]??null;
                if($team&&($team['event_id']!=$r['event_id']||$team['modality']!==$r['modality']||(!is_mixed_modality($r['modality'])&&$team['gender']!==$r['gender'])))throw new AdminOperationException('Participante incompatível com a partida.');
                if($player&&$player['team_id']!=$r[$side.'_team_id']&&!in_array($r['status'],['encerrada','wo'],true))throw new AdminOperationException('A inscrição do jogador não corresponde ao time.');
                $pid=$individual?$r[$side.'_player_id']:$r[$side.'_team_id'];if($pid!==null)$ids[]=$pid;
                if($pid!==null&&!in_array($r['stage'],['grupo','individual','repescagem','preliminar'],true)){$key=implode(':',[$r['event_id'],$r['modality'],$r['gender'],$r['stage'],$pid]);if(isset($occupied[$key])&&$occupied[$key]!==$id)throw new AdminOperationException('O participante aparece em dois caminhos incompatíveis na mesma fase.');$occupied[$key]=$id;}
                if($r[$side.'_source_match_id']){$parent=$matches[$r[$side.'_source_match_id']];if(!$parent['archived_at']&&$parent['review_state']==='ready'&&$parent['winner_team_id']!==null&&($r[$side.'_team_id']!=$parent['winner_team_id']||($individual&&$r[$side.'_player_id']!=$parent['winner_player_id'])))throw new AdminOperationException('O participante não corresponde ao vencedor da fase anterior.');}
            }
            if(count($ids)===2&&$ids[0]===$ids[1])throw new AdminOperationException('Um participante não pode enfrentar a si mesmo.');
            if(count($ids)===2){sort($ids);$key=implode(':',[$r['event_id'],$r['modality'],$r['gender'],$r['stage'],$r['group_name']??'',...$ids]);if(isset($pairs[$key]))throw new AdminOperationException('Este confronto já está ativo nesta fase e grupo. Arquive a duplicação ou reorganize a fase.');$pairs[$key]=$id;}
            if(in_array($r['status'],['em_andamento','aguardando_resultado','encerrada','wo'],true)&&empty((json_decode($r['score_data']??'{}',true)??[])['collective_match'])&&count($ids)!==2)throw new AdminOperationException('Partida iniciada ou disputada sem dois participantes válidos.');
        }
    }

    private function recalculate(array $before): void
    {
        $after=$this->snapshot();$scopes=[];
        foreach(self::diff($before,$after) as $table=>$rows)foreach($rows as $change){$r=$change['after']??$change['before'];if(in_array($table,['matches','teams','event_modalities'],true)&&isset($r['event_id'],$r['modality'])){$g=$r['gender']??'masculino';$scopes[$r['event_id'].':'.$r['modality'].':'.$g]=[$r['event_id'],$r['modality'],$g];}}
        foreach(self::diff($before,$after)['players']??[] as $change)foreach(['before','after'] as $side){$p=$change[$side];$t=$after['teams'][$p['team_id']??0]??$before['teams'][$p['team_id']??0]??null;if($t)$scopes[$t['event_id'].':'.$t['modality'].':'.$t['gender']]=[$t['event_id'],$t['modality'],$t['gender']];}
        foreach($scopes as [$event,$mod,$gender]){
            $stmt=$this->pdo->prepare('DELETE FROM class_modality_points WHERE modality=? AND gender=? AND class_standings_id IN (SELECT id FROM class_standings WHERE event_id=?)');$stmt->execute([$mod,$gender,$event]);
            $this->active(function()use($event,$mod,$gender){if(game_is_new_modality($mod))game_recalculate_class_points((int)$event,$mod);else calculate_class_points((int)$event,$mod,$gender);});
            $this->pdo->prepare('UPDATE class_standings SET total_points=(SELECT COALESCE(SUM(points),0) FROM class_modality_points WHERE class_standings_id=class_standings.id) WHERE event_id=?')->execute([$event]);
            $this->pdo->prepare('UPDATE event_modalities SET structure_revision=structure_revision+1 WHERE event_id=? AND modality=?')->execute([$event,$mod]);
        }
    }

    private function impact(array $changes): array
    {
        $entities=[];$phases=[];
        foreach($changes as $table=>$rows){$entities[$table]=count($rows);if($table==='matches')foreach($rows as $id=>$change){$r=$change['after']??$change['before'];$action=$change['after']===null?'Excluir definitivamente':($change['before']===null?'Criar':($r['archived_at']?'Arquivar':($this->effects[$id]['action']??'Editar')));$phases[$r['stage']][]=['id'=>$id,'event_id'=>$r['event_id'],'modality'=>$r['modality'],'action'=>$action,'had_result'=>in_array($change['before']['status']??'', ['em_andamento','aguardando_resultado','encerrada','wo'],true),'before'=>array_intersect_key($change['before']??[],array_flip(['home_team_id','away_team_id','home_player_id','away_player_id','home_score','away_score','status'])),'after'=>array_intersect_key($change['after']??[],array_flip(['home_team_id','away_team_id','home_player_id','away_player_id','home_score','away_score','status','review_state']))];}}
        return ['entities'=>$entities,'phases'=>$phases,'match_count'=>count($changes['matches']??[])];
    }

    private function regenerate(array $command): void
    {
        $event=(int)($command['event_id']??0);$mod=(string)($command['modality']??'');$gender=is_mixed_modality($mod)?'masculino':($command['gender']??'masculino');$mode=$command['mode']??'missing';
        if(!in_array($mode,['missing','phase','all'],true))throw new AdminOperationException('Escolha gerar faltantes, reorganizar a fase ou regenerar a modalidade.');
        $settings=$this->pdo->prepare('SELECT * FROM event_modalities WHERE event_id=? AND modality=?');$settings->execute([$event,$mod]);$config=$settings->fetch();if(!$config)throw new AdminOperationException('Modalidade inválida.');
        $stmt=$this->pdo->prepare('SELECT * FROM matches WHERE event_id=? AND modality=? AND gender=? AND archived_at IS NULL');$stmt->execute([$event,$mod,$gender]);$existing=$stmt->fetchAll();
        if($mode!=='missing')foreach($existing as $r)if($mode==='all'||$r['stage']===($command['phase']??''))$this->archive('matches',(int)$r['id']);
        $kind=$command['kind']??$config['bracket_type'];$selected=array_map('intval',$command['eligible_players']??[]);
        $temporarilyArchived=[];
        if($selected){$stmt=$this->pdo->prepare('SELECT p.id FROM players p JOIN teams t ON t.id=p.team_id WHERE t.event_id=? AND t.modality=? AND p.archived_at IS NULL');$stmt->execute([$event,$mod]);foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $id)if(!in_array((int)$id,$selected,true)){$temporarilyArchived[]=(int)$id;$this->pdo->prepare('UPDATE players SET archived_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'),$id]);}}
        if(!empty($command['redistribute_groups'])){
            if($mode==='missing')throw new AdminOperationException('Redistribuir grupos exige prévia de reorganização ou regeneração.');
            $this->pdo->prepare('UPDATE teams SET group_name=? WHERE event_id=? AND modality=? AND gender=? AND archived_at IS NULL')->execute(['',$event,$mod,$gender]);
            $this->active(fn()=>assign_team_groups($event,$mod,$gender));
            if(is_individual_modality($mod))$this->pdo->prepare('UPDATE players SET group_name=? WHERE team_id IN (SELECT id FROM teams WHERE event_id=? AND modality=? AND gender=?) AND archived_at IS NULL')->execute(['',$event,$mod,$gender]);
        }
        try {
            $this->active(function()use($event,$mod,$gender,$kind,$config,$mode,$existing,$command){
                if($mode==='phase'){
                    $phase=$command['phase']??'';if(!in_array($phase,self::STAGES,true))throw new AdminOperationException('Selecione a fase a reorganizar.');
                    if($phase!=='grupo'){admin_generate_phase($event,$mod,$gender,$phase,$command['eligible_players']??[]);return;}
                }
                if($mode==='missing'&&$existing&&$kind!=='groups'&&$kind!=='generate_group'&&$kind!=='round_robin'&&$kind!=='repescagem'&&$config['bracket_type']!=='groups'){
                    admin_complete_bracket($event,$mod,$gender,$existing);return;
                }
                if(game_is_new_modality($mod)){
                    // For missing individual brackets, generate a candidate whole tree then reconcile identical slots below.
                    if($kind==='groups'||$kind==='generate_group')game_generate_groups($event,$mod);else game_generate_bracket($event,$mod);
                }elseif($kind==='groups'||$kind==='generate_group')generate_group_matches($event,$mod,null,null,$gender);
                elseif($kind==='round_robin')generate_round_robin_matches($event,$mod,null,null,$gender);
                elseif($kind==='repescagem')generate_repechage_matches($event,$mod,$gender);
                elseif($config['bracket_type']==='groups')generate_knockout_matches($event,$mod,$gender);
                else admin_generate_team_bracket($event,$mod,$gender);
            });
        } catch (RuntimeException $error) {
            if($error instanceof AdminOperationException||$error instanceof GameScoreException||$error instanceof PDOException)throw $error;
            throw new AdminOperationException($error->getMessage());
        } finally {foreach($temporarilyArchived as $id)$this->pdo->prepare('UPDATE players SET archived_at=NULL WHERE id=?')->execute([$id]);}
        if($mode!=='missing')$this->preserveCompatibleMatches($event,$mod,$gender,$existing);
        $this->recordGeneratedOrigins($event,$mod,$gender,array_column($existing,'id'));
        $this->warnings[]='O sorteio desta prévia está congelado: confirmar aplicará exatamente estes confrontos, sem novo sorteio.';
    }

    private function preserveCompatibleMatches(int $event,string $mod,string $gender,array $existing):void
    {
        $state=$this->snapshot();$map=[];$used=[];$candidates=array_filter($state['matches'],fn($m)=>$m['event_id']==$event&&$m['modality']===$mod&&$m['gender']===$gender&&!$m['archived_at']&&!in_array($m['id'],array_column($existing,'id'),true));
        foreach($candidates as $newId=>$candidate){
            foreach(['home','away'] as $side)if(isset($map[$candidate[$side.'_source_match_id']]))$candidate[$side.'_source_match_id']=$map[$candidate[$side.'_source_match_id']];
            foreach($existing as $old){if(isset($used[$old['id']])||$old['stage']!==$candidate['stage']||($old['group_name']??'')!==($candidate['group_name']??'')||$old['review_state']!=='ready')continue;
                if((json_decode($old['rules_snapshot']??'{}',true)??[])!==(json_decode($candidate['rules_snapshot']??'{}',true)??[]))continue;
                $compatible=true;foreach(['home','away'] as $side){if($old[$side.'_source_match_id']!=$candidate[$side.'_source_match_id']){$compatible=false;break;}if(!$candidate[$side.'_source_match_id']&&($old[$side.'_team_id']!=$candidate[$side.'_team_id']||$old[$side.'_player_id']!=$candidate[$side.'_player_id'])){$compatible=false;break;}}
                if(!$compatible)continue;$old['archived_at']=null;$old['archived_by']=null;$this->write('matches',$old);$map[$newId]=$old['id'];$used[$old['id']]=true;break;
            }
            if(!isset($map[$newId]))$this->write('matches',$candidate);
        }
        foreach($candidates as $newId=>$candidate)if(!isset($map[$newId])){foreach(['home','away'] as $side)if(isset($map[$candidate[$side.'_source_match_id']]))$candidate[$side.'_source_match_id']=$map[$candidate[$side.'_source_match_id']];$this->write('matches',$candidate);}
        foreach($map as $newId=>$oldId)$this->pdo->prepare('DELETE FROM matches WHERE id=?')->execute([$newId]);
        if($map)$this->warnings[]=count($map).' confronto(s) compatível(is) terão seus identificadores e resultados preservados.';
    }

    private function recordGeneratedOrigins(int $event,string $mod,string $gender,array $existingIds):void
    {
        $state=$this->snapshot();$ranks=[];
        foreach($state['teams'] as $t)if($t['event_id']==$event&&$t['modality']===$mod&&$t['gender']===$gender&&!$t['archived_at']&&!empty($t['group_name']))$ranks[$t['group_name']]=[];
        foreach($state['players'] as $p){$t=$state['teams'][$p['team_id']]??null;if($t&&$t['event_id']==$event&&$t['modality']===$mod&&!$p['archived_at']&&!empty($p['group_name']))$ranks[$p['group_name']]=[];}
        foreach($ranks as $group=>&$rows)$rows=$this->active(fn()=>game_is_new_modality($mod)?game_group_standings($event,$mod,(string)$group):calculate_standings($event,$mod,(string)$group,$gender));unset($rows);
        $hasGroups=false;foreach($state['matches'] as $m)if($m['event_id']==$event&&$m['modality']===$mod&&$m['gender']===$gender&&$m['stage']==='grupo'&&!$m['archived_at'])$hasGroups=true;
        foreach($state['matches'] as $m){if($m['event_id']!=$event||$m['modality']!==$mod||$m['gender']!==$gender||$m['archived_at']||in_array($m['id'],$existingIds,true))continue;
            $sources=json_decode($m['qualification_sources']??'{}',true)??[];$snapshot=[];
            foreach(['home','away'] as $side){$team=$state['teams'][$m[$side.'_team_id']]??[];$player=$state['players'][$m[$side.'_player_id']]??[];
                $snapshot[$side]=['team_id'=>$m[$side.'_team_id'],'player_id'=>$m[$side.'_player_id'],'name'=>$player['name']??$team['name']??null,'class_name'=>$team['class_name']??null];
                if(!$hasGroups||$m['stage']==='grupo'||$m[$side.'_source_match_id']||!$m[$side.'_team_id'])continue;
                $repechage=array_values(array_filter($state['matches'],fn($p)=>$p['event_id']==$event&&$p['modality']===$mod&&$p['gender']===$gender&&$p['stage']==='repescagem'&&!$p['archived_at']&&$p['winner_team_id']==$m[$side.'_team_id']));
                if($m['stage']!=='repescagem'&&count($repechage)===1){$m[$side.'_source_match_id']=$repechage[0]['id'];continue;}
                $origins=[];foreach($ranks as $group=>$rows)foreach($rows as $position=>$qualified)if(is_individual_modality($mod)?(($qualified['player_id']??null)==$m[$side.'_player_id']):(($qualified['team_id']??null)==$m[$side.'_team_id']))$origins[]=['type'=>'group_rank','group'=>(string)$group,'position'=>$position+1];
                if(count($origins)===1)$sources[$side]=$origins[0];
            }
            $m['participant_snapshot']=self::json($snapshot);$m['qualification_sources']=$sources?self::json($sources):null;$this->write('matches',$m);
        }
    }
}

/** Complete orphan rounds without redrawing existing entrants, results or IDs. */
function admin_complete_bracket(int $event,string $mod,string $gender,array $existing):void
{
    $pdo=db();$individual=is_individual_modality($mod);$covered=[];$referenced=[];
    foreach($existing as $m)foreach(['home','away'] as $side){if($m[$side.'_source_match_id'])$referenced[$m[$side.'_source_match_id']]=true;$id=$m[$side.($individual?'_player_id':'_team_id')];if($id)$covered[$id]=true;}
    $participants=$individual?game_players($event,$mod):$pdo->query('SELECT * FROM teams WHERE event_id='.(int)$event." AND modality=".$pdo->quote($mod)." AND gender=".$pdo->quote($gender))->fetchAll();
    foreach($participants as $p)if(!isset($covered[$p['id']]))throw new AdminOperationException('Há novas inscrições fora da chave existente. Selecione uma fase para reorganizar ou crie as vagas manualmente; os caminhos existentes serão preservados.');
    $rounds=['dezesseis_avos','oitavas','quartas','semifinal'];
    foreach($rounds as $stage){$nodes=[];foreach($existing as $m)if($m['stage']===$stage&&!isset($referenced[$m['id']]))$nodes[]=$m;
        if(count($nodes)%2)throw new AdminOperationException('Há uma vaga sem par inequívoco. Defina sua origem manualmente ou restaure a partida arquivada.');
        foreach(array_chunk($nodes,2) as [$h,$a]){ $next=knockout_next_stage($stage);$stmt=$pdo->prepare('INSERT INTO matches(event_id,modality,gender,stage,home_team_id,away_team_id,home_player_id,away_player_id,home_source_match_id,away_source_match_id,score_data,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$event,$mod,$gender,$next,$h['winner_team_id'],$a['winner_team_id'],$h['winner_player_id'],$a['winner_player_id'],$h['id'],$a['id'],json_encode(default_score_data($mod)),500+count($existing)]);$id=(int)$pdo->lastInsertId();$read=$pdo->prepare('SELECT * FROM matches WHERE id=?');$read->execute([$id]);$existing[]=$read->fetch();$referenced[$h['id']]=$referenced[$a['id']]=true;}
    }
}

/** Reorganize one round using known winners or an explicit, eligible selection. */
function admin_generate_phase(int $event,string $modality,string $gender,string $stage,array $selected=[]): int
{
    $pdo=db();$individual=is_individual_modality($modality);$nodes=[];
    $previous=match($stage){'oitavas'=>'dezesseis_avos','quartas'=>'oitavas','semifinal'=>'quartas','final'=>'semifinal',default=>null};
    if(!$selected&&$previous){$stmt=$pdo->prepare('SELECT * FROM matches WHERE event_id=? AND modality=? AND gender=? AND stage=? AND winner_team_id IS NOT NULL ORDER BY sort_order,id');$stmt->execute([$event,$modality,$gender,$previous]);foreach($stmt->fetchAll() as $m)$nodes[]=['team_id'=>$m['winner_team_id'],'player_id'=>$m['winner_player_id'],'match_id'=>$m['id']];}
    if(!$nodes){
        if($individual){$stmt=$pdo->prepare('SELECT p.id AS player_id,t.id AS team_id FROM players p JOIN teams t ON t.id=p.team_id WHERE t.event_id=? AND t.modality=? ORDER BY p.id');$stmt->execute([$event,$modality]);$nodes=array_values(array_filter($stmt->fetchAll(),fn($n)=>!$selected||in_array((int)$n['player_id'],array_map('intval',$selected),true)));}
        else{$stmt=$pdo->prepare('SELECT id AS team_id FROM teams WHERE event_id=? AND modality=? AND gender=? ORDER BY id');$stmt->execute([$event,$modality,$gender]);$nodes=$stmt->fetchAll();}
    }
    if(count($nodes)<2||count($nodes)>32||count($nodes)%2!==0)throw new AdminOperationException('Para reorganizar uma fase, selecione uma quantidade par de 2 a 32 participantes ou organize as vagas manualmente.');
    shuffle($nodes);$created=0;
    foreach(array_chunk($nodes,2) as [$home,$away]){
        $score=game_is_new_modality($modality)?($modality==='clash_royale'?['battles'=>[]]:['penalties'=>null,'decided_by'=>null]):bracket_score_data_with_players($modality,$home['player_id']??null,$away['player_id']??null);
        $stmt=$pdo->prepare('INSERT INTO matches(event_id,modality,gender,stage,home_team_id,away_team_id,home_player_id,away_player_id,home_source_match_id,away_source_match_id,score_data,rules_snapshot,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$event,$modality,$gender,$stage,$home['team_id'],$away['team_id'],$home['player_id']??null,$away['player_id']??null,$home['match_id']??null,$away['match_id']??null,json_encode($score,JSON_THROW_ON_ERROR),game_is_new_modality($modality)?json_encode(game_event_rules($event,$modality),JSON_THROW_ON_ERROR):null,500+$created++]);
    }
    return $created;
}

/** Same bracket tree used for teams and legacy individual modalities, separated by gender. */
function admin_generate_team_bracket(int $event,string $modality,string $gender): int
{
    $pdo=db();$individual=is_individual_modality($modality);
    if($individual){$stmt=$pdo->prepare('SELECT p.id AS player_id,t.id AS team_id FROM players p JOIN teams t ON t.id=p.team_id WHERE t.event_id=? AND t.modality=? ORDER BY p.id');$stmt->execute([$event,$modality]);}
    else{$stmt=$pdo->prepare('SELECT id AS team_id FROM teams WHERE event_id=? AND modality=? AND gender=? ORDER BY id');$stmt->execute([$event,$modality,$gender]);}
    $participants=$stmt->fetchAll();if(count($participants)<2||count($participants)>32)throw new AdminOperationException('Selecione de 2 a 32 participantes para o gerador; inscrições excedentes permanecem cadastradas.');
    shuffle($participants);$capacity=2;while($capacity<count($participants))$capacity*=2;$byes=$capacity-count($participants);$nodes=[];$index=0;
    for($pair=0;$pair<$capacity/2;$pair++){$nodes[]=$participants[$index++];$nodes[]=$pair<$byes?[]:$participants[$index++];}
    $created=0;
    while($capacity>=2){$stage=match($capacity){32=>'dezesseis_avos',16=>'oitavas',8=>'quartas',4=>'semifinal',default=>'final'};$next=[];
        foreach(array_chunk($nodes,2) as [$home,$away]){if(!$home||!$away){$next[]=$home?:$away;continue;}$score=bracket_score_data_with_players($modality,$home['player_id']??null,$away['player_id']??null);
            $stmt=$pdo->prepare('INSERT INTO matches (event_id,modality,gender,stage,home_team_id,away_team_id,home_player_id,away_player_id,home_source_match_id,away_source_match_id,score_data,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$event,$modality,$gender,$stage,$home['team_id']??null,$away['team_id']??null,$home['player_id']??null,$away['player_id']??null,$home['match_id']??null,$away['match_id']??null,json_encode($score,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),500+$created++]);$next[]=['match_id'=>(int)$pdo->lastInsertId()];}
        $nodes=$next;$capacity=intdiv($capacity,2);
    }
    return $created;
}
