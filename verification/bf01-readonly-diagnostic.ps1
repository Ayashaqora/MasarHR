# READ-ONLY. Creates ONE temporary PHP file with a unique random name under %TEMP% and deletes exactly that file at the end.
# Runs SELECT statements only, inside a READ ONLY transaction that is always rolled back.
$ErrorActionPreference = 'Continue'
$Php     = 'C:\Users\Lenovo\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$Backend = 'C:\Projects\MasarHR\backend'
$Ini = 'C:\Users\Lenovo\.masarhr\php83\php.ini'   # the project's php.ini, passed explicitly (PHPRC is left untouched)
if (-not (Test-Path $Php))     { throw "BLOCKED: PHP 8.3 executable not found: $Php" }
if (-not (Test-Path $Backend)) { throw "BLOCKED: backend folder not found: $Backend" }
Set-Location $Backend
$tmp = Join-Path $env:TEMP ('masar-bf01-{0}.php' -f [guid]::NewGuid().ToString('N'))
$script = @'
<?php
chdir($argv[1]);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function blocked(string $m): void { fwrite(STDERR, "BLOCKED: $m\n"); exit(1); }
$env = config('app.env');
$db = DB::connection()->getDatabaseName();
if ($env !== 'local') { blocked("APP_ENV is '$env', expected local"); }
if ($db !== 'masarhr') { blocked("database is '$db', expected masarhr"); }

// STRICT READ-ONLY: a read-only transaction that is always rolled back. Only SELECT statements follow.
DB::beginTransaction();
DB::statement('SET TRANSACTION READ ONLY');

$out = ['app_env' => $env, 'database' => $db, 'server_today' => now()->toDateString(), 'app_timezone' => config('app.timezone')];

$person = DB::selectOne('SELECT id, national_id, is_terminal, version FROM hr.persons WHERE national_id = ?', ['9990000001']);
if (! $person) { DB::rollBack(); blocked('no person with national_id 9990000001'); }
$out['person'] = $person;

$rels = DB::select('SELECT id, employment_type_id, employee_number, effective_from, effective_to, end_knowledge_state, ended_terminally, version FROM hr.employment_relationships WHERE person_id = ? ORDER BY effective_from', [$person->id]);
$out['relationships'] = $rels;

$periods = [];
foreach ($rels as $rel) {
    $periods[$rel->id] = DB::select(
        'SELECT p.id, d.code AS status_code, p.effective_from, p.effective_to, p.travel_pay_status, p.created_at
           FROM hr.employment_status_periods p JOIN ref.employment_status_details d ON d.id = p.status_detail_id
          WHERE p.employment_relationship_id = ? ORDER BY p.effective_from, p.created_at',
        [$rel->id],
    );
}
$out['status_periods'] = $periods;
$out['open_period_count'] = array_map(fn ($rows) => count(array_filter($rows, fn ($r) => $r->effective_to === null)), $periods);
$out['status_behavior_floor'] = DB::selectOne("SELECT max(b.effective_from) AS floor FROM ref.employment_status_detail_behaviors b JOIN ref.employment_status_details d ON d.id = b.status_detail_id")->floor;

// Evaluate each rejection condition of RecordEmploymentStatusPeriod for the attempted date and its day/month swap.
$checks = [];
foreach (['2026-10-03', '2026-03-10'] as $candidate) {
    foreach ($rels as $rel) {
        $checks[$candidate][$rel->id] = [
            'A_not_after_relationship_effective_from' => $candidate <= (string) $rel->effective_from,
            'B_recorded_periods_starting_on_or_after_candidate' => DB::select(
                'SELECT p.id, d.code AS status_code, p.effective_from, p.effective_to FROM hr.employment_status_periods p JOIN ref.employment_status_details d ON d.id = p.status_detail_id WHERE p.employment_relationship_id = ? AND p.effective_from >= ? ORDER BY p.effective_from',
                [$rel->id, $candidate],
            ),
            'covering_period' => DB::selectOne(
                'SELECT p.id, p.effective_from, p.effective_to FROM hr.employment_status_periods p WHERE p.employment_relationship_id = ? AND p.effective_from < ? AND (p.effective_to IS NULL OR p.effective_to > ?)',
                [$rel->id, $candidate, $candidate],
            ),
        ];
    }
}
$out['rejection_condition_checks'] = $checks;

DB::rollBack();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
echo "READ_ONLY_DIAGNOSTIC_DONE (nothing was written)\n";
'@
try {
    Set-Content -Path $tmp -Value $script -Encoding UTF8
    if (-not (Test-Path $Ini)) { throw "BLOCKED: project php.ini not found: $Ini" }
    & $Php -c $Ini $tmp $Backend
    "PHP_EXIT=$LASTEXITCODE"
}
finally {
    if ($tmp -and (Test-Path $tmp) -and ((Split-Path $tmp -Leaf) -like 'masar-bf01-*')) { Remove-Item $tmp -Force }
}
"TEMP_SCRIPT_EXISTS=$(Test-Path $tmp)"
