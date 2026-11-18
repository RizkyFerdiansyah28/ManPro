import { preflight as runtimePreflight } from '../Frontend/isolationPreflight.mjs';
import assert from 'node:assert/strict';
import { chromium } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { writeFile } from 'node:fs/promises';
if (process.env.SILANSIA_P94E1_VERIFY === '1' || JSON.parse(process.env.SILANSIA_TEST_IDENTITY ?? '{}').runtime_root) runtimePreflight();

let raw = ''; for await (const chunk of process.stdin) raw += chunk;
const input = JSON.parse(raw); raw = '';
const { origin, sources, accounts, password, proof } = input;
assert.equal(new URL(origin).hostname, '127.0.0.1'); assert.ok(!['8000','5187',''].includes(new URL(origin).port));
const database = process.env.SILANSIA_TEST_DB_DATABASE, run = process.env.P94D_EVIDENCE_RUN;
const securityAcceptance = process.env.SILANSIA_P94E_VERIFY === '1';
const evidencePrefix = process.env.SILANSIA_P94E1_VERIFY === '1' ? 'SILANSIA_P9_4E_1' : securityAcceptance ? 'SILANSIA_P9_4E' : 'SILANSIA_P9_4D';
assert.match(database, /^silansia_test_[a-f0-9]{16}$/); assert.match(run, /^[a-f0-9]{16}$/);
const report = { database, origin, level: 'real Chrome → Laravel → owned PostgreSQL/private storage', cases: [], passed: 0, failed: 0 };
let browser, stage = 'isolation';
function control(body) {
    const r = spawnSync(process.env.P94D_PHP_BINARY, ['tests/Support/p94d-control.php'], { cwd: process.cwd(), env: process.env, input: JSON.stringify(body), encoding: 'utf8', timeout: 20000, windowsHide: true });
    assert.equal(r.status,0,'Owned control failed'); const value = JSON.parse(r.stdout); assert.equal(value.ok,true); return value;
}
const snapshot = s => control({action:'snapshot',residents:[s.resident]}).snapshot;
const familyPage = s => '/keluarga/tagihan/' + s.invoice;
const adminPage = (s,p) => '/admin/pembayaran/' + s.invoice + '/' + p;
const familyApi = s => '/keluarga/billing/invoices/' + s.invoice;
const adminApi = (s,p) => '/admin/billing/invoices/' + s.invoice + '/payments/' + p;
const image = { name: 'synthetic-proof.png', mimeType: 'image/png', buffer: Buffer.from(proof,'base64') };

async function scenario(name, sourceName, action) {
    stage = name; const source = sources[sourceName];
    const context = await browser.newContext({viewport:{width:1440,height:1000},serviceWorkers:'block'});
    const page = await context.newPage();
    const facts = {name,status:'running',financial_posts:0,reconcile_posts:0,context_gets:0,runtime_errors:0,blocked_origins:0,requests:[]};
    let loss = '', expire = false, originalContext = null, beforeFinancial = null;
    page.on('pageerror', () => {facts.runtime_errors++;});
    await context.route('**/*',async route => {
        const req = route.request(), url = new URL(req.url());
        if (url.origin !== origin) {facts.blocked_origins++; return route.abort('blockedbyclient');}
        const financial = req.method() === 'POST' && /^\/(?:keluarga\/billing\/invoices\/[^/]+\/payments|admin\/billing\/invoices\/[^/]+\/payments\/[^/]+\/(?:verify|reject))$/.test(url.pathname);
        if (req.method() === 'GET' && /\/(?:payment-context|review-context)$/.test(url.pathname)) {
            facts.context_gets++;
            const res = await route.fetch({maxRedirects:0});
            if (res.status() === 200) {
                const value = await res.json(); originalContext = value.command_context;
                if (expire) { value.command_context = control({action:'expired_context',token:value.command_context}).token; originalContext = value.command_context; return route.fulfill({response:res,json:value}); }
            }
            return route.fulfill({response:res});
        }
        if (financial) {
            facts.financial_posts++;
            if (beforeFinancial) { const callback = beforeFinancial; beforeFinancial = null; await callback(); }
            if (loss === 'absent') return route.abort('failed');
            if (loss === 'committed') { const res = await route.fetch({maxRedirects:0}); assert.ok([200,201].includes(res.status())); facts.actual_commit_status = res.status(); return route.abort('failed'); }
        }
        if (req.method() === 'POST' && url.pathname === '/billing/payment-commands/reconcile') {
            facts.reconcile_posts++;
            const body = req.postDataJSON(); facts.original_context_preserved = body.command_context === originalContext; assert.equal(facts.original_context_preserved,true);
        }
        return route.continue();
    });
    const api = async (path, method='GET', body, csrf=true) => {
        const headers = {Accept:'application/json'};
        if (method === 'POST') {headers['Content-Type']='application/json'; if(csrf) {const cookie=(await context.cookies(origin)).find(c=>c.name==='XSRF-TOKEN'); if(cookie)headers['X-XSRF-TOKEN']=decodeURIComponent(cookie.value);}}
        const res = await context.request.fetch(origin+path,{method,headers,...(body?{data:body}:{}),maxRedirects:0,timeout:20000});
        facts.requests.push({path:path.split('?')[0],method,status:res.status()}); return res;
    };
    const login = async account => {
        await page.goto(origin+'/login'); await page.locator('input[name=email]').fill(account.email); await page.locator('input[name=password]').fill(password);
        await page.getByRole('button',{name:/Masuk/}).click(); await page.waitForURL(url=>url.pathname!=='/login');
    };
    const logout = async () => {await page.getByRole('button',{name:'Keluar',exact:true}).click(); await page.waitForURL(origin+'/login');};
    const openFamily = async () => {facts.step="family-detail"; await page.goto(origin+familyPage(source)); await page.getByRole('heading',{name:source.name,exact:false}).count(); await page.getByText(source.name,{exact:true}).waitFor(); await page.locator('#payment-proof').waitFor(); await page.waitForFunction(()=>!document.querySelector('#payment-proof')?.disabled);};
    const submit = async (mode='', doubleClick=false) => {
        facts.step="submit-"+mode; loss=mode; const before=facts.financial_posts, contexts=facts.context_gets;
        await page.locator('#payment-proof').setInputFiles(image); assert.equal(facts.financial_posts,before); assert.equal(facts.context_gets,contexts);
        await page.getByRole('checkbox',{name:/Saya mengonfirmasi bukti transfer/}).check();
        const button = page.getByRole('button',{name:'Kirim bukti pembayaran',exact:true});
        if (doubleClick) { await button.dblclick(); facts.double_submit_attempted = true; } else await button.click();
        await page.getByRole('heading',{name:mode?'Hasil tindakan belum diketahui':'Tindakan tercatat',exact:true}).waitFor();
    };
    const readyReview = async payment => {facts.step="admin-review"; await page.goto(origin+adminPage(source,payment)); await page.getByRole('heading',{name:'Pengajuan pembayaran',exact:true}).waitFor(); await page.getByRole('button',{name:'Tinjau verifikasi',exact:true}).waitFor({state:'visible'}); await page.waitForFunction(()=>[...document.querySelectorAll('button')].find(b=>b.textContent==='Tinjau verifikasi')?.disabled===false);};
    const verify = async () => {
        facts.step="verify-dialog"; await page.getByRole('button',{name:'Tinjau verifikasi',exact:true}).click(); const dialog=page.getByRole('dialog');
        assert.equal(await dialog.getByRole('checkbox',{name:/Saya sudah memeriksa mutasi bank/}).isChecked(),false);
        assert.equal(await dialog.getByRole('button',{name:'Konfirmasi dan verifikasi',exact:true}).isDisabled(),true);
        await dialog.getByRole('checkbox',{name:/Saya sudah memeriksa mutasi bank/}).check(); await dialog.getByRole('checkbox',{name:/Saya mengonfirmasi tindakan final/}).check();
        await dialog.getByRole('button',{name:'Konfirmasi dan verifikasi',exact:true}).click(); await page.getByRole('heading',{name:'Tindakan tercatat',exact:true}).waitFor();
    };
    try {
        await action({page,context,source,facts,api,login,logout,openFamily,submit,readyReview,verify,setExpire:v=>{expire=v;},original:()=>originalContext,beforePost:callback=>{beforeFinancial=callback;}});
        assert.equal(facts.runtime_errors,0); assert.equal(facts.blocked_origins,0); facts.status='PASS'; report.passed++;
    } catch(error) {facts.status='FAIL';facts.error_class=error.name;report.failed++;throw error;}
    finally {report.cases.push(facts);originalContext=null;await context.close();}
}
try {
    const identity=await(await fetch(origin+'/__p94d/identity')).json();
    assert.equal(identity.environment,'testing'); assert.equal(identity.database,database); assert.equal(identity.connection,'silansia_testing');
    for(const key of ['ownership_verified','proof_root_owned','upload_temp_owned'])assert.equal(identity[key],true);
    for(const key of ['config_cached','environment_file_exists','csrf_test_bypass'])assert.equal(identity[key],false);
    report.isolation_verified=true; browser=await chromium.launch({channel:'chrome',headless:true,chromiumSandbox:true}); report.browser_version=browser.version();
    await scenario('family own list/detail and explicit upload then Admin private proof and final verify','happy',async r=>{
        await r.login(r.source.family); await r.page.goto(origin+'/keluarga/tagihan'); await r.page.getByRole('heading',{name:'Tagihan',exact:true}).waitFor();
        await r.page.getByText(r.source.name,{exact:true}).waitFor(); await r.page.screenshot({path:`docs/report/${evidencePrefix}_FAMILY_DESKTOP_${run}.png`,fullPage:true});
        await r.openFamily(); const before=snapshot(r.source); await r.submit('',true); const pending=snapshot(r.source);
        assert.equal(pending.payments.length,1);assert.equal(pending.payments[0].status,'pending');assert.equal(pending.invoices[0].status,'unpaid');assert.equal(r.facts.financial_posts,1);assert.deepEqual(pending.protected_domain,before.protected_domain);
        await r.page.getByText('Menunggu verifikasi Admin. Tagihan belum dinyatakan lunas.',{exact:true}).waitFor(); assert.equal(await r.page.locator('#payment-proof').count(),0);
        const p=pending.payments[0].id; await r.logout();await r.login(r.source.admin); await r.page.goto(origin+'/admin/pembayaran');
        const pagination=r.page.getByRole('navigation',{name:'Halaman daftar pembayaran'});await pagination.waitFor();
        // Full regression preserves earlier committed fixtures. Find the target using
        // actual UI pagination rather than assuming newest data is on page one.
        for(let number=1;await r.page.getByText(r.source.name,{exact:true}).count()===0;number++) {
            assert.ok(number<25,'Synthetic queue target must remain bounded');
            const next=pagination.getByRole('button',{name:'Berikutnya',exact:true});assert.equal(await next.isEnabled(),true);
            await next.click();await pagination.getByText('Halaman '+(number+1),{exact:true}).waitFor();
        }
        r.facts.queue_pagination_used=true;
        await r.readyReview(p);await r.page.getByRole('button',{name:'Buka bukti transfer',exact:true}).click();await r.page.locator('[data-payment-proof]').waitFor();
        const imageUrl=await r.page.locator('[data-payment-proof]').getAttribute('src');assert.ok(imageUrl.startsWith('blob:'));r.facts.authorized_blob_only=true;
        await r.page.getByRole('button',{name:'Tutup bukti transfer',exact:true}).click();await r.verify();const paid=snapshot(r.source);assert.equal(paid.invoices[0].status,'paid');assert.equal(paid.payments[0].status,'verified');assert.equal(paid.payments.length,1);assert.deepEqual(paid.protected_domain,before.protected_domain);
        await r.logout();await r.login(r.source.family);await r.page.goto(origin+familyPage(r.source));await r.page.getByText('Lunas',{exact:true}).waitFor();assert.equal(await r.page.locator('#payment-proof').count(),0);
        await r.page.setViewportSize({width:390,height:844});await r.page.screenshot({path:`docs/report/${evidencePrefix}_FAMILY_MOBILE_${run}.png`,fullPage:true});r.facts.database_verified=true;
    });
    await scenario('reject stays unpaid, public reason visible and resubmit creates new attempt','reject',async r=>{
        await r.login(r.source.family);await r.openFamily();await r.submit();const first=snapshot(r.source).payments[0].id;
        await r.logout();await r.login(r.source.admin);await r.readyReview(first);await r.page.getByRole('button',{name:'Tolak bukti',exact:true}).click();
        const dialog=r.page.getByRole('dialog');await dialog.getByLabel('Kode alasan').fill('receipt_not_confirmed');await dialog.getByLabel('Alasan untuk keluarga').fill('Transfer sintetis belum ditemukan.');
        await dialog.getByLabel('Catatan privat Admin (opsional)').fill('PRIVATE-SYNTHETIC-NOTE-NOT-FOR-FAMILY');await dialog.getByRole('checkbox',{name:/Saya mengonfirmasi tindakan final/}).check();await dialog.getByRole('button',{name:'Konfirmasi dan tolak',exact:true}).click();
        await r.page.getByRole('heading',{name:'Tindakan tercatat',exact:true}).waitFor();const rejected=snapshot(r.source);assert.equal(rejected.invoices[0].status,'unpaid');assert.equal(rejected.payments[0].status,'rejected');
        await r.logout();await r.login(r.source.family);await r.openFamily();await r.page.getByText('Alasan untuk keluarga: Transfer sintetis belum ditemukan.',{exact:true}).waitFor();assert.ok(!(await r.page.locator('body').innerText()).includes('PRIVATE-SYNTHETIC'));
        await r.submit();const after=snapshot(r.source);assert.equal(after.payments.length,2);assert.equal(after.payments.filter(p=>p.status==='pending').length,1);assert.ok(after.payments.some(p=>p.id!==first));r.facts.new_attempt_not_reopen=true;
    });
    await scenario('foreign family denied on page/API, no financial disclosure','happy',async r=>{
        await r.login(r.source.foreign);const page=await r.page.goto(origin+familyPage(r.source));assert.equal(page.status(),404);assert.equal((await r.api(familyApi(r.source))).status(),404);
        assert.ok(!(await r.page.locator('body').innerText()).includes(r.source.name));
    });
    await scenario('caregiver has no payment navigation or route access; inactive Admin cannot login','happy',async r=>{
        await r.login(accounts.caregiver);assert.equal(await r.page.locator('a[href="/admin/pembayaran"],a[href="/keluarga/tagihan"]').count(),0);
        assert.equal((await r.page.goto(origin+'/admin/pembayaran')).status(),403);assert.equal((await r.api('/admin/billing/payments')).status(),403);
        await r.page.goto(origin+'/perawat');await r.logout();await r.page.locator('input[name=email]').fill(accounts.inactive.email);await r.page.locator('input[name=password]').fill(password);await r.page.getByRole('button',{name:/Masuk/}).click();await r.page.waitForURL(origin+'/login');
        assert.equal((await r.api('/admin/billing/payments')).status(),401);
    });
    await scenario('real missing and invalid CSRF denied; valid request is processed without mutation','absent',async r=>{
        await r.login(r.source.family);const before=snapshot(r.source);
        assert.equal((await r.api('/billing/payment-commands/reconcile','POST',{},false)).status(),419);
        const bad=await r.context.request.post(origin+'/billing/payment-commands/reconcile',{headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':'invalid'},data:{}});assert.equal(bad.status(),419);
        assert.equal((await r.api('/billing/payment-commands/reconcile','POST',{})).status(),422);assert.deepEqual(snapshot(r.source),before);r.facts.native_csrf=true;
    });
    await scenario('lost response after real COMMIT reconciles original in-memory context without re-upload','lost',async r=>{
        await r.login(r.source.family);await r.openFamily();await r.submit('committed');const before=snapshot(r.source);assert.equal(before.payments.length,1);
        await r.page.getByRole('button',{name:'Periksa hasil tindakan yang sama',exact:true}).click();await r.page.getByRole('heading',{name:'Hasil tindakan ditemukan',exact:true}).waitFor();
        assert.equal(r.facts.financial_posts,1);assert.equal(r.facts.reconcile_posts,1);assert.deepEqual(snapshot(r.source),before);
        const stored=await r.page.evaluate(()=>Object.entries(sessionStorage).filter(([k])=>k.startsWith('silansia.payment.')));assert.equal(stored.length,0);
    });
    await scenario('absent original outcome stays unresolved and never creates first payment','absent',async r=>{
        await r.login(r.source.family);await r.openFamily();const before=snapshot(r.source);await r.submit('absent');
        await r.page.getByRole('button',{name:'Periksa hasil tindakan yang sama',exact:true}).click();await r.page.getByRole('heading',{name:'Hasil belum dapat dipastikan',exact:true}).waitFor();
        assert.equal(r.facts.financial_posts,1);assert.deepEqual(snapshot(r.source),before);assert.equal(await r.page.getByRole('button',{name:'Kirim bukti pembayaran',exact:true}).isDisabled(),true);
    });
    await scenario('reload loses ephemeral command, boolean hold blocks replacement request','reload',async r=>{
        await r.login(r.source.family);await r.openFamily();await r.submit('absent');const count=r.facts.context_gets;
        const storage=await r.page.evaluate(()=>Object.entries(sessionStorage).filter(([k])=>k.startsWith('silansia.payment.hold.v1:')));assert.equal(storage.length,1);assert.ok(storage.every(([k,v])=>/:[a-f0-9]{64}$/.test(k)&&v==='1'));
        await r.page.reload();await r.page.getByRole('heading',{name:'Perlu pemeriksaan manual',exact:true}).waitFor();assert.equal(await r.page.getByRole('button',{name:'Periksa hasil tindakan yang sama',exact:true}).count(),0);
        assert.equal(r.facts.context_gets,count);assert.equal(r.facts.financial_posts,1);assert.equal(snapshot(r.source).payments.length,0);
    });
    await scenario('expired context rejects mutation then reconciles unresolved without new UUID','expired',async r=>{
        await r.login(r.source.family);await r.openFamily();const before=snapshot(r.source);r.setExpire(true);
        await r.page.locator('#payment-proof').setInputFiles(image);await r.page.getByRole('checkbox',{name:/Saya mengonfirmasi bukti transfer/}).check();await r.page.getByRole('button',{name:'Kirim bukti pembayaran',exact:true}).click();
        await r.page.getByRole('heading',{name:'Data berubah atau hasil perlu diperiksa',exact:true}).waitFor();await r.page.getByRole('button',{name:'Periksa hasil tindakan yang sama',exact:true}).click();await r.page.getByRole('heading',{name:'Hasil belum dapat dipastikan',exact:true}).waitFor();
        assert.deepEqual(snapshot(r.source),before);assert.equal(r.facts.context_gets,1);assert.equal(r.facts.financial_posts,1);
    });
    await scenario('session expiry/rotation rejects old recovery; logout does not persist command','session',async r=>{
        await r.login(r.source.family);await r.openFamily();await r.submit('committed');const before=snapshot(r.source);control({action:'expire_session',actor:r.source.family.id});
        await r.page.getByRole('button',{name:'Periksa hasil tindakan yang sama',exact:true}).click();await r.page.getByRole('heading',{name:'Akses sesi berubah',exact:true}).waitFor();assert.equal(r.facts.reconcile_posts,0);
        await r.login(r.source.family);await r.page.goto(origin+familyPage(r.source));await r.page.getByRole('heading',{name:'Perlu pemeriksaan manual',exact:true}).waitFor();assert.deepEqual(snapshot(r.source),before);
    });
    await scenario('zero charge stays paid without fabricated payment or upload form','zero',async r=>{
        await r.login(r.source.family);await r.page.goto(origin+familyPage(r.source));await r.page.getByText('Tagihan tanpa biaya. Tidak diperlukan payment atau bukti transfer.',{exact:true}).waitFor();
        assert.equal(snapshot(r.source).payments.length,0);assert.equal(await r.page.locator('#payment-proof').count(),0);assert.equal(r.facts.financial_posts,0);
    });
    await scenario('existing invoice and safety UI remain readable with payment enabled only in owned test','happy',async r=>{
        await r.login(r.source.admin);assert.equal((await r.page.goto(origin+'/monitoring/records')).status(),200);assert.equal((await r.page.goto(origin+'/admin/billing/invoices/'+r.source.invoice)).status(),200);
        await r.page.getByRole('heading',{name:'Detail invoice',exact:true}).waitFor();assert.equal(r.facts.financial_posts,0);
    });
    if (securityAcceptance) {
        await scenario('P94E keyboard dialog Escape and note disposal do not allocate financial intent','reject',async r=>{
            const payment=snapshot(r.source).payments.find(p=>p.status==='pending').id;
            await r.login(r.source.admin);await r.readyReview(payment);const before=snapshot(r.source);
            await r.page.getByRole('button',{name:'Tinjau verifikasi',exact:true}).click();const dialog=r.page.getByRole('dialog');
            assert.equal(await dialog.getByRole('checkbox').first().isChecked(),false);
            await r.page.keyboard.press('Shift+Tab');assert.equal(await dialog.getByRole('button',{name:'Kembali',exact:true}).evaluate(el=>el===document.activeElement),true);
            await r.page.keyboard.press('Escape');await dialog.waitFor({state:'hidden'});
            assert.equal(await r.page.getByRole('button',{name:'Tinjau verifikasi',exact:true}).evaluate(el=>el===document.activeElement),true);
            await r.page.getByRole('button',{name:'Tolak bukti',exact:true}).click();await dialog.getByLabel('Catatan privat Admin (opsional)').fill('SYNTHETIC-EPHEMERAL-NOTE');
            await dialog.getByRole('button',{name:'Kembali',exact:true}).click();await r.page.getByRole('button',{name:'Tolak bukti',exact:true}).click();
            await r.page.waitForFunction(()=>document.querySelector('#payment-private-note')?.value==='');
            assert.equal(await dialog.getByLabel('Catatan privat Admin (opsional)').inputValue(),'');await r.page.keyboard.press('Escape');
            assert.equal(r.facts.financial_posts,0);assert.equal(r.facts.context_gets,0);assert.deepEqual(snapshot(r.source),before);r.facts.keyboard_and_note_disposal=true;
        });
        await scenario('P94E authorized proof Blob is revoked on close and logout','happy',async r=>{
            const payment=snapshot(r.source).payments.find(p=>p.status==='verified').id;
            await r.login(r.source.admin);await r.page.goto(origin+adminPage(r.source,payment));const before=snapshot(r.source);
            await r.page.getByRole('button',{name:'Buka bukti transfer',exact:true}).click();await r.page.locator('[data-payment-proof]').waitFor();
            const url=await r.page.locator('[data-payment-proof]').getAttribute('src');assert.ok(url.startsWith('blob:'));
            assert.equal(await r.page.evaluate(async u=>{try{return(await fetch(u)).ok;}catch{return false;}},url),true);
            await r.page.getByRole('button',{name:'Tutup bukti transfer',exact:true}).click();
            assert.equal(await r.page.evaluate(async u=>{try{return(await fetch(u)).ok;}catch{return false;}},url),false);
            await r.page.getByRole('button',{name:'Buka bukti transfer',exact:true}).click();await r.page.locator('[data-payment-proof]').waitFor();
            const next=await r.page.locator('[data-payment-proof]').getAttribute('src');await r.logout();
            assert.equal(await r.page.evaluate(async u=>{try{return(await fetch(u)).ok;}catch{return false;}},next),false);
            assert.deepEqual(snapshot(r.source),before);assert.equal(r.facts.financial_posts,0);r.facts.blob_revoke_verified=true;
        });
        await scenario('P94E invalid extension and oversized file never allocate context or upload','absent',async r=>{
            await r.login(r.source.family);await r.openFamily();const before=snapshot(r.source);
            await r.page.locator('#payment-proof').setInputFiles({...image,name:'benign-synthetic.txt',mimeType:'text/plain'});
            await r.page.getByText('Gunakan berkas JPEG atau PNG.',{exact:true}).waitFor();
            await r.page.locator('#payment-proof').setInputFiles({...image,buffer:Buffer.alloc(2097153,120)});
            await r.page.getByText('Ukuran bukti maksimal 2 MiB.',{exact:true}).waitFor();
            await r.page.getByRole('checkbox',{name:/Saya mengonfirmasi bukti transfer/}).check();
            assert.equal(await r.page.getByRole('button',{name:'Kirim bukti pembayaran',exact:true}).isDisabled(),true);
            assert.equal(r.facts.financial_posts,0);assert.equal(r.facts.context_gets,0);assert.deepEqual(snapshot(r.source),before);
        });
        await scenario('P94E competing durable review makes original UI stale without retry or replacement','reject',async r=>{
            const payment=snapshot(r.source).payments.find(p=>p.status==='pending').id;
            await r.login(r.source.admin);await r.readyReview(payment);let committed;
            r.beforePost(async()=>{
                const context=await r.api(adminApi(r.source,payment)+'/review-context?operation=verify');assert.equal(context.status(),200);
                const token=(await context.json()).command_context;
                const other=await r.api(adminApi(r.source,payment)+'/verify','POST',{command_context:token,confirm:true,bank_receipt_checked:true});
                assert.equal(other.status(),200);committed=snapshot(r.source);assert.equal(committed.invoices[0].status,'paid');
            });
            await r.page.getByRole('button',{name:'Tinjau verifikasi',exact:true}).click();const dialog=r.page.getByRole('dialog');
            await dialog.getByRole('checkbox',{name:/Saya sudah memeriksa mutasi bank/}).check();await dialog.getByRole('checkbox',{name:/Saya mengonfirmasi tindakan final/}).check();
            const rejected=r.page.waitForResponse(res=>res.request().method()==='POST'&&new URL(res.url()).pathname.endsWith('/verify'));
            await dialog.getByRole('button',{name:'Konfirmasi dan verifikasi',exact:true}).click();assert.equal((await rejected).status(),409);
            await r.page.getByRole('heading',{name:'Data berubah atau hasil perlu diperiksa',exact:true}).waitFor();
            await r.page.getByRole('button',{name:'Muat ulang informasi',exact:true}).click();await r.page.getByText('Lunas',{exact:true}).waitFor();
            await r.page.getByRole('button',{name:'Periksa hasil tindakan yang sama',exact:true}).click();await r.page.getByRole('heading',{name:'Hasil belum dapat dipastikan',exact:true}).waitFor();
            assert.equal(r.facts.financial_posts,1);assert.equal(r.facts.context_gets,1);assert.equal(r.facts.reconcile_posts,1);
            assert.deepEqual(snapshot(r.source),committed);assert.equal(committed.payments.filter(p=>p.status==='verified').length,1);r.facts.stale_original_not_retried=true;
        });
    }
} catch(error) {report.failed=Math.max(report.failed,1);report.failure_stage=stage;report.error_class=error.name;}
finally {if(browser)await browser.close();report.ok=report.failed===0;await writeFile(`docs/report/${evidencePrefix}_REAL_BROWSER_${run}.json`,JSON.stringify(report,null,2));process.stdout.write(JSON.stringify({ok:report.ok,passed:report.passed,failed:report.failed,stage:report.failure_stage??'complete'}));process.exitCode=report.ok?0:1;}
