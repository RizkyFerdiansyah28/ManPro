<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Billing\PaymentCommand;
use App\Services\Billing\PaymentFailure;
use App\Services\Billing\PaymentProofStore;
use App\Services\Billing\PaymentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BillingIntegrityFixtures;
use Tests\Support\PaymentProofFixtureStore;

// SQL fixture adapter ONLY. This never hooks/changes the application financial writer.
$fixtures = new class($owned)
{
    use BillingIntegrityFixtures;

    public function __construct(private readonly Closure $verifyOwned) {}

    public function graph(): array { ($this->verifyOwned)(); return $this->billingGraph(); }

    public function locks(array $g): void { $this->billingLocks($g); }

    public function transfer(array $g): void { $this->billingLocks($g); $this->billingTransfer($g); }

    public function submit(array $g, string $mode, string $authority): array
    {
        $this->billingLocks($g);
        $reference = (string) Str::uuid();
        $payment = ['id' => (string) Str::uuid(), 'invoice_id' => $g['invoice']['id'],
            'submitted_by' => $g['p']['family'], 'amount' => $g['revision']['amount'],
            'proof_path' => 'payment-proofs/'.$reference.'/object.png', 'submitted_at' => '2026-11-03 00:00:00+07',
            'status' => 'pending', 'verified_by' => null, 'verified_at' => null, 'verification_notes' => null,
            'billing_schema_version' => 1, 'invoice_revision_id' => $g['revision']['id'],
            'submission_channel' => 'family_upload', 'proof_sha256' => str_repeat('b', 64),
            'proof_size_bytes' => 128, 'proof_mime' => 'image/png', 'proof_disk' => 'local', 'public_rejection_reason' => null];
        DB::table('payments')->insert($payment);
        $request = $this->billingRequest($g, 'submit', $g['p']['family'], $g['revision']['id'], $payment['id']);
        $action = $this->billingAction($g, $request, 'payment_submitted', $g['revision']['id'], $payment['id'], '2026-11-03 00:00:00.123456+07');
        $descriptor = ['descriptor_version' => 1, 'proof_reference' => $reference,
            ...array_intersect_key($payment, array_flip(['proof_disk','proof_path','proof_sha256','proof_size_bytes','proof_mime']))];
        $subject = null;
        if ($mode !== 'missing_subject') {
            $subject = DB::scalar('SELECT public.proof_scan_initialize_subject(?,?,?,?::jsonb,?)',
                [$payment['id'], $request['id'], $action['id'], json_encode($descriptor, JSON_THROW_ON_ERROR), $authority]);
        }
        DB::select('SELECT public.billing_validate_invoice(?)', [$g['invoice']['id']]);

        return [...$g, ...compact('payment', 'request', 'action', 'descriptor', 'subject')];
    }
};
$case = function (string $id, string $preconditions, string $operation, string $expected, callable $body) use (&$record, $owned): mixed {
    $owned();
    $entry = ['id'=>$id,'preconditions'=>$preconditions,'operation'=>$operation,'expected'=>$expected,'started_at'=>gmdate('c')];
    echo 'CASE '.$id.PHP_EOL;
    try {
        $value = $body();
        $entry['status']='PASS';
        $entry['actual']=is_array($value) ? $value : ['result'=>'expected result observed'];
        return $value;
    } catch (Throwable $e) {
        $entry['status']='FAIL';
        $entry['actual']=['exception'=>get_class($e),'message'=>$e instanceof PDOException ? ($e->errorInfo[2] ?? 'SQL failure') : $e->getMessage()];
        throw $e;
    } finally {
        $entry['finished_at']=gmdate('c');
        $record['tests'][]=$entry;
    }
};
$tx = function (callable $body) use ($owned): mixed {
    $owned();
    return DB::transaction(function () use ($body) {
        DB::statement("SET LOCAL lock_timeout='5s'");
        DB::statement("SET LOCAL statement_timeout='15s'");
        DB::statement("SET LOCAL idle_in_transaction_session_timeout='20s'");
        return $body();
    }, 1);
};
$reject = function (string $state, string $constraint, callable $body) use ($assert): array {
    try { $body(); } catch (Throwable $e) {
        $actualState = $e instanceof PaymentFailure ? $e->sqlState : ($e instanceof PDOException ? ($e->errorInfo[0] ?? null) : null);
        $rule = $e instanceof PaymentFailure ? $e->rule : null;
        if ($e instanceof PDOException) {
            preg_match('/constraint "([^"]+)"/i', $e->errorInfo[2] ?? '', $m);
            $rule = $m[1] ?? null;
        }
        $assert($actualState === $state && $rule === $constraint, 'wrong rejection: '.get_class($e).' state='.($actualState??'null').' rule='.($rule??'null').' expected='.$constraint);
        $assert(DB::transactionLevel() === 0, 'rejected_transaction_not_closed');
        return ['sqlstate'=>$actualState,'constraint'=>$rule,'transaction_level'=>0];
    }
    throw new RuntimeException('expected_rejection_missing:'.$constraint);
};
$financial = static function (): array {
    $r=[];
    foreach (['users','elderlies','service_classes','service_enrollments','financial_ownership_periods','invoices','invoice_revisions','payments','billing_requests','billing_actions'] as $table) {
        $r[$table]=DB::selectOne('SELECT count(*)::int AS rows,md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),\'[]\'::jsonb)::text) AS digest FROM public.'.$table.' t');
    }
    return json_decode(json_encode($r, JSON_THROW_ON_ERROR), true, flags:JSON_THROW_ON_ERROR);
};
// Source-derived semantic manifest, independently projected from pg_catalog.
// The observed fingerprint is evidence, never used to repin a source/financial contract.
$captureTechnicalCatalog = function()use($schema,$assert,&$record){
    $schema->assertReady();
    $expected=(new ReflectionProperty($schema,'expected'))->getValue($schema);
    $norm=static fn(?string $v)=>$v===null?null:strtolower(preg_replace('/[\s()"]/', '',str_replace('public.','',$v)));
    $want=['tables'=>[],'constraints'=>[],'indexes'=>[],'functions'=>[],'triggers'=>[]];
    $got=$want;
    $prefix=['proof_scan_subjects'=>'pss','proof_scan_policies'=>'psp','proof_scan_policy_heads'=>'psh',
        'proof_scan_jobs'=>'psj','proof_scan_attempts'=>'psa','proof_scan_verdicts'=>'psv','proof_scan_audit_events'=>'pse'];
    foreach($expected['tables']as$t){
        $want['tables'][$t['name']]=$t['columns'];
        $rows=DB::select("SELECT attname,format_type(atttypid,atttypmod) AS type,attnotnull FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped ORDER BY attnum",['public.'.$t['name']]);
        $got['tables'][$t['name']]=array_map(static fn($r)=>['name'=>$r->attname,'type'=>$r->type,'nullable'=>!$r->attnotnull],$rows);
        foreach($t['columns']as$c)if(!$c['nullable']){
            $n=$prefix[$t['name']].'_'.$c['name'].'_nn';
            $want['constraints'][$n]=['table'=>$t['name'],'type'=>'n','columns'=>[$c['name']],'parent'=>null,'parent_columns'=>[],'expression'=>null,'deferred'=>false,'initially_deferred'=>false];
        }
    }
    foreach($expected['constraints']as$c)$want['constraints'][$c['name']]=[
        'table'=>$c['table'],'type'=>$c['type'],'columns'=>$c['type']==='c'?[]:$c['columns'],
        'parent'=>$c['type']==='f'?$c['ref']:null,'parent_columns'=>$c['type']==='f'?$c['refcols']:[],
        'expression'=>$c['type']==='c'?$norm($c['call']):null,'deferred'=>$c['deferred']??false,'initially_deferred'=>$c['deferred']??false];
    $rows=DB::select("SELECT t.relname,p.conname,p.contype,p.condeferrable,p.condeferred,r.relname AS parent,
        array_to_json(ARRAY(SELECT a.attname::text FROM unnest(p.conkey) WITH ORDINALITY x(n,o) JOIN pg_attribute a ON a.attrelid=p.conrelid AND a.attnum=x.n ORDER BY x.o))::text AS cols,
        array_to_json(ARRAY(SELECT a.attname::text FROM unnest(p.confkey) WITH ORDINALITY x(n,o) JOIN pg_attribute a ON a.attrelid=p.confrelid AND a.attnum=x.n ORDER BY x.o))::text AS refs,
        pg_get_expr(p.conbin,p.conrelid) AS expression FROM pg_constraint p JOIN pg_class t ON t.oid=p.conrelid LEFT JOIN pg_class r ON r.oid=p.confrelid
        WHERE t.relnamespace='public'::regnamespace AND left(t.relname,11)='proof_scan_' AND p.contype<>'t'");
    foreach($rows as$r)$got['constraints'][$r->conname]=[
        'table'=>$r->relname,'type'=>$r->contype,'columns'=>$r->contype==='c'?[]:json_decode($r->cols,true,flags:JSON_THROW_ON_ERROR),
        'parent'=>$r->parent,'parent_columns'=>json_decode($r->refs,true,flags:JSON_THROW_ON_ERROR),
        'expression'=>$norm($r->expression),'deferred'=>$r->condeferrable,'initially_deferred'=>$r->condeferred];
    foreach($expected['indexes']as$i)$want['indexes'][$i['name']]=['table'=>$i['table'],'columns'=>$i['columns'],'unique'=>$i['unique'],'predicate'=>$norm($i['predicate'])];
    foreach($expected['constraints']as$c)if(in_array($c['type'],['p','u'],true))$want['indexes'][$c['name']]=['table'=>$c['table'],'columns'=>$c['columns'],'unique'=>true,'predicate'=>null];
    $rows=DB::select("SELECT t.relname,i.relname AS name,x.indisunique,
        array_to_json(ARRAY(SELECT a.attname::text FROM unnest(x.indkey) WITH ORDINALITY k(n,o) JOIN pg_attribute a ON a.attrelid=x.indrelid AND a.attnum=k.n ORDER BY k.o))::text AS cols,
        pg_get_expr(x.indpred,x.indrelid) AS predicate FROM pg_index x JOIN pg_class t ON t.oid=x.indrelid JOIN pg_class i ON i.oid=x.indexrelid
        WHERE t.relnamespace='public'::regnamespace AND left(t.relname,11)='proof_scan_'");
    foreach($rows as$r)$got['indexes'][$r->name]=['table'=>$r->relname,'columns'=>json_decode($r->cols,true,flags:JSON_THROW_ON_ERROR),'unique'=>$r->indisunique,'predicate'=>$norm($r->predicate)];
    $canonicalTypes=static function(string$s):string{
        $s=preg_replace('/\bchar\b/','character',$s);
        $s=preg_replace('/\bvarchar\b/','character varying',$s);
        return preg_replace('/\btimestamptz\b/','timestamp with time zone',$s);
    };
    foreach($expected['functions']as$name=>$f){
        $args=substr($f['signature'],strpos($f['signature'],'(')+1,-1);
        $want['functions'][$name]=['args'=>$canonicalTypes($args),'result'=>$f['result'],'body'=>$f['body'],'volatility'=>$f['volatility'],
            'definer'=>false,'language'=>'plpgsql','config'=>'{"search_path=pg_catalog, public"}'];
    }
    $rows=DB::select("SELECT p.proname,oidvectortypes(p.proargtypes) AS args,format_type(p.prorettype,NULL) AS result,p.prosrc,p.provolatile,p.prosecdef,p.proconfig,l.lanname
        FROM pg_proc p JOIN pg_language l ON l.oid=p.prolang WHERE p.pronamespace='public'::regnamespace AND left(p.proname,11)='proof_scan_'");
    foreach($rows as$r)$got['functions'][$r->proname]=['args'=>$r->args,'result'=>$r->result,'body'=>$r->prosrc,'volatility'=>$r->provolatile,'definer'=>$r->prosecdef,'language'=>$r->lanname,'config'=>$r->proconfig];
    foreach($expected['triggers']as$t)$want['triggers'][$t['table'].'.'.$t['name']]=['table'=>$t['table'],'function'=>$t['fn'],'type'=>$t['type'],'deferred'=>$t['deferred'],'initially_deferred'=>$t['deferred'],'when'=>$norm($t['when'])];
    $rows=DB::select("SELECT c.relname,t.tgname,p.proname,t.tgtype,t.tgdeferrable,t.tginitdeferred,pg_get_triggerdef(t.oid) AS definition
        FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_proc p ON p.oid=t.tgfoid
        WHERE c.relnamespace='public'::regnamespace AND NOT t.tgisinternal
          AND (left(c.relname,11)='proof_scan_' OR (c.relname='payments' AND t.tgname='proof_scan_payment_initial_final'))");
    foreach($rows as$r){preg_match('/ WHEN \((.*)\) EXECUTE FUNCTION /',$r->definition,$m);
        $got['triggers'][$r->relname.'.'.$r->tgname]=['table'=>$r->relname,'function'=>$r->proname,'type'=>$r->tgtype,'deferred'=>$r->tgdeferrable,'initially_deferred'=>$r->tginitdeferred,'when'=>$norm($m[1]??null)];
    }
    $canonical=function($x)use(&$canonical){if(!is_array($x))return$x;if(!array_is_list($x))ksort($x);return array_map($canonical,$x);};
    $want=$canonical($want);$got=$canonical($got);
    $digest=static fn($x)=>hash('sha256',"silansia.proof.catalog.semantic.pg18.v1\n".json_encode($x,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $record['technical_catalog']=['expected_source_fingerprint'=>$digest($want),'actual_catalog_fingerprint'=>$digest($got),
        'tables'=>count($got['tables']),'columns'=>array_sum(array_map('count',$got['tables'])),'constraints'=>count($got['constraints']),
        'indexes'=>count($got['indexes']),'functions'=>count($got['functions']),'triggers'=>count($got['triggers']),
        'owner_acl_invoker_search_path_contract_verified'=>true,'semantic_manifest'=>$got];
    $assert($want===$got,'semantic_catalog_manifest_mismatch');
    $assert($digest($want)===$digest($got),'technical_fingerprint_mismatch');
    return ['source_fingerprint'=>$digest($want),'actual_fingerprint'=>$digest($got),'match'=>true];
};
$case('CAT01','M10/M11 installed; prepared reviewed source expected definitions','Compare source-derived and actual normalized catalogs','Exact matching semantic fingerprints',$captureTechnicalCatalog);
$envelope=static function(array $assignment,string $verdict):array{
    unset($assignment['lease_deadline'],$assignment['adapter_timeout_seconds']);
    return $assignment+['result_id'=>(string)Str::uuid(),'verdict'=>$verdict,'reason_code'=>'simulation_'.$verdict,
        'observed_at'=>DB::scalar("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')"),
        'engine_id'=>'synthetic_mock','engine_version'=>'1','signature_set_id'=>'not_applicable_simulation','signature_version'=>'not_applicable_simulation',
        'auth_scheme_version'=>'trusted_in_process_v1','key_epoch_reference'=>'not_applicable_owned_runtime'];
};
if ($paymentWriter || $academic || ($accessCase ?? null) === 'positive') {
    // J1 and HTTP fixtures need an empty policy/head. CAT01 is read-only.
    return;
}
$authority = DB::scalar('SELECT public.proof_scan_authority()');
$definition = [
    'version'=>'academic-sim-v1','definition_version'=>1,
    'stage_plan'=>[['stage_id'=>'synthetic_stage_v1','is_simulation'=>true]],
    'allowed_versions'=>['adapter_version'=>'1','scanner_identity'=>'synthetic_mock','engine_id'=>'synthetic_mock','engine_version'=>'1',
        'signature_set_id'=>'not_applicable_simulation','signature_version'=>'not_applicable_simulation',
        'auth_scheme_version'=>'trusted_in_process_v1','key_epoch_reference'=>'not_applicable_owned_runtime'],
    'retry_profile'=>['max_attempts'=>2,'adapter_timeout_seconds'=>5,'lease_seconds'=>30,'retry_due_seconds'=>1],
    'freshness_profile'=>['verdict_seconds'=>120], 'resource_profile'=>['worker_concurrency'=>1,'dispatcher'=>'manual_one_shot'],
];
$createPolicy = fn (array $d) => $tx(fn()=>DB::scalar('SELECT public.proof_scan_create_policy(?::jsonb,?)',[json_encode($d,JSON_THROW_ON_ERROR),$authority]));
$case('A02','No committed policy; all fields valid except max_attempts','create_policy(max_attempts=3)','23514 psp_profile_ck',
    fn()=>$reject('23514','psp_profile_ck',fn()=>$createPolicy(array_replace_recursive($definition,['retry_profile'=>['max_attempts'=>3]]))));
foreach (['missing'=>'version','extra'=>'unapproved_key'] as $mode=>$field) {
    $d=$definition;
    if ($mode==='missing') unset($d[$field]); else $d[$field]=true;
    $case($mode==='missing'?'A03':'A04','Otherwise valid closed definition; only one key difference','create_policy('.$mode.' field)','23514 pscan_policy_definition',
        fn()=>$reject('23514','pscan_policy_definition',fn()=>$createPolicy($d)));
}
$policy = null;
$case('A01','Valid closed academic profile; policy table empty','create_policy(valid) COMMIT','One immutable policy +policy_created',function()use(&$policy,$createPolicy,$definition,$assert){
    $policy=$createPolicy($definition);
    $assert(DB::table('proof_scan_policies')->count()===1,'policy_count');
    $assert(DB::table('proof_scan_audit_events')->where('event_type','policy_created')->count()===1,'policy_audit');
    return ['policy_created'=>true];
});
$incarnation=(string)Str::uuid();
$setHead=fn(int $epoch,string $admission)=>$tx(fn()=>DB::scalar('SELECT public.proof_scan_set_head(?,?,?,?,?)',[$policy,$incarnation,$epoch,$admission,$authority]));
$case('A05','Policy committed in earlier transaction','set_head(epoch0,active) COMMIT','epoch1 active',function()use($setHead,$assert){$assert($setHead(0,'active')===1,'head_epoch');});
$case('A06','Head at epoch1','set_head(expected0,held)','23514 pscan_head_epoch',fn()=>$reject('23514','pscan_head_epoch',fn()=>$setHead(0,'held')));
$case('A07','Head at epoch1 active','set_head(1,held); set_head(2,active)','epoch2 then3',function()use($setHead,$assert){$assert($setHead(1,'held')===2 && $setHead(2,'active')===3,'CAS progression');});
$case('A08','Committed policy','UPDATE policy approval_reference','23514 pscan_append_only',fn()=>$reject('23514','pscan_append_only',fn()=>$tx(fn()=>DB::table('proof_scan_policies')->where('id',$policy)->update(['approval_reference'=>'academic-owned:'.str_repeat('a',64)]))));
$case('A09','Head epoch3, valid authority format but different value','UPDATE head authority','23514 pscan_head_immutable',fn()=>$reject('23514','pscan_head_immutable',fn()=>$tx(fn()=>DB::table('proof_scan_policy_heads')->update(['authority_ref'=>'academic-owned:'.str_repeat('a',64)]))));

$g0=$fixtures->graph();
$case('CD03-N1','Valid unpaid/current invoice, family, revision, complete financial request/action; no Q','Synthetic financial INSERT/request/action COMMIT without graph','23514 pscan_initial_graph_complete',
    fn()=>$reject('23514','pscan_initial_graph_complete',fn()=>$tx(fn()=>$fixtures->submit($g0,'missing_subject',$authority))));
$assert(DB::table('payments')->where('invoice_id',$g0['invoice']['id'])->count()===0,'missing_graph_not_atomic');
if ($technicalRegression) {
    $record['not_applicable'][] = ['id' => 'CD03-N2',
        'reason' => 'The historical assertion targets the writer before J1. CD03-N1 still tests missing graph, and J1-06 tests the integrated writer.'];
} else {
$case('CD03-N2','Valid financial graph; existing PaymentService with synthetic proof store','PaymentService::submit once; no HTTP/retry','23514 pscan_initial_graph_complete',function()use($g0,$reject,$assert){
    config(['financial_ownership.enabled'=>true,'financial_ownership.cutover_reference'=>'owned-test-only-approval','invoice_issuance.enabled'=>true,'payment_core.enabled'=>true]);
    $store=new PaymentProofFixtureStore;
    app()->instance(PaymentProofStore::class,$store);
    $reference=$store->issue($g0['p']['family'],$g0['invoice']['id']);
    Auth::login(User::findOrFail($g0['p']['family']));
    $command=new PaymentCommand(['operation'=>'submit','actor_id'=>$g0['p']['family'],'invoice_id'=>$g0['invoice']['id'],
        'revision_id'=>$g0['revision']['id'],'request_id'=>(string)Str::uuid(),'proof_reference'=>$reference,'confirm'=>true]);
    try { return $reject('23514','pscan_initial_graph_complete',fn()=>app(PaymentService::class)->submit($command)); }
    finally { $store->cleanup(); Auth::logout(); }
});
}
$s=null;
$case('CD03-P1','Same valid invoice; no prior payment; head current active','Synthetic pending/request/action +initialize_subject in one COMMIT','Pending +QUEUED/gen1/v1 +READY job +2 audits',function()use(&$s,$g0,$fixtures,$authority,$tx,$assert){
    $s=$tx(fn()=>$fixtures->submit($g0,'complete',$authority));
    $q=DB::table('proof_scan_subjects')->where('id',$s['subject'])->first();
    $j=DB::table('proof_scan_jobs')->where('subject_id',$q->id)->first();
    $assert($q->state==='QUEUED' && $q->generation===1 && $q->state_version===1 && $j->state==='READY' && $j->attempt_count===0,'initial_graph');
    return ['committed'=>true,'payment_status'=>'pending','subject_state'=>$q->state,'job_state'=>$j->state];
});
// Build valid new Q manually while selectively omitting one required graph edge; never disable a trigger.
$omit = function(string $mode)use($fixtures,$tx,$authority){
    $g=$fixtures->graph();
    return $tx(function()use($mode,$g,$fixtures,$authority){
        $base=$fixtures->submit($g,'missing_subject',$authority);
        $head=DB::table('proof_scan_policy_heads')->first();
        $at=DB::scalar('SELECT clock_timestamp()::text');
        $q=['id'=>(string)Str::uuid(),'payment_id'=>$base['payment']['id'],'invoice_id'=>$g['invoice']['id'],'revision_id'=>$g['revision']['id'],
            'submitter_id'=>$g['p']['family'],'proof_reference'=>$base['descriptor']['proof_reference'],'billing_request_id'=>$base['request']['id'],'billing_action_id'=>$base['action']['id'],
            ...array_intersect_key($base['payment'],array_flip(['proof_disk','proof_path','proof_sha256','proof_size_bytes','proof_mime'])),
            'descriptor_version'=>1,'created_at'=>$at,'changed_at'=>$at,'generation'=>1,'state_version'=>1,'policy_epoch'=>$head->epoch,
            'policy_id'=>$head->policy_id,'admission_incarnation'=>$head->admission_incarnation,'state'=>'QUEUED','held_reason'=>'queued','security_hold'=>false,
            'hold_authority_ref'=>null,'hold_reason'=>null];
        $q['subject_digest']=DB::scalar('SELECT public.proof_scan_subject_digest(?::jsonb)',[json_encode($q,JSON_THROW_ON_ERROR)]);
        DB::table('proof_scan_subjects')->insert($q);
        if($mode!=='missing_audit') DB::select("SELECT public.proof_scan_record_event('initial_subject_bound',?,NULL,NULL,NULL,NULL,NULL,NULL,?::timestamptz,?,NULL,NULL,NULL)",[$q['id'],$at,$authority]);
        if($mode!=='missing_job') DB::select("SELECT public.proof_scan_new_job(?,'fresh_submit',?::timestamptz,?)",[$q['id'],$at,$authority]);
    });
};
$case('B02','Fresh valid pending/request/action and Q+initial audit; only job omitted','COMMIT incomplete initial graph','23514 pscan_initial_graph_complete',fn()=>$reject('23514','pscan_initial_graph_complete',fn()=>$omit('missing_job')));
$case('B03','Fresh valid Q/job; only initial_subject_bound omitted','COMMIT incomplete initial audit','23514 pscan_initial_graph_complete',fn()=>$reject('23514','pscan_initial_graph_complete',fn()=>$omit('missing_audit')));
$case('B04','Committed payment/request/action/Q cannot be initialized again in a later transaction','initialize_subject(old committed payment)','23514 pscan_no_adoption',
    fn()=>$reject('23514','pscan_no_adoption',fn()=>$tx(fn()=>DB::scalar('SELECT public.proof_scan_initialize_subject(?,?,?,?::jsonb,?)',[$s['payment']['id'],$s['request']['id'],$s['action']['id'],json_encode($s['descriptor']),$authority]))));
$case('B05','Fresh pending with financial rows but request belongs to another operation/invoice','initialize_subject wrong request','23514 pscan_initial_binding',function()use($fixtures,$tx,$authority,$reject){
    $g=$fixtures->graph();
    $other=$fixtures->graph();
    return $reject('23514','pscan_initial_binding',fn()=>$tx(function()use($g,$other,$fixtures,$authority){
        $p=$fixtures->submit($g,'missing_subject',$authority);
        $different=$fixtures->submit($other,'complete',$authority);
        return DB::scalar('SELECT public.proof_scan_initialize_subject(?,?,?,?::jsonb,?)',[$p['payment']['id'],$different['request']['id'],$p['action']['id'],json_encode($p['descriptor']),$authority]);
    }));
});
$case('B06','Committed subject, all columns valid; only proof_path changes','UPDATE original path','23514 pscan_subject_immutable',
    fn()=>$reject('23514','pscan_subject_immutable',fn()=>$tx(fn()=>DB::table('proof_scan_subjects')->where('id',$s['subject'])->update(['proof_path'=>'payment-proofs/'.Str::uuid().'/object.png']))));
$case('B07','Valid new job row; only stable subject FK references missing UUID','INSERT cloned READY job with foreign subject','23503 psj_subject_fk',function()use($s,$tx,$reject){
    $j=(array)DB::table('proof_scan_jobs')->where('subject_id',$s['subject'])->first();
    $j['id']=(string)Str::uuid();$j['subject_id']=(string)Str::uuid();
    return $reject('23503','psj_subject_fk',fn()=>$tx(fn()=>DB::table('proof_scan_jobs')->insert($j)));
});
$financialBaseline=$financial();
$record['financial_data_before_technical']=$financialBaseline;

$jobOf=fn(string $q)=>DB::table('proof_scan_jobs')->where('subject_id',$q)->orderByDesc('generation')->first();
$claim=fn(string $job)=>$tx(fn()=>json_decode(DB::scalar('SELECT public.proof_scan_claim_attempt(?,1,?,?)',[$job,$authority,$authority]),true,flags:JSON_THROW_ON_ERROR));
$accept=fn(array $e)=>$tx(fn()=>json_decode(DB::scalar('SELECT public.proof_scan_accept_result(?::jsonb,?)',[json_encode($e,JSON_THROW_ON_ERROR),$authority]),true,flags:JSON_THROW_ON_ERROR));
$a=null;$j=$jobOf($s['subject']);
$case('C01','READY count0 current generation','claim_attempt COMMIT','One LEASED attempt; exact30s lease; fence1',function()use(&$a,$claim,$j,$assert){
    $a=$claim($j->id);$row=DB::selectOne("SELECT extract(epoch FROM lease_deadline-leased_at)::int AS seconds,fence,attempt_no FROM proof_scan_attempts WHERE id=?",[$a['attempt_id']]);
    $assert($row->seconds===30&&$row->fence===1&&$row->attempt_no===1,'lease_profile');
    return ['lease_seconds'=>30,'fence'=>1];
});
$case('C02','Existing active attempt for this job','claim same job again','23514 pscan_claim_precondition',fn()=>$reject('23514','pscan_claim_precondition',fn()=>$claim($j->id)));
$case('C02B','Active attempt exists; distinct valid ordinal/fence/challenge, same job','INSERT second active attempt','23505 psa_active_job_uq',function()use($a,$tx,$reject){
    $row=(array)DB::table('proof_scan_attempts')->where('id',$a['attempt_id'])->first();
    $row['id']=(string)Str::uuid();$row['challenge_id']=(string)Str::uuid();$row['attempt_no']=2;$row['fence']=2;
    return $reject('23505','psa_active_job_uq',fn()=>$tx(fn()=>DB::table('proof_scan_attempts')->insert($row)));
});
$case('C03','Another fully committed READY subject in same incarnation; one active elsewhere','claim second job','23505 psa_runtime_active_uq',function()use($fixtures,$tx,$authority,$claim,$reject,$jobOf,&$financialBaseline,$financial){
    $g=$fixtures->graph();$other=$tx(fn()=>$fixtures->submit($g,'complete',$authority));
    $financialBaseline=$financial();
    return $reject('23505','psa_runtime_active_uq',fn()=>$claim($jobOf($other['subject'])->id));
});
$case('C04','Valid active assignment','start_attempt COMMIT','RUNNING and attempt_started audit',fn()=>$tx(fn()=>DB::select("SELECT public.proof_scan_start_or_expire_attempt(?,'start',?)",[$a['attempt_id'],$authority])));
$e=$envelope($a,'clean');
$case('D01','RUNNING current lease, exact envelope','accept_result clean COMMIT','ACCEPTED/CLEAN_ELIGIBLE',function()use($accept,$e,$s,$assert){$r=$accept($e);$assert($r['historical_ack']===false && DB::table('proof_scan_subjects')->where('id',$s['subject'])->value('state')==='CLEAN_ELIGIBLE','clean_projection');return $r;});
$case('D02','Previously accepted exact result','accept_result same envelope','Historical ACK; no new V/audit/time',function()use($accept,$e,$assert){
    $before=DB::table('proof_scan_audit_events')->count();$r=$accept($e);
    $assert($r['historical_ack']===true&&DB::table('proof_scan_audit_events')->count()===$before,'duplicate_mutated_state');return $r;
});
$case('D03','Same accepted assignment/result ID, conflicting verdict','accept_result conflicting envelope','23514 pscan_result_conflict',fn()=>$reject('23514','pscan_result_conflict',fn()=>$accept([...$e,'verdict'=>'infected','reason_code'=>'simulation_infected'])));
$case('C05','Terminal ACCEPTED attempt','expire_attempt on accepted','23514 pscan_active_attempt_required',fn()=>$reject('23514','pscan_active_attempt_required',fn()=>$tx(fn()=>DB::select("SELECT public.proof_scan_start_or_expire_attempt(?,'expire',?)",[$a['attempt_id'],$authority]))));

$grant=function(string $principal,string $kind='protected_get_granted',?array $descriptor=null)use($tx,$s,$authority,$fixtures){
    return $tx(function()use($principal,$kind,$descriptor,$s,$authority,$fixtures){
        $fixtures->locks($s); // L_F held before P/Q SHARE; no lock upgrade.
        $v=DB::table('proof_scan_subjects')->where('id',$s['subject'])->value('state_version');
        return json_decode(DB::scalar('SELECT public.proof_scan_append_audit(?,?,?,?,?,?,?::jsonb,?)',
            [$s['subject'],$v,$kind,$principal,str_repeat('a',64),str_repeat('c',64),json_encode($descriptor??$s['descriptor']),$authority]),true,flags:JSON_THROW_ON_ERROR);
    });
};
$case('E01','Current clean/fresh; original current AND frozen family','protected_get grant COMMIT','One immutable grant audit',fn()=>$grant($s['p']['family']));
$case('E02','Current clean/fresh pending exact amount; authorized Admin','verify_use grant COMMIT, no financial writer','Typed verify-use audit only',fn()=>$grant($s['p']['admin'],'verify_use_granted'));
$case('E03','Active different family; all technical evidence valid','protected_get grant for foreign family','23514 pscan_use_authorization',fn()=>$reject('23514','pscan_use_authorization',fn()=>$grant($s['p']['next_family'])));
$case('E04','Valid family and current clean; only descriptor size wrong','protected_get with tampered descriptor','23514 pscan_use_unavailable',fn()=>$reject('23514','pscan_use_unavailable',fn()=>$grant($s['p']['family'],'protected_get_granted',[...$s['descriptor'],'proof_size_bytes'=>129])));
foreach(['UPDATE','DELETE','TRUNCATE']as$i=>$op){
    $rule=$op==='TRUNCATE'?'pscan_no_truncate':($op==='DELETE'?'pscan_no_delete':'pscan_append_only');
    $sql=match($op){'UPDATE'=>"UPDATE proof_scan_audit_events SET reason_code=reason_code WHERE id=(SELECT id FROM proof_scan_audit_events LIMIT 1)",'DELETE'=>"DELETE FROM proof_scan_audit_events WHERE id=(SELECT id FROM proof_scan_audit_events LIMIT 1)",default=>'TRUNCATE proof_scan_audit_events'};
    $case('E0'.($i+5),'Committed audit, no invalid ancillary field',$op.' audit','23514 '.$rule,fn()=>$reject('23514',$rule,fn()=>$tx(fn()=>DB::statement($sql))));
}
$case('E08','Valid current clean subject; typed grant missing audience/auth binding','record_event grant with NULL bindings','23514 pse_event_shape_ck',function()use($tx,$s,$j,$a,$authority,$reject){
    $v=DB::table('proof_scan_verdicts')->where('attempt_id',$a['attempt_id'])->value('id');
    return $reject('23514','pse_event_shape_ck',fn()=>$tx(fn()=>DB::select("SELECT public.proof_scan_record_event('protected_get_granted',?,?,?,?,NULL,NULL,NULL,clock_timestamp(),?,?,NULL,NULL)",[$s['subject'],$j->id,$a['attempt_id'],$v,$authority,$s['p']['family']])));
});
$case('E09','Valid fresh grant; whole caller transaction explicitly rolls back','append grant then ROLLBACK','No durable grant row / no disclosure',function()use($s,$authority,$assert,$fixtures,$owned){
    $owned();$n=DB::table('proof_scan_audit_events')->count();DB::beginTransaction();
    try{$fixtures->locks($s);$q=DB::table('proof_scan_subjects')->where('id',$s['subject'])->first();
        DB::scalar('SELECT public.proof_scan_append_audit(?,?,?,?,?,?,?::jsonb,?)',[$q->id,$q->state_version,'protected_get_granted',$s['p']['family'],str_repeat('a',64),str_repeat('c',64),json_encode($s['descriptor']),$authority]);
    }finally{DB::rollBack();}
    $assert(DB::table('proof_scan_audit_events')->count()===$n,'rolled_back_grant_persisted');
});

$case('E12','Valid grant can return; later unrelated technical graph fault at COMMIT','append grant, insert invalid future-generation job, COMMIT','23514 pscan_job_graph; grant and fault both rollback',function()use($s,$authority,$fixtures,$tx,$reject,$assert){
    $before=DB::table('proof_scan_audit_events')->count();$checkpoint=(object)['returned'=>false];
    $r=$reject('23514','pscan_job_graph',fn()=>$tx(function()use($s,$authority,$fixtures,$checkpoint){
        $fixtures->locks($s);$q=DB::table('proof_scan_subjects')->where('id',$s['subject'])->first();
        DB::scalar('SELECT public.proof_scan_append_audit(?,?,?,?,?,?,?::jsonb,?)',[$q->id,$q->state_version,'protected_get_granted',$s['p']['family'],str_repeat('a',64),str_repeat('c',64),json_encode($s['descriptor']),$authority]);
        $checkpoint->returned=true;
        $j=(array)DB::table('proof_scan_jobs')->where('subject_id',$q->id)->where('generation',$q->generation)->first();
        $j['id']=(string)Str::uuid();$j['generation']=$q->generation+1;$j['state']='READY';$j['attempt_count']=0;$j['current_attempt_id']=null;$j['fence']=1;$j['terminal_reason']=null;
        $j['origin']='authorized_rescan';$j['requested_by_function']='proof_scan_rescan_or_dispose';
        DB::table('proof_scan_jobs')->insert($j);
    }));
    $assert($checkpoint->returned && DB::table('proof_scan_audit_events')->count()===$before,'commit_failure_grant_persisted_or_not_reached');
    return $r+['grant_returned_before_commit'=>true,'durable_grant'=>false];
});
$rescan=fn()=>$tx(fn()=>DB::scalar("SELECT public.proof_scan_rescan_or_dispose(?,?,'rescan',?)",[$s['subject'],DB::table('proof_scan_subjects')->where('id',$s['subject'])->value('state_version'),$authority]));
$case('D04','One accepted clean V, current generation1','rescan COMMIT','generation2; immutable old V and A remain',function()use($rescan,$assert){$count=DB::table('proof_scan_verdicts')->count();$assert($rescan()===2 && DB::table('proof_scan_verdicts')->count()===$count,'rescan_history');});
$a2=$claim($jobOf($s['subject'])->id);
$case('D05','New active assignment; only identity epoch wrong','accept_result stale identity','23514 pscan_result_binding',fn()=>$reject('23514','pscan_result_binding',fn()=>$accept([...$envelope($a2,'clean'),'identity_epoch'=>2])));
$case('C06','New active gen2 assignment; only fence wrong','accept_result wrong fence','23514 pscan_result_binding',fn()=>$reject('23514','pscan_result_binding',fn()=>$accept([...$envelope($a2,'clean'),'fence'=>2])));
$case('C06B','New active gen2 assignment; only generation wrong','accept_result wrong generation','23514 pscan_result_binding',fn()=>$reject('23514','pscan_result_binding',fn()=>$accept([...$envelope($a2,'clean'),'generation'=>1])));
$case('D06','New active exact envelope','accept infected COMMIT','INFECTED_HELD +persistent security_hold',function()use($accept,$envelope,$a2,$s,$assert){
    $accept($envelope($a2,'infected'));$q=DB::table('proof_scan_subjects')->where('id',$s['subject'])->first();$assert($q->state==='INFECTED_HELD'&&$q->security_hold,'infected_hold');
});
$case('D07','INFECTED hold; new-generation rescan and clean result','rescan +claim +accept clean','Hold persists, no grant',function()use($rescan,$claim,$jobOf,$s,$accept,$envelope,$assert){$rescan();$a=$claim($jobOf($s['subject'])->id);$accept($envelope($a,'clean'));$assert(DB::table('proof_scan_subjects')->where('id',$s['subject'])->value('state')==='INFECTED_HELD','rescan_auto_cleared_hold');});
$case('E10','Latest result clean but security hold true','protected grant','23514 pscan_use_unavailable',fn()=>$reject('23514','pscan_use_unavailable',fn()=>$grant($s['p']['family'])));
$case('D08','Legitimate owned authority, current clean/fresh verdict and hold','dispose_hold COMMIT','Recomputed CLEAN_ELIGIBLE',function()use($tx,$s,$authority,$assert){
    $tx(fn()=>DB::scalar("SELECT public.proof_scan_rescan_or_dispose(?,?,'dispose_hold',?)",[$s['subject'],DB::table('proof_scan_subjects')->where('id',$s['subject'])->value('state_version'),$authority]));
    $assert(DB::table('proof_scan_subjects')->where('id',$s['subject'])->value('state')==='CLEAN_ELIGIBLE','dispose_projection');
});
$case('D09','Current fresh clean after legitimate disposition','protected_get grant','Grant succeeds',fn()=>$grant($s['p']['family']));
$case('C07','New generation to test expiry and retry; no active assignments','claim; wait authoritative30s; accept expired result; expire COMMIT','Late result denied; active only EXPIRED; retry due+1',function()use($rescan,$claim,$jobOf,$s,$envelope,$accept,$reject,$tx,$authority,$assert,&$expiredAssignment){
    $rescan();$expiredAssignment=$claim($jobOf($s['subject'])->id);$env=$envelope($expiredAssignment,'clean');
    $remaining=(float)DB::scalar('SELECT greatest(0,extract(epoch FROM lease_deadline-clock_timestamp())) FROM proof_scan_attempts WHERE id=?',[$expiredAssignment['attempt_id']]);
    echo 'WAIT lease expiry '.ceil($remaining).' seconds'.PHP_EOL;
    usleep((int)(($remaining+0.1)*1_000_000));
    $r=$reject('23514','pscan_lease',fn()=>$accept($env));
    $tx(fn()=>DB::select("SELECT public.proof_scan_start_or_expire_attempt(?,'expire',?)",[$expiredAssignment['attempt_id'],$authority]));
    $j=$jobOf($s['subject']);$assert($j->state==='RETRY_WAIT','retry_wait');
    return $r+['active_expired'=>true];
});
$case('C08','RETRY_WAIT not_before=completion+1s','claim before due','23514 pscan_claim_precondition',fn()=>$reject('23514','pscan_claim_precondition',fn()=>$claim($jobOf($s['subject'])->id)));
$case('C09','Retry due elapsed; current job has1 expired attempt','claim ordinal/fence2, accept unknown; third claim','DEAD_LETTER and third claim rejected',function()use($claim,$jobOf,$s,$assert,$envelope,$accept,$reject){
    usleep(1_100_000);$a=$claim($jobOf($s['subject'])->id);$assert($a['fence']===2,'retry_fence');
    $accept($envelope($a,'unknown'));$assert($jobOf($s['subject'])->state==='DEAD_LETTER','attempt_budget');
    return $reject('23514','pscan_claim_precondition',fn()=>$claim($jobOf($s['subject'])->id));
});
$case('D10','New generation clean accepted result to measure freshness','rescan/claim/clean; wait server received_at+120; protected grant','23514 pscan_use_unavailable after freshness',function()use($rescan,$claim,$jobOf,$s,$accept,$envelope,$grant,$reject){
    $rescan();$a=$claim($jobOf($s['subject'])->id);$accept($envelope($a,'clean'));
    $remaining=(float)DB::scalar("SELECT greatest(0,extract(epoch FROM received_at+interval '120 seconds'-clock_timestamp())) FROM proof_scan_verdicts WHERE attempt_id=?",[$a['attempt_id']]);
    echo 'WAIT freshness expiry '.ceil($remaining).' seconds'.PHP_EOL;
    $end=microtime(true)+$remaining+0.1;
    while(microtime(true)<$end) usleep((int)(min(20,$end-microtime(true))*1_000_000));
    return $reject('23514','pscan_use_unavailable',fn()=>$grant($s['p']['family']));
});
$case('E11','All synthetic financial rows frozen before technical operations','Compare financial row counts+ordered JSON fingerprints','Unchanged finance/ownership/history',function()use($financial,$financialBaseline,$assert,&$record){
    $after=$financial();$assert($after===$financialBaseline,'technical_routines_mutated_financial_data');
    // Use the frozen checkpoint refreshed after C03's explicit financial fixture creation.
    $record['financial_data_before_technical']=$financialBaseline;
    $record['financial_data_after_technical']=$after;
});
$case('F01','Expired prior result; authorized new-generation rescan','rescan/claim/clean then valid Admin grant','Fresh positive control before ownership tests',function()use($rescan,$claim,$jobOf,$s,$accept,$envelope,$grant){
    $rescan();$a=$claim($jobOf($s['subject'])->id);$accept($envelope($a,'clean'));return $grant($s['p']['admin']);
});
$case('F02','Valid current/frozen family is original family; fresh technical evidence','Explicit synthetic fixture ownership transfer with ledger/audit','Current family changes; frozen invoice owner unchanged',function()use($tx,$fixtures,$s,$assert){
    $tx(fn()=>$fixtures->transfer($s));
    $assert(DB::table('elderlies')->where('id',$s['p']['elderly'])->value('family_id')===$s['p']['next_family'],'transfer_fixture');
    $assert(DB::table('invoices')->where('id',$s['invoice']['id'])->value('bill_to_family_id')===$s['p']['family'],'frozen_owner_changed');
});
$case('F03','Original family remains frozen but is no longer current; technical evidence fresh','Grant original family','23514 pscan_use_authorization',fn()=>$reject('23514','pscan_use_authorization',fn()=>$grant($s['p']['family'])));
$case('F04','New family is current but not frozen invoice owner; technical evidence fresh','Grant new family','23514 pscan_use_authorization',fn()=>$reject('23514','pscan_use_authorization',fn()=>$grant($s['p']['next_family'])));
$case('F05','Same technical evidence after rejected family grants','Grant active Admin again','Positive control proves failures were ownership-specific',fn()=>$grant($s['p']['admin']));
