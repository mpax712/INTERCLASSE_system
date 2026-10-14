# Banco local do XAMPP

O sistema usa o MariaDB do XAMPP em `127.0.0.1:3306`, banco
`interclasse_local`, com a configuração em `includes/config.local.php`.
O usuário local é `root`, sem senha, conforme a instalação atual do XAMPP.

A migração de 13/10/2026 trouxe todos os 7 usuários e o último evento por
`created_at DESC, id DESC`: **Interclasse Etapa 1 Parte 2**, ID 6.
Foram preservados os IDs, hashes de senha, 9 turmas necessárias, 3 modalidades,
26 equipes, 81 jogadores e 6 jogos. As tabelas de vínculos, ocorrências,
histórico de placar e classificação foram copiadas com o filtro deste evento;
não havia registros associados nessas tabelas na origem. Sugestões e eventos
anteriores não foram importados. O banco externo não foi alterado.

O esquema MySQL correspondente está em `mysql_local_schema.sql`, sem dados
pessoais. Não importe esse arquivo sobre o banco local já criado.

Para iniciar novamente:

1. Abra o painel do XAMPP e inicie **MySQL**.
2. No PowerShell, entre na pasta que contém o `index.php` e execute
   `php -S localhost:8080`.
3. Acesse <http://localhost:8080>.

O PHP 8.4 instalado via WinGet teve `pdo_mysql` habilitado em seu `php.ini`.
O PHP incluído no XAMPP também possui essa extensão. A conexão local aceita
senha vazia; a validação de senha obrigatória continua para PostgreSQL.

A migração conferiu contagens e valores de todas as linhas selecionadas e
aplicou as chaves estrangeiras após a importação para validar os vínculos.
As consultas de agregação, ordenação, JSON e atualização de classificação
foram adaptadas para MariaDB. Os testes de escrita usaram transação com rollback.
