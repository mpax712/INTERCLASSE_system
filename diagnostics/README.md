# Diagnosticos de carga

Estes endpoints ajudam a separar problema de hospedagem, banco, codigo e sessao.

Todos exigem `?key=SUA_CHAVE`, usando `APP_DIAGNOSTIC_KEY` do sistema.

## Endpoints

- `/diagnostics/static.php?key=SUA_CHAVE`
  - PHP simples sem consulta ao banco.
  - Se cair, o problema esta fora do banco: PHP, servidor web, limite da hospedagem ou bloqueio externo.

- `/diagnostics/db.php?key=SUA_CHAVE`
  - Faz consultas leves no banco.
  - Se o static passa e este cai, suspeite de conexao PostgreSQL/Supabase, query lenta, indice, lock ou limite de conexoes.

- `/diagnostics/session-lock.php?key=SUA_CHAVE&sid=shared&sleep=2&close=0`
  - Simula varias requisicoes usando a mesma sessao sem liberar o lock.
  - Se ficar em fila/lento, a sessao pode estar bloqueando concorrencia do mesmo usuario.

- `/diagnostics/session-lock.php?key=SUA_CHAVE&sid=shared&sleep=2&close=1`
  - Mesmo teste, mas liberando a sessao antes do `sleep`.
  - Se melhorar muito, `session_write_close()` em rotas de leitura pode ajudar.

## k6

Teste PHP sem banco:

```powershell
k6 run load-tests/hostinger-diagnostics.js `
  -e BASE_URL="https://seu-dominio.com" `
  -e KEY="SUA_CHAVE" `
  -e TARGET="static" `
  -e MAX_VUS="10" `
  -e STEP_SECONDS="10"
```

Teste banco:

```powershell
k6 run load-tests/hostinger-diagnostics.js `
  -e BASE_URL="https://seu-dominio.com" `
  -e KEY="SUA_CHAVE" `
  -e TARGET="db" `
  -e MAX_VUS="10" `
  -e STEP_SECONDS="10"
```

Teste sessao sem liberar lock:

```powershell
k6 run load-tests/hostinger-diagnostics.js `
  -e BASE_URL="https://seu-dominio.com" `
  -e KEY="SUA_CHAVE" `
  -e TARGET="session" `
  -e SESSION_CLOSE="0" `
  -e SESSION_SLEEP="2" `
  -e MAX_VUS="10" `
  -e STEP_SECONDS="10"
```

Teste sessao liberando lock:

```powershell
k6 run load-tests/hostinger-diagnostics.js `
  -e BASE_URL="https://seu-dominio.com" `
  -e KEY="SUA_CHAVE" `
  -e TARGET="session" `
  -e SESSION_CLOSE="1" `
  -e SESSION_SLEEP="2" `
  -e MAX_VUS="10" `
  -e STEP_SECONDS="10"
```
