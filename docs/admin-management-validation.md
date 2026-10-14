# Validação da administração e migração — 14/10/2026

Implementação local com prévia transacional, confirmação, justificativa, versões, idempotência, dependências transitivas, revisão, arquivamento, restauração e histórico. Partidas e inscrições de todas as modalidades são administradas pelas telas existentes.

- MySQL/MariaDB: 40 verificações da suíte administrativa aprovadas em cópia isolada.
- PostgreSQL/Supabase: 39 verificações da mesma suíte aprovadas em esquema isolado, além de cinco verificações específicas de proposta JSONB, recálculo da pontuação, exclusão de participante disputado, restauração e sessão persistente.
- Navegador Edge/Playwright: troca de gênero, cancelamento de prévia, campos preservados após falha, clique duplicado, atualização imediata, CSRF, bloqueio da API antiga, histórico e sessão expirada; sem recarregamento completo.
- PHP: arquivos verificados com PHP 8.4; JavaScript validado com Node.
- Migração local idempotente: contagens e todos os valores das colunas preexistentes preservados.
- Migração MySQL → novo Supabase: contagens e todos os valores de origem comparados dentro da transação, incluindo IDs e vínculos entre partidas.
- Páginas reais de eventos, FIFA, Clash Royale, Handebol, classificação e API de jogos responderam HTTP 200 após a troca de conexão.

Foram transferidos 1 evento, 3 configurações de modalidades, 9 turmas, 8 usuários, 1 associação de modalidade de usuário, 28 equipes, 116 jogadores, 36 partidas, 11 registros de placar e 9 registros de classificação. As demais tabelas da origem estavam vazias. Nenhum resultado real foi editado pelos testes.

Backups MySQL e configuração anterior ficam fora do repositório, em `interclasse-backups/2026-10-14/admin-management`, nos Documentos do usuário. A migração se recusa a sobrescrever um destino com dados.

O Vercel recebeu configuração de runtime PHP 0.9.0, entrada, rotas, inclusão de arquivos e proteção dos diretórios internos. Login e CSRF usam sessões compartilhadas no banco nesse ambiente. Não houve deploy nem teste do runtime PHP 8.5 no Vercel nesta validação: configure a variável privada DATABASE_URL no projeto e verifique um deploy de Preview. O painel administrativo usa JavaScript para prévia e execução; páginas públicas permanecem renderizadas no servidor.
