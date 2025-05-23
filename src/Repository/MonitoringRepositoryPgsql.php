<?php

namespace RiseTechApps\Monitoring\Repository;

use Illuminate\Support\Facades\DB;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

class MonitoringRepositoryPgsql  implements MonitoringRepositoryInterface
{
    protected string $connection;
    protected string $table = 'monitorings';

    public function __construct(string $connection)
    {
        $this->connection = $connection;
    }

    public function create(array $data): void
    {
        $data = $this->convertNestedArraysToJson($data);
        DB::connection($this->connection)->table($this->table)->insert($data);
    }

    public function convertNestedArraysToJson(array $data): array
    {
        foreach ($data as $key => $value) {
            foreach ($value as $k => $v) {

                if (is_array($v)) {
                    $data[$key][$k] = json_encode($v);
                }
            }
        }

        return $data;
    }
}
