# Sistema de Gerenciamento de Interclasse Escolar

## Executar com o XAMPP local

1. Inicie o MySQL no painel do XAMPP e crie o banco `interclasse_local` com charset `utf8mb4`.
2. Para um banco novo, importe `database/mysql_local_schema.sql`. O arquivo contém apenas a estrutura; os dados dos campeonatos não são publicados neste repositório.
3. Copie `includes/config.local.example.php` para `includes/config.local.php` e ajuste a conexão. Se o banco já existe, use os dados dele sem reimportar o esquema.
4. Habilite a extensão PHP `pdo_mysql`, execute `php -S localhost:8080` na raiz do projeto e abra `http://localhost:8080`.

O sistema também oferece suporte a PostgreSQL/Supabase. A seção de instalação abaixo descreve essa alternativa. Configurações privadas, dumps com dados, logs, caches e sessões ficam fora do Git.

## Gerenciamento administrativo

Após fazer backup, execute `php scripts/migrate_admin_management.php`. A migração funciona em MySQL/MariaDB e PostgreSQL, é aditiva e idempotente e preserva resultados e inscrições existentes. Bancos novos também precisam dessa migração após importar o esquema base.

O painel de montagem reúne partidas, jogadores, inscrições, turmas globais, eventos, regras e histórico. Administradores podem corrigir campeonatos encerrados. Cada alteração apresenta uma prévia, exige justificativa e confirma exatamente a proposta analisada. Escolha atualizar com segurança, reiniciar resultados dependentes ou pausar os confrontos inconsistentes para reorganizar manualmente. Cancelar a prévia não modifica registros.

Arquivamento preserva identidades e permite restauração. Exclusão definitiva exige digitar EXCLUIR; uma cópia dos registros removidos permanece no histórico administrativo. As APIs antigas recusam gravações administrativas de inscrições, turmas, eventos e placares: use `api/admin_operations.php`. Os demais perfis mantêm suas permissões de operação.

Os geradores suportam até 32 participantes nos mata-matas automáticos. Inscrições excedentes podem permanecer cadastradas; selecione os elegíveis ou organize as fases manualmente. Gerar faltantes preserva caminhos existentes e informa quando uma vaga não pode ser reconstruída com segurança. Regenerar arquiva estruturas substituídas e conserva confrontos compatíveis.

Os testes `tests/admin_integration.php` aceitam exclusivamente uma cópia com banco/esquema `interclasse_admin_test_*`. `tests/browser_admin.cjs` valida a interface nessa cópia; `tests/admin_pg_confirmation.php` verifica proposta congelada, exclusão, restauração e sessões PostgreSQL. Não execute operações de teste no banco real.

## Hospedagem no Vercel

O arquivo `vercel.json` usa `vercel-php@0.9.0`, encaminha `/` e `/index.php` para `api/index.php`, inclui os arquivos PHP necessários e mantém assets e APIs nas URLs atuais. Diretórios internos e arquivos privados não são publicados como páginas. Selecione a raiz deste repositório como Root Directory e o preset Other, sem comando de build personalizado.

Configure `DATABASE_URL` no painel Environment Variables do Vercel, com a senha real codificada na URL e `sslmode=require`. O `.env.example` aponta para o novo pooler de São Paulo na porta 5432 (modo sessão). Configure Production e Preview conforme os bancos correspondentes; não envie senhas ao Git. Use `APP_DEBUG=false` e `APP_SECURE_COOKIES=true`.

No Vercel, sessões de login/CSRF são compartilhadas no banco (`app_sessions`), e caches locais são desativados. Isso evita depender dos arquivos temporários de uma única instância. O esquema deve estar migrado antes do deploy. A configuração local privada permite continuar usando PHP/XAMPP; ela não é incluída no deploy. O runtime e a hospedagem devem ser verificados com um deploy de Preview antes de promover alterações em produção.

Para migrar MySQL a um Supabase vazio, use `php scripts/migrate_mysql_to_supabase.php CAMINHO_CONFIG_PRIVADA public`. O script se recusa a sobrescrever um destino com registros, mantém IDs e vínculos, compara todos os valores dentro da transação e bloqueia acesso direto às tabelas pela API pública do Supabase. Faça backup da origem antes de executá-lo.

Sistema simples em PHP puro, PostgreSQL (Supabase), HTML, CSS e JavaScript para organizar interclasses escolares com eventos, modalidades, times, jogadores, tabela pública, classificação, repescagem, W.O. e placar controlado durante o jogo.

## Modalidades FIFA e Clash Royale

Eventos novos podem incluir FIFA e Clash Royale. Cada aluno é inscrito individualmente e mantém vínculo com sua turma. FIFA aceita fase de grupos seguida de mata-mata, ou mata-mata direto. Nos grupos, a pontuação padrão é 3/1/0; no mata-mata, empate em gols exige decisão por pênaltis. Clash Royale usa mata-mata e séries melhor de 1, 3 ou 5 batalhas, com melhor de 3 como padrão. As regras de pontuação da turma, as regras do FIFA e o tamanho da série podem ser ajustados na página de montagem da modalidade. Cada partida conserva as regras vigentes no momento de sua criação.

Antes de atualizar um banco existente, faça backup do banco e execute a migração aditiva em uma cópia. Depois execute no banco ativo:

```bash
php scripts/migrate_game_features.php
```

A migração é idempotente, adiciona campos e índices e não reimporta o arquivo de 2026. Para um banco novo, use o esquema em `database/supabase_schema.sql`. O evento de 2026 mantém apenas suas modalidades originais.

As alterações de placar usam versão da partida e identificador único da requisição. Se duas pessoas tentarem alterar a mesma versão, a segunda recebe conflito e deve atualizar o estado atual. Repetir a mesma requisição não aplica o resultado novamente. Correções administrativas passam pela prévia de impacto e permitem resolver dependências já iniciadas pelas estratégias do painel.

Para executar os testes de integração, configure `TEST_SCHEMA` com um esquema isolado cujo nome comece com `interclasse_game_stage_`. O primeiro comando abaixo copia as tabelas atuais do esquema público; ele se recusa a sobrescrever um esquema de teste existente. Execute-o apenas em um banco de desenvolvimento ou de homologação. Os testes criam e removem dados nesse esquema, portanto nunca os aponte para o esquema público.

```bash
TEST_SCHEMA=interclasse_game_stage_teste php scripts/create_game_test_schema.php
TEST_SCHEMA=interclasse_game_stage_teste PGOPTIONS='-c search_path=interclasse_game_stage_teste,public' php tests/game_integration.php
```

## Principais recursos

- Login e gestão de usuários por administrador.
- Perfis de administrador, mesário e representante.
- Páginas públicas para jogos do dia, partidas, placares e tabelas sem exigir login.
- Catálogo público de eventos com busca, filtros por status/modalidade/data e cards resumidos.
- Página "Meus jogos" para o aluno/representante ver partidas dos times onde está inscrito ou que representa.
- Criação de eventos por administradores com futsal, vôlei e basquete.
- Controle de status do evento: rascunho, inscrições abertas, em andamento e encerrado.
- Cadastro de times por modalidade, grupo e representante.
- Pré-cadastro de jogadores com prazo de edição do elenco.
- Geração de jogos da fase de grupos com tentativa de evitar jogos seguidos para o mesmo time.
- Repescagem opcional entre terceiros colocados.
- Geração de quartas, semifinais ou final conforme quantidade de classificados.
- Avanço automático de quartas para semifinais e de semifinais para final quando os jogos forem encerrados.
- Placar por modalidade:
  - Futsal: gols.
  - Vôlei: sets e pontos do set atual.
  - Basquete: pontos por quarto.
- Botões para somar/remover pontos com confirmação antes de gravar no banco.
- Opção de W.O. para o mesário/admin que comanda a partida.
- API interna em `api/` com respostas JSON.
- Exportação CSV da classificação geral e da súmula geral por modalidade.
- Sugestões de usuários e registros administrativos de alterações de placar.

## Requisitos

- PHP 8.1 ou superior.
- PostgreSQL 14+ (o projeto está configurado para Supabase).
- Extensão PHP `pdo_pgsql`.
- XAMPP, Laragon, Apache/Nginx ou o servidor embutido do PHP.

## Instalação rápida

1. Copie `includes/config.local.example.php` para `includes/config.local.php` e informe a senha do Supabase. Como alternativa, defina `DATABASE_URL` conforme `.env.example`.
2. Valide o dump e execute a migração:

```bash
export DATABASE_URL='postgresql://postgres.irpaajhtekpvlpfwzxke:SENHA_CODIFICADA@aws-0-us-east-2.pooler.supabase.com:5432/postgres?sslmode=require'
php scripts/migrate_to_supabase.php --dry-run
php scripts/migrate_to_supabase.php
```

A migração cria o esquema, copia os dados de `database/InscricaoInterclassed.sql`, ajusta as sequências e valida as contagens. Por segurança, ela para se o destino já contiver dados; `--force` substitui os dados apenas quando essa for a intenção explícita. Para criar somente o esquema, use `--schema-only`.

3. Rode o servidor local:

```bash
C:\xampp\php\php.exe -S localhost:8000
```

4. Acesse `http://localhost:8000`.

Se o PHP estiver no PATH, você também pode usar:

```bash
php -S localhost:8000 -t New_project
```

## Dados do dump legado

O dump atual contém 11 turmas e 7 usuários com seus hashes de senha existentes. As demais tabelas não têm registros no arquivo. Depois da migração, revise os perfis e redefina as senhas antes de usar o sistema em produção.

## Deploy local no Apache

Antes de copiar para o Apache, rode uma checagem rápida:

```bash
find New_project -name "*.php" -print0 | xargs -0 -n1 php -l
php -S 127.0.0.1:8080 -t New_project
curl -I http://127.0.0.1:8080/index.php
```

Depois teste login como admin, mesário e representante, além de uma alteração de placar em uma partida de teste.

Para publicar no Apache local usando `/var/www/html`, copie o conteúdo do projeto e mantenha a configuração local fora do versionamento:

```bash
sudo cp -r /media/rmzin/48FCEA2FFCEA16CA/ProjetoManada/New_project/* /var/www/html/
sudo cp /media/rmzin/48FCEA2FFCEA16CA/ProjetoManada/New_project/includes/config.local.php /var/www/html/includes/config.local.php
```

Se não houver `config.local.php`, o sistema usa `DATABASE_URL` ou as variáveis `DB_*`. A senha nunca deve ser gravada em `includes/config.php`.

## Diagnóstico na Hostinger

Se a hospedagem retornar erro 500 logo na `index.php`, suba os arquivos atualizados e acesse:

```text
https://seu-dominio.com/diagnostico.php?key=manada-check
```

Confira principalmente:

- `PHP_VERSION`: deve ser PHP 8.1 ou superior.
- `PDO_PGSQL`: deve aparecer `ok`.
- `DB_CONNECTION`: deve aparecer `ok`.
- `TABLE_*`: todas as tabelas principais devem aparecer `ok`.

Se `DB_CONNECTION` falhar, confira no Supabase o host, porta, nome do banco, usuário e senha; depois configure `DATABASE_URL` ou `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` e `DB_SSLMODE`. Depois do teste, altere `APP_DIAGNOSTIC_KEY` ou remova `diagnostico.php` da hospedagem.

## Fluxo sugerido do interclasse

1. Admin cria mesários e representantes.
2. Admin cria o evento e escolhe as modalidades.
3. Admin cria os times, define grupo e escolhe representante.
4. Representante cadastra jogadores até o prazo definido.
5. Admin ou gestor do evento gera jogos da fase de grupos.
6. Mesário autorizado controla o placar ao vivo durante cada partida.
7. Ao fim dos grupos, admin ou gestor do evento pode gerar repescagem.
8. Depois, admin ou gestor do evento gera eliminatórias.
9. Quartas e semifinais criam a próxima fase automaticamente quando encerradas.

## Critérios da classificação

A tabela ordena principalmente por saldo de pontuação, como pedido no projeto. Em empate, usa pontos marcados, confronto direto, pontos, vitórias e nome do time.

## Segurança aplicada

- Senhas com `password_hash`.
- PDO com prepared statements.
- Sessões com cookies HTTPOnly e SameSite.
- CSRF em formulários e endpoints de escrita da API.
- Regras de permissão por perfil e por evento.

## Interface administrativa

Turmas, equipes, jogadores, montagem e placar continuam em telas separadas, com os formulários e a organização visual originais. As permissões ampliadas do administrador permanecem disponíveis, inclusive em eventos encerrados.

- Use **Mais opções** em uma partida para editar participantes, fase, origens das vagas, agenda ou resultado. As regras específicas ficam recolhidas até serem abertas.
- Use **Detalhes / transferir** na lista de jogadores ou **Editar equipe** na lista de equipes para alterações administrativas adicionais.
- **Histórico de alterações** mostra as mudanças relacionadas à tela atual. **Arquivados e restauração** permite recuperar cadastros ou analisar a exclusão definitiva.
- **Reorganizar confrontos** oferece geração de partidas faltantes, reorganização de fase e regeneração da modalidade com uma proposta antes da gravação.
- Alterações com dependências mostram os impactos e exigem justificativa. Resultados incompatíveis exigem reinício explícito ou pausa para revisão manual.

Os formulários atualizam o conteúdo sem navegação completa. Cancelar preserva os campos; erros de validação mantêm o formulário disponível. Sem JavaScript, os formulários administrativos passam por uma tela de confirmação no servidor. Os controles de placar ao vivo continuam dependendo de JavaScript.

## Observação sobre atualização de placar

O placar público faz uma consulta simples a cada poucos segundos. Não usa WebSocket e não precisa de infraestrutura especial. Se quiser deixar ainda mais simples, basta remover o `setInterval` de `assets/js/live-score.js` e deixar a atualização por recarregamento manual da página.
