<?php

namespace App\Console\Commands;

use App\Services\Weighted\BuildCombinations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WeightedStatusCommand extends Command
{
    protected $signature = 'lotto:weighted-status';

    protected $description = '가중 추천의 DB 대상과 현재 건수 및 최초 적재 계획을 읽기 전용으로 확인합니다';

    /** 자격증명 없이 실제 접속 대상과 가중 테이블 준비 상태만 출력합니다. */
    public function handle(): int
    {
        $connection = DB::connection();
        $tables = [];
        foreach (['winning_numbers', 'filtered_base_candidates', 'filter5_numbers', 'filter6_numbers', 'recommendation_runs', 'lotto_combinations', 'weighted_models', 'weighted_pools', 'weighted_pool_entries', 'weighted_selections'] as $table) {
            $tables[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
        }
        $latest = Schema::hasTable('winning_numbers') ? DB::table('winning_numbers')->max('round') : null;
        $database = $connection->getDriverName() === 'mysql' ? DB::selectOne('SELECT DATABASE() AS name')->name : $connection->getDatabaseName();
        $this->line(json_encode([
            'environment' => app()->environment(), 'driver' => $connection->getDriverName(),
            'host' => $connection->getConfig('host'), 'port' => $connection->getConfig('port'),
            'database' => $database, 'tables' => $tables, 'latest_round' => $latest,
            'initial_plan' => ['combination_inserts' => max(0, BuildCombinations::TOTAL - ($tables['lotto_combinations'] ?? 0)), 'model_inserts' => 1, 'pool_inserts' => 1, 'pool_entry_inserts' => 10000, 'selection_inserts' => 0, 'run_inserts' => 0],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
