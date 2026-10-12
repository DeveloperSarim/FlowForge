<?php

namespace App\Domain\Integrations\Handlers;

use App\Domain\Integrations\ActionException;
use App\Domain\Integrations\ActionResult;
use App\Domain\Integrations\Models\Credential;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Runs one parameterised SQL statement against the MySQL server in the node's credential.
 * Use "?" placeholders + bindings for anything that comes from a previous step.
 */
class DatabaseHandler
{
    public function handle(string $handler, array $inputs, ?Credential $credential): ActionResult
    {
        if (! $credential || $credential->auth_type !== 'mysql') {
            throw new ActionException('Select a MySQL credential for this node.');
        }

        $db = $credential->data;
        $sql = trim((string) $inputs['query']);
        $bindings = array_map(fn ($value) => is_array($value) ? json_encode($value) : $value, $inputs['bindings'] ?? []);

        try {
            $connection = DB::build([
                'driver' => 'mysql',
                'host' => $db['host'],
                'port' => (int) ($db['port'] ?? 3306),
                'database' => $db['database'],
                'username' => $db['username'],
                'password' => (string) ($db['password'] ?? ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'options' => [PDO::ATTR_TIMEOUT => 10],
            ]);

            if (preg_match('/^\s*(select|show|describe|desc|explain|with)\b/i', $sql)) {
                $rows = array_map(fn ($row) => (array) $row, $connection->select($sql, $bindings));

                return new ActionResult(output: ['rows' => $rows, 'count' => count($rows)]);
            }

            $affected = $connection->affectingStatement($sql, $bindings);

            return new ActionResult(output: [
                'affected_rows' => $affected,
                'last_insert_id' => preg_match('/^\s*insert\b/i', $sql) ? (int) $connection->getPdo()->lastInsertId() : null,
            ]);
        } catch (Throwable $e) {
            throw new ActionException('Database error: '.strtok($e->getMessage(), "\n"), previous: $e);
        } finally {
            if (isset($connection)) {
                $connection->disconnect();
            }
        }
    }
}
