<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;

final class InvoiceSchemaContract
{
    // Pinned dari schema A/B asli pada runner owned; bukan belajar dari DB saat runtime.
    private const SCHEMA_SHA256 = '17c0f079263ed8fc89faf09acc661c52beee1447c9c75f2c1c1ea12e861048c0';

    private const TABLES = ['users', 'elderlies', 'service_enrollments', 'invoices', 'payments',
        'financial_ownership_periods', 'invoice_revisions', 'billing_requests', 'billing_actions'];

    public function fingerprint(): string
    {
        $names = implode(',', array_fill(0, count(self::TABLES), '?'));
        $columns = DB::select("SELECT c.relname,a.attname,pg_catalog.format_type(a.atttypid,a.atttypmod) AS type,
            a.attnotnull,pg_get_expr(d.adbin,d.adrelid) AS default_expression
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace JOIN pg_attribute a ON a.attrelid=c.oid
            LEFT JOIN pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum
            WHERE n.nspname='public' AND c.relname IN ({$names}) AND a.attnum>0 AND NOT a.attisdropped
            ORDER BY c.relname,a.attname", self::TABLES);
        $constraints = DB::select("SELECT c.relname,p.conname,p.contype,p.condeferrable,p.condeferred,p.convalidated,
            pg_get_constraintdef(p.oid,true) AS definition FROM pg_constraint p JOIN pg_class c ON c.oid=p.conrelid
            WHERE c.relnamespace='public'::regnamespace AND c.relname IN ({$names})
                AND (p.contype <> 't' OR p.conname ~ '^billing_(invoice|payment|period|revision|request|action)_final$')
            ORDER BY c.relname,p.conname", self::TABLES);
        $indexes = DB::select("SELECT t.relname,i.relname AS name,x.indisvalid,x.indisready,pg_get_indexdef(i.oid) AS definition
            FROM pg_index x JOIN pg_class t ON t.oid=x.indrelid JOIN pg_class i ON i.oid=x.indexrelid
            WHERE t.relnamespace='public'::regnamespace AND t.relname IN ({$names}) ORDER BY t.relname,i.relname", self::TABLES);

        return InvoiceCanonical::digest('silansia.invoice.schema.pg18.v1', [$columns, $constraints, $indexes]);
    }

    public function assertReady(): void
    {
        if ($this->fingerprint() !== self::SCHEMA_SHA256) {
            throw new InvoiceIssuanceFailure('schema_unavailable');
        }
        $path = database_path('migrations/2026_11_13_000009_add_billing_integrity_guards.php');
        $source = str_replace("\r\n", "\n", file_get_contents($path));
        if (hash('sha256', $source) !== '8c29cdf61723cefedfd47a3d468cd28059206b285db8289be538e81b4a80d6c0') {
            throw new InvoiceIssuanceFailure('guard_source_mismatch');
        }
        preg_match_all('/CREATE FUNCTION public\.(billing_[a-z_]+)\((.*?)\) RETURNS ([^\n]+)\n\s*LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path = pg_catalog, public AS \$fn\$(.*?)\$fn\$;/s', $source, $matches, PREG_SET_ORDER);
        if (count($matches) !== 12) {
            throw new InvoiceIssuanceFailure('guard_unavailable');
        }
        foreach ($matches as $match) {
            $signature = $match[1].'('.preg_replace('/[a-z_]+ (text|uuid)/', '$1', $match[2]).')';
            $row = DB::selectOne('SELECT p.prosrc,p.prosecdef,p.provolatile,p.proconfig,p.prokind,p.proisstrict,p.proleakproof,p.proparallel,l.lanname,
                p.prorettype::regtype::text AS result FROM pg_proc p JOIN pg_language l ON l.oid=p.prolang
                WHERE p.oid=to_regprocedure(?)', ['public.'.$signature]);
            $body = preg_replace('/^ {16}/m', '', $match[4]);
            $expectedType = str_replace('public.', '', $match[3]);
            if (! $row || $row->prosrc !== $body || $row->prosecdef || $row->provolatile !== 'v'
                || $row->proconfig !== '{"search_path=pg_catalog, public"}' || $row->lanname !== 'plpgsql'
                || $row->prokind !== 'f' || $row->proisstrict || $row->proleakproof || $row->proparallel !== 'u'
                || $row->result !== $expectedType) {
                throw new InvoiceIssuanceFailure('guard_definition_mismatch');
            }
        }
        foreach (['invoices' => 'invoice', 'payments' => 'payment', 'financial_ownership_periods' => 'period',
            'invoice_revisions' => 'revision', 'billing_requests' => 'request', 'billing_actions' => 'action'] as $table => $prefix) {
            foreach (['write', 'final', 'truncate'] as $kind) {
                $row = DB::selectOne('SELECT t.tgenabled,t.tgdeferrable,t.tginitdeferred,pg_get_triggerdef(t.oid) AS definition
                    FROM pg_trigger t WHERE t.tgrelid=?::regclass AND t.tgname=? AND NOT t.tgisinternal',
                    ['public.'.$table, 'billing_'.$prefix.'_'.$kind]);
                $when = in_array($table, ['invoices', 'payments'], true) ? ' WHEN ((new.billing_schema_version = 1))' : '';
                $expected = match ($kind) {
                    'write' => "CREATE TRIGGER billing_{$prefix}_write BEFORE INSERT OR DELETE OR UPDATE ON public.{$table} FOR EACH ROW EXECUTE FUNCTION billing_guard_{$prefix}()",
                    'truncate' => "CREATE TRIGGER billing_{$prefix}_truncate BEFORE TRUNCATE ON public.{$table} FOR EACH STATEMENT EXECUTE FUNCTION billing_guard_truncate()",
                    'final' => "CREATE CONSTRAINT TRIGGER billing_{$prefix}_final AFTER INSERT OR UPDATE ON public.{$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW{$when} EXECUTE FUNCTION billing_deferred_check()",
                };
                if (! $row || ! in_array($row->tgenabled, ['O', 'A'], true) || $row->definition !== $expected
                    || $row->tgdeferrable !== ($kind === 'final') || $row->tginitdeferred !== ($kind === 'final')) {
                    throw new InvoiceIssuanceFailure('guard_definition_mismatch');
                }
            }
        }
    }
}
