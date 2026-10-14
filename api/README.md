# API Interna

Todas as respostas usam JSON. Rotas de leitura são públicas quando a tela também é pública. Rotas de escrita exigem sessão autenticada e `csrf_token` no JSON, no query string ou no header `X-CSRF-Token`.

## Autenticação

`GET /api/auth.php` retorna o usuário logado.

`POST /api/auth.php`

```json
{"action":"login","email":"mesario@escola.local","password":"password"}
```

`POST /api/auth.php`

```json
{"action":"logout"}
```

## Jogos e resultados

`GET /api/games.php?date=2026-05-01`

`GET /api/score.php?id=1` retorna o placar atual.

`POST /api/score.php`

```json
{"match_id":1,"csrf_token":"TOKEN","action":"adjust","team":"home","amount":1}
```

Ações aceitas:

- `start`: inicia a partida.
- `finish`: encerra a partida.
- `adjust`: soma ou subtrai gols/pontos.
- `volleyball_point`: soma ou subtrai ponto no set atual.
- `volleyball_set`: adiciona um set ao time.
- `wo`: encerra por W.O.

## Eventos

`GET /api/events.php`

`GET /api/events.php?id=1`

`POST /api/events.php`

```json
{"csrf_token":"TOKEN","name":"Interclasse 2026","start_date":"2026-05-10","modalities":["futsal","handbol","queimada","volei"],"group_count":2}
```

`PUT /api/events.php`

```json
{"csrf_token":"TOKEN","id":1,"name":"Interclasse 2026","start_date":"2026-05-10","status":"em_andamento","group_count":2}
```

`DELETE /api/events.php?id=1&csrf_token=TOKEN`

## Times

`GET /api/teams.php?event_id=1&modality=futsal`

`GET /api/teams.php?id=1`

`POST /api/teams.php`

```json
{"csrf_token":"TOKEN","event_id":1,"modality":"futsal","name":"2A","class_name":"2º A","group_name":"A","representative_id":3}
```

`PUT /api/teams.php`

```json
{"csrf_token":"TOKEN","id":1,"name":"2A","class_name":"2º A","group_name":"B","representative_id":3}
```

`DELETE /api/teams.php?id=1&csrf_token=TOKEN`

## Jogadores

`GET /api/players.php?team_id=1`

`GET /api/players.php?id=1`

`POST /api/players.php`

```json
{"csrf_token":"TOKEN","team_id":1,"name":"Ana Silva","email":"ana@escola.local","jersey_number":10}
```

`PUT /api/players.php`

```json
{"csrf_token":"TOKEN","id":1,"name":"Ana Silva","email":"ana@escola.local","jersey_number":11}
```

`DELETE /api/players.php?id=1&csrf_token=TOKEN`

## Exportações

Exportações exigem perfil administrador e CSRF no query string.

`GET /api/export.php?type=modality_general&event_id=1&modality=futsal&csrf_token=TOKEN`

`GET /api/export.php?type=general&event_id=1&csrf_token=TOKEN`
