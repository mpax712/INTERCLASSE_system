# Testes de atualização sem recarregamento

`browser_workspace.cjs` verifica filtros e histórico sem navegação completa,
geração de jogos, edição, criação/movimentação/exclusão de times e partidas,
FIFA e Clash Royale, cancelamento de confirmações, clique duplo, falhas de rede,
CSRF inválido, erros de validação, expiração da sessão e envio sem JavaScript.

Execute em uma cópia do aplicativo com uma cópia do banco MySQL chamada
`interclasse_async_test_*`. Configure **o config.local.php da cópia** para esse
banco. Não use o banco real: os testes criam jogos e modificam os registros da
fixture. A fixture se recusa a rodar em um banco com outro nome.

Na cópia, execute `php tests/workspace_fixture.php`. A saída JSON fornece
`eventId` e `sessionId`; cada execução do teste deve usar uma fixture nova.
Inicie o servidor PHP da cópia em uma porta separada, por exemplo 8765, usando
o mesmo PHP e a mesma configuração de armazenamento de sessões da fixture.

Configure as variáveis `BASE_URL` (por exemplo `http://127.0.0.1:8765/`),
`EVENT_ID` e `TEST_SESSION_ID`, então execute `node tests/browser_workspace.cjs`.
O Playwright precisa estar instalado. `PLAYWRIGHT_MODULE` aceita o caminho
do módulo, e `BROWSER_EXECUTABLE` permite usar o Edge ou Chrome instalado.

Ao concluir, pare o servidor de teste e descarte o banco e a cópia isolados.
