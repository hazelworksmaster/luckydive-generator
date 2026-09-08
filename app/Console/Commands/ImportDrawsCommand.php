<?php

namespace App\Console\Commands;

use App\Services\Lotto\DrawHistory;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

class ImportDrawsCommand extends Command
{
    protected $signature = 'lotto:import-draws {file : UTF-8 CSV 파일} {--apply : 검증 후 저장} {--dry-run : 저장 없는 사전 확인}';

    protected $description = '최초 당첨 이력을 CSV로 검증·반입합니다';

    /** 필수 헤더·중복 회차·번호를 모두 검증한 뒤 한 transaction으로 반입합니다. */
    public function handle(DrawHistory $history): int
    {
        $handle = null;
        try {
            if ($this->option('apply') && $this->option('dry-run')) {
                throw new RuntimeException('--apply와 --dry-run은 동시에 사용할 수 없습니다.');
            }
            $path = $this->argument('file');
            if (! is_file($path) || ! is_readable($path) || filesize($path) > 5 * 1024 * 1024) {
                throw new RuntimeException('읽을 수 있는 5MB 이하 CSV 파일이 필요합니다.');
            }
            $handle = fopen($path, 'r');
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (! is_array($header)) {
                throw new RuntimeException('CSV 헤더가 없습니다.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $required = ['round', 'number1', 'number2', 'number3', 'number4', 'number5', 'number6', 'bonus', 'date'];
            if (count($header) !== count(array_unique($header)) || array_diff($required, $header)) {
                throw new RuntimeException('CSV 필수 헤더가 누락되거나 중복되었습니다.');
            }
            $draws = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== count($header)) {
                    throw new RuntimeException('CSV 열 수가 헤더와 다릅니다.');
                }
                $draws[] = $history->fromArray(array_combine($header, $row));
            }
            if (! $draws) {
                throw new RuntimeException('CSV 데이터가 없습니다.');
            }
            $this->line(json_encode($history->store($draws, 'initial_csv', (bool) $this->option('apply')), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e instanceof QueryException ? 'DB 조회 또는 저장 실패: 연결 설정과 migration을 확인하세요.' : $e->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
