<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Services\FinancialOwnershipLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceIssuanceReadiness
{
    public function __construct(private readonly InvoiceSchemaContract $schema, private readonly FinancialOwnershipLedgerService $ledger) {}

    public function entry(): void
    {
        if (config('invoice_issuance.enabled') !== true || config('financial_ownership.enabled') !== true) {
            throw new InvoiceIssuanceFailure('gate_closed');
        }
        // Pertahanan aplikasi, tidak bergantung pada autoload-dev Tests atau flag DTO.
        $identity = json_decode(getenv('SILANSIA_TEST_IDENTITY') ?: '', true);
        if (! app()->environment('testing') || app()->configurationIsCached() || config('database.default') !== 'silansia_testing'
            || ! is_array($identity) || DB::getDriverName() !== 'pgsql') {
            throw new InvoiceIssuanceFailure('owned_test_database_required');
        }
        foreach (['database', 'oid', 'owner', 'address', 'port', 'host', 'connection_port', 'token', 'cache'] as $key) {
            if (! isset($identity[$key]) || ! is_string($identity[$key])) {
                throw new InvoiceIssuanceFailure('owned_test_database_required');
            }
        }
        $db = DB::connection();
        if (! preg_match('/\Asilansia_test_[a-f0-9]{16}\z/', $identity['database'])
            || ! preg_match('/\A[a-f0-9]{64}\z/', $identity['token']) || ! ctype_digit($identity['oid'])
            || $db->getName() !== 'silansia_testing' || $db->getConfig('url') !== null
            || $db->getConfig('database') !== $identity['database'] || $db->getConfig('host') !== $identity['host']
            || (string) $db->getConfig('port') !== $identity['connection_port'] || $db->getConfig('username') !== $identity['owner']
            || config('database.connections.pgsql.database') === $identity['database']
            || getenv('APP_CONFIG_CACHE') !== $identity['cache'] || file_exists($identity['cache']) || is_link($identity['cache'])
            || ! is_dir(dirname($identity['cache']))
            || ! str_starts_with($identity['cache'], sys_get_temp_dir().DIRECTORY_SEPARATOR.'silansia-phase1-')) {
            throw new InvoiceIssuanceFailure('owned_test_database_required');
        }
        $row = DB::selectOne("SELECT current_database() AS name,current_user AS role,d.oid::text AS oid,
            pg_get_userbyid(d.datdba) AS owner,COALESCE(inet_server_addr()::text,'') AS address,
            COALESCE(inet_server_port()::text,'') AS port,shobj_description(d.oid,'pg_database') AS marker
            FROM pg_database d WHERE datname=current_database()");
        if (! $row || $row->name !== $identity['database'] || $row->oid !== $identity['oid'] || $row->role !== $identity['owner']
            || $row->owner !== $identity['owner'] || $row->address !== $identity['address'] || $row->port !== $identity['port']
            || $row->marker !== 'silansia-phase1:'.$identity['token']) {
            throw new InvoiceIssuanceFailure('owned_test_database_required');
        }
    }

    public function transaction(): void
    {
        $this->entry();
        if (DB::transactionLevel() !== 1 || DB::scalar("SELECT current_setting('transaction_isolation')") !== 'read committed'
            || DB::scalar("SELECT current_setting('session_replication_role')") !== 'origin') {
            throw new InvoiceIssuanceFailure('read_committed_required');
        }
        try {
            $this->ledger->assertReady();
        } catch (ValidationException) {
            throw new InvoiceIssuanceFailure('ledger_unavailable');
        }
        $this->schema->assertReady();
    }
}
