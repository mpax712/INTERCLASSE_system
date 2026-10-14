// Run only against the isolated fixture named "Teste de navegação assíncrona".
// Requires Playwright, BASE_URL, EVENT_ID and TEST_SESSION_ID.
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.BASE_URL;
const eventId = process.env.EVENT_ID;
if (!base || !eventId || !process.env.TEST_SESSION_ID
    || !['127.0.0.1', 'localhost'].includes(new URL(base).hostname)) {
    throw new Error('Configure an isolated local fixture and its authenticated test session.');
}

(async () => {
    const browser = await chromium.launch({headless: true,
        ...(process.env.BROWSER_EXECUTABLE ? {executablePath: process.env.BROWSER_EXECUTABLE} : {})});
    try {
        const context = await browser.newContext();
        const cookie = {name: 'PHPSESSID', value: process.env.TEST_SESSION_ID,
            domain: new URL(base).hostname, path: '/'};
        await context.addCookies([cookie]);
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const eventUrl = gender => base + `index.php?page=event&id=${eventId}&modality=handbol&gender=${gender}`;
        const managerUrl = modality => base + `index.php?page=match_manager&event_id=${eventId}&modality=${modality}&gender=feminino`;
        await page.goto(eventUrl('masculino'));
        assert.match(await page.locator('main h1').innerText(), /Teste de navegação assíncrona/);

        let documentRequests = 0;
        page.on('request', request => { if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documentRequests++; });
        async function mark() { await page.evaluate(() => { window.workspaceTestMarker = 'preserved'; }); }
        async function intact() { assert.equal(await page.evaluate(() => window.workspaceTestMarker), 'preserved'); }
        async function idle() { await page.waitForFunction(() => document.querySelector('#event-workspace').getAttribute('aria-busy') !== 'true'); }
        async function action(callback, method = 'GET', confirm = false) {
            const response = page.waitForResponse(response => response.url().includes('index.php') && response.request().method() === method);
            response.catch(() => {});
            await callback();
            if (confirm) await page.locator('#app-confirm-modal [data-confirm-accept]').click();
            await response;
            await idle();
            await intact();
        }
        await mark();
        await action(() => page.getByRole('link', {name: 'Feminino', exact: true}).click());
        assert.equal(new URL(page.url()).searchParams.get('gender'), 'feminino');
        await action(() => page.locator('form:has(input[value="generate_group"]) button').click(), 'POST', true);
        assert.equal(await page.locator('.match-board-card').count(), 1);
        assert.equal(new URL(page.url()).searchParams.get('gender'), 'feminino');
        await action(() => page.getByRole('link', {name: 'Masculino', exact: true}).click());
        assert.equal(await page.locator('.match-board-card').count(), 0);
        await action(() => page.goBack());
        assert.equal(new URL(page.url()).searchParams.get('gender'), 'feminino');
        await action(() => page.goForward());
        assert.equal(new URL(page.url()).searchParams.get('gender'), 'masculino');
        assert.equal(documentRequests, 0, 'Filters, generation and history must not navigate the document');

        await page.route('**/index.php?**', route => route.request().method() === 'GET' ? route.abort() : route.continue());
        await page.getByRole('link', {name: 'Feminino', exact: true}).click();
        await page.locator('[data-workspace-error]').waitFor();
        assert.equal(await page.locator('.match-board-card').count(), 0);
        await intact();
        await page.unroute('**/index.php?**');

        await page.route('**/index.php?**', async route => {
            if (route.request().method() === 'GET' && new URL(route.request().url()).searchParams.get('gender') === 'feminino') {
                await new Promise(resolve => setTimeout(resolve, 300));
            }
            await route.continue().catch(() => {});
        });
        await page.getByRole('link', {name: 'Feminino', exact: true}).click();
        await action(() => page.getByRole('link', {name: 'Masculino', exact: true}).click());
        assert.equal(new URL(page.url()).searchParams.get('gender'), 'masculino');
        await page.unroute('**/index.php?**');

        await page.goto(managerUrl('handbol'));
        await mark();
        documentRequests = 0;
        await action(() => page.locator('.match-manager-switcher select[name=gender]').selectOption('masculino'));
        await action(() => page.locator('.match-manager-switcher select[name=gender]').selectOption('feminino'));
        const advancement = page.locator('#match-manager-advancement-form');
        await advancement.locator('[name=teams_advancing_per_group]').fill('1');
        await advancement.locator('[name=csrf_token]').evaluate(input => { input.value = 'invalid'; });
        await action(() => advancement.locator('button[type=submit], button:not([type])').click(), 'POST');
        assert.match(await page.locator('[data-workspace-error]').innerText(), /expirou/);
        assert.equal(await advancement.locator('[name=teams_advancing_per_group]').inputValue(), '1');
        await page.goto(managerUrl('handbol'));
        await mark();
        const search = page.locator('#match-manager-search');
        await search.fill('masculino');
        await action(() => page.locator('#match-manager-advancement-form button').click(), 'POST');
        assert.equal(await search.inputValue(), 'masculino');
        await search.fill('');
        await page.locator('[data-edit-match]').first().click();
        await page.locator('#match-edit-place').fill('Local atualizado AJAX');
        await action(() => page.locator('#match-edit-save').click(), 'POST');
        assert.equal(await page.locator('.modal.show').count(), 0);
        assert.match(await page.locator('#event-workspace').innerText(), /Local atualizado AJAX/);

        const teamCount = await page.locator('form:has(input[value="move_team"])').count();
        const teamIdsBefore = await page.locator('form:has(input[value="move_team"]) input[name=team_id]').evaluateAll(inputs => inputs.map(input => input.value));
        const createTeam = page.locator('.match-manager-create-team');
        const availableClass = await createTeam.locator('[name=class_id] option:not([value=""])').last().getAttribute('value');
        await createTeam.locator('[name=class_id]').selectOption(availableClass);
        await action(() => createTeam.locator('button').click(), 'POST');
        assert.equal(await page.locator('form:has(input[value="move_team"])').count(), teamCount + 1);
        const teamIdsAfter = await page.locator('form:has(input[value="move_team"]) input[name=team_id]').evaluateAll(inputs => inputs.map(input => input.value));
        const movedId = teamIdsAfter.find(id => !teamIdsBefore.includes(id));
        const moveTeam = page.locator(`form:has(input[value="move_team"]):has(input[name="team_id"][value="${movedId}"])`);
        await moveTeam.locator('[name=group_name]').selectOption('');
        await action(() => moveTeam.locator('button').click(), 'POST');
        const removeTeam = page.locator(`form:has(input[value="delete_team"]):has(input[name="team_id"][value="${movedId}"])`);
        await action(() => removeTeam.locator('button').click(), 'POST', true);
        assert.equal(await page.locator('form:has(input[value="move_team"])').count(), teamCount);

        await page.locator('#match-manager-open-create').click();
        const createMatch = page.locator('#match-manager-create-match-form');
        await createMatch.locator('[name=stage]').selectOption('final');
        const homeId = await createMatch.locator('[name=home_team_id] option').nth(1).getAttribute('value');
        await createMatch.locator('[name=home_team_id]').selectOption(homeId);
        const awayId = await createMatch.locator('[name=away_team_id] option').nth(1).getAttribute('value');
        await createMatch.locator('[name=away_team_id]').selectOption(awayId);
        const matchesBefore = await page.locator('.match-manager-row').count();
        const matchIdsBefore = await page.locator('[data-edit-match]').evaluateAll(buttons => buttons.map(button => button.dataset.id));
        await action(() => page.locator('#match-create-save').click(), 'POST');
        assert.equal(await page.locator('.match-manager-row').count(), matchesBefore + 1);
        const matchIdsAfter = await page.locator('[data-edit-match]').evaluateAll(buttons => buttons.map(button => button.dataset.id));
        const createdMatchId = matchIdsAfter.find(id => !matchIdsBefore.includes(id));
        await action(() => page.locator(`form:has(input[value="delete_match"]):has(input[name="match_id"][value="${createdMatchId}"]) button`).click(), 'POST', true);
        assert.equal(await page.locator('.match-manager-row').count(), matchesBefore);

        // Cancelled confirmations must never send a POST.
        let posts = 0;
        const countPosts = request => { if (request.method() === 'POST') posts++; };
        page.on('request', countPosts);
        await page.locator('form:has(input[value="delete_match"]) button').first().click();
        await page.locator('#app-confirm-modal [data-bs-dismiss=modal]').last().click();
        await page.locator('#app-confirm-modal').waitFor({state: 'hidden'});
        assert.equal(posts, 0);
        page.off('request', countPosts);

        for (const modality of ['fifa', 'clash_royale']) {
            await page.goto(managerUrl(modality));
            await mark();
            documentRequests = 0;
            if (modality === 'fifa') {
                await page.locator('[name=bracket_type]').selectOption('simple');
                await action(() => page.locator('form:has(input[value="save_format"]) button').click(), 'POST');
            }
            posts = 0;
            page.on('request', countPosts);
            await page.locator('form:has(input[value="generate_bracket"]) button').click();
            const response = page.waitForResponse(response => response.request().method() === 'POST');
            await page.locator('#app-confirm-modal [data-confirm-accept]').dblclick();
            await response;
            await idle();
            await intact();
            assert.equal(posts, 1, 'Double clicks must create one request');
            page.off('request', countPosts);
            assert.equal(await page.locator('.game-manager-match').count(), 3);
            assert.equal(documentRequests, 0);
            await page.locator('.game-manager-match details summary').first().click();
            await page.locator('form:has(input[value="save_schedule"])').first().locator('[name=place]').fill('Agenda AJAX');
            await action(() => page.locator('form:has(input[value="save_schedule"])').first().getByRole('button', {name: 'Salvar'}).click(), 'POST');
            assert.equal(await page.locator('form:has(input[value="save_schedule"])').first().locator('[name=place]').inputValue(), 'Agenda AJAX');
            if (modality === 'fifa') {
                const formatForm = page.locator('form:has(input[value="save_format"])');
                await formatForm.locator('[name=bracket_type]').evaluate(select => {
                    select.add(new Option('Invalid fixture value', 'invalid'));
                    select.value = 'invalid';
                });
                await action(() => formatForm.locator('button').click(), 'POST');
                assert.equal(await formatForm.locator('[name=bracket_type]').inputValue(), 'invalid');
                assert.equal(await page.locator('[data-workspace-message="warning"]').count(), 1);
            }
        }

        let failedPosts = 0;
        await page.route('**/index.php?**', route => {
            if (route.request().method() === 'POST') { failedPosts++; return route.abort(); }
            return route.continue();
        });
        await page.locator('form:has(input[value="save_rules"]) button').click();
        await page.locator('[data-workspace-error]').waitFor();
        await idle();
        assert.equal(failedPosts, 1);
        assert.match(await page.locator('[data-workspace-error]').innerText(), /pode ter sido concluída/);
        await page.unroute('**/index.php?**');

        await context.clearCookies();
        await page.locator('form:has(input[value="save_rules"]) button').click();
        await page.locator('[data-workspace-error] a').waitFor();
        assert.match(await page.locator('[data-workspace-error]').innerText(), /sessão expirou/);
        await intact();

        const noJs = await browser.newContext({javaScriptEnabled: false});
        await noJs.addCookies([cookie]);
        const fallback = await noJs.newPage();
        await fallback.goto(eventUrl('masculino'));
        await fallback.locator('form:has(input[value="generate_group"]) button').click();
        await fallback.waitForLoadState();
        assert.equal(await fallback.locator('.match-board-card').count(), 1);
        assert.equal(new URL(fallback.url()).searchParams.get('gender'), 'masculino');
        assert.deepEqual(errors, []);
        console.log('PASS: workspace filters, history, generation, editing, errors, confirmations, duplicate prevention, session expiration and no-JS fallback');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
