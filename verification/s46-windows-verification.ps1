# MASARHR - S46 WINDOWS VERIFICATION (single block). Run in Windows PowerShell on the Owner machine.
#
# READ-ONLY with respect to source, Git and data: it never edits a tracked/untracked source file, stages, commits, pushes,
# tags, resets or cleans; it runs no migration, seed, password change, or backend command; it needs no secrets.
# Declared side effects of the checks themselves (all git-ignored build outputs): `npm run build` writes frontend\dist,
# `tsc -b` may refresh TypeScript build-info under node_modules\.tmp, vitest/eslint may refresh their caches.
# A failed check is NEVER reported as PASS: only exit code 0 counts, and the test counts are compared with the tested package.
$ErrorActionPreference = 'Continue'
$Repo  = 'C:\Projects\MasarHR'
$Front = Join-Path $Repo 'frontend'
$ExpectedHead = '7df739f7012f2f8225351ab03a78e9001a0cd821'
$ExpectedTestFiles = 18; $ExpectedTests = 162   # as run by the author on Linux for this exact payload
$Summary = [ordered]@{}

# SHA-256 of the 24 payload files exactly as the author tested them (S46 23 files + Employee360Tabs.test.tsx; Employee360Page.tsx is the RC2 version).
$Expected = [ordered]@{
    'frontend/src/features/employees/Employee360StatusHistory.tsx' = '46d0476c37a19e609ff49a0e1d0626d2d69eca1e5edebdd1274312d56d2a1aca'
    'frontend/src/features/employees/api.ts' = 'd764e71a6298298541a59e87a27ce6c76d5a767176a26258c34a4b47e0125d05'
    'frontend/src/features/employees/operations/MovementOperations.tsx' = '245f6d5001695916a1fd4f5dccc1d6a375eb39ec9b9151f5b841ae4e02812279'
    'frontend/src/features/employees/operations/ScheduleAndEndOperations.tsx' = '89eb489e217ee0a30a8260762bbe0d10b151928d71e0ee3ef6379ac2fc05799c'
    'frontend/src/features/employees/operations/StatusOperations.tsx' = '1ba7de25a617aa1ca54acd81160eb8de54083b4ffea96d6aff1434c7b4d23e0a'
    'frontend/src/features/employees/operations/WeekdayPicker.tsx' = '91f30d6dbbe3f85c98b50ca1d2a4192c36ec3c59831153f16cd3a9b0ee35ea95'
    'frontend/src/features/employees/operations/api.ts' = 'f3049eef5dc824bc69271d90cdad5ef904fecfeedb8de3ad967a2fc1c4fac094'
    'frontend/src/features/employees/operations/optionHooks.ts' = '90ca6a6eae4e452d2f766f2c79063dc42f56746891a11de838c4340db467194b'
    'frontend/src/features/employees/operations/options.tsx' = '2e4c6e148e49fea8b34b7fb0b25c1798c785454a804197abd293178057449d6d'
    'frontend/src/features/employees/operations/shared.ts' = '0107c1a6ac4f97d269be49dc4824cd500c9b7169974cae5cf9b13b5f193b5b81'
    'frontend/src/features/employees/statusCodes.ts' = 'aa43a1fed38ab14581cfda135e474966e2e48c5424d034e08d1eb27ecbb79c29'
    'frontend/src/features/employees/weekdays.ts' = 'acef7c4a3cf88db3c3c05f2f2bd2356285d8c5b388a85070869ff283329930c8'
    'frontend/src/i18n/messages/ar.ts' = '53cb2768221cc6452f0134e6120d4cd6d6c0b280793162bc34bc88743e79ea7a'
    'frontend/src/i18n/messages/en.ts' = '5b8cb16bb7997545ad41a40304f2aadeeaf64198aa43ca2954d1813ca2d2744b'
    'frontend/src/pages/Employee360Operations.test.tsx' = '7e5c91df50e69cc05ebea8911d515512ca789980f7c3a12f0a03f1f8af114c54'
    'frontend/src/pages/Employee360Page.tsx' = '507bfb304fa07a80f3dce7bde05626989a00a9b6f2dd5209cfdb00d1ab491e51'
    'frontend/src/pages/Employee360Tabs.test.tsx' = '7b94956f420904ced556ed3d10b480e6ff2268b6d0ed94b7f4746c7e634a54d5'
    'frontend/src/shared/api/mutationError.ts' = 'd6f2046abfe346373bd4238edd6d710a994bbfdccf09bd3e9ae9eedabf559ee8'
    'frontend/src/shared/hooks/useOperation.ts' = 'b11a10b07305db20dbf6b6d4c5bed38464c4eb6f8ee6beca4cc8221c87f28de8'
    'frontend/src/shared/security/permissions.ts' = 'a4b4bbd047505d8d630b6e49c248df35dd607a770b4945b26c82ec4527ac25c3'
    'frontend/src/shared/ui/NativeSelect.tsx' = 'f729681fa976fc2d34dfeaa446062cfe12015e95c1bee63bed45b9d04e3ac341'
    'frontend/src/shared/ui/Operation.tsx' = '9d5554b80cb9f5339ebb2c9e74754b981cd748660221d3a50461726d2c9e3d53'
    'frontend/src/shared/ui/feedbackContext.ts' = '13beae9862b54b3a7d053f08741ebdead9c44915e453d412f08da488979a6db5'
    'frontend/src/test/employee360Fixtures.ts' = '158d94aec40b5e5d5d059ae362427ea43c4c25486399ee374537f9cd98ab768c'
}

function Section($t) { ''; "=================== $t ===================" }
function Invoke-Native([string]$File, [string[]]$ArgList, [string]$WorkDir, [int]$TimeoutSec) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $File
    $psi.Arguments = ($ArgList | ForEach-Object { if ($_ -match '[\s"]') { '"' + ($_ -replace '"', '\"') + '"' } else { $_ } }) -join ' '
    if ($WorkDir) { $psi.WorkingDirectory = $WorkDir }
    $psi.UseShellExecute = $false; $psi.RedirectStandardOutput = $true; $psi.RedirectStandardError = $true; $psi.CreateNoWindow = $true
    $p = New-Object System.Diagnostics.Process; $p.StartInfo = $psi
    try { [void]$p.Start() } catch { return [pscustomobject]@{ ExitCode = -1; TimedOut = $false; Text = "could not start: $($_.Exception.Message)" } }
    $o = $p.StandardOutput.ReadToEndAsync(); $e = $p.StandardError.ReadToEndAsync()
    $timedOut = -not $p.WaitForExit($TimeoutSec * 1000)
    if ($timedOut) { try { $p.Kill() } catch {} ; [void]$p.WaitForExit(3000) }
    [pscustomobject]@{ ExitCode = $(if ($timedOut) { -2 } else { $p.ExitCode }); TimedOut = $timedOut; Text = ($(if ($o.Wait(3000)) { $o.Result } else { '' }) + "`n" + $(if ($e.Wait(3000)) { $e.Result } else { '' })) }
}
function Git-Read([string[]]$GitArgs) { Invoke-Native 'git' (@('-C', $Repo) + $GitArgs) $null 60 }

Section '1. GIT (read-only)'
$branch = (Git-Read @('branch', '--show-current')).Text.Trim()
$head   = (Git-Read @('rev-parse', 'HEAD')).Text.Trim()
$origin = (Git-Read @('rev-parse', 'origin/develop')).Text.Trim()
$staged = @(((Git-Read @('diff', '--cached', '--name-only')).Text -split "`r?`n") | Where-Object { $_.Trim() })
"branch        = $branch"; "HEAD          = $head"; "origin/develop = $origin  (not fetched by this script)"; "staged files  = $($staged.Count)"
'git status --short:'; ((Git-Read @('status', '--short')).Text -split "`r?`n") | Where-Object { $_.Trim() } | ForEach-Object { "    $_" }
$Summary['git_branch_develop']  = $(if ($branch -eq 'develop') { 'PASS' } else { "FAIL ($branch)" })
$Summary['git_head_expected']   = $(if ($head -eq $ExpectedHead) { 'PASS' } else { "FAIL ($head)" })
$Summary['git_index_empty']     = $(if ($staged.Count -eq 0) { 'PASS' } else { 'FAIL' })

Section '2. PAYLOAD FINGERPRINTS (24 files) vs the tested package'
$bad = 0
foreach ($rel in $Expected.Keys) {
    $path = Join-Path $Repo ($rel -replace '/', '\')
    if (-not (Test-Path $path)) { "MISSING   $rel"; $bad++; continue }
    $raw = (Get-FileHash $path -Algorithm SHA256).Hash.ToLower()
    if ($raw -eq $Expected[$rel]) { "MATCH     $rel"; continue }
    $bytes = [IO.File]::ReadAllBytes($path); $text = [Text.Encoding]::UTF8.GetString($bytes) -replace "`r`n", "`n"
    $lf = ([BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($text))) -replace '-', '').ToLower()
    if ($lf -eq $Expected[$rel]) { "MATCH(LF) $rel   (only line endings differ)" } else { "DIFFERENT $rel   actual=$($raw.Substring(0, 12)) expected=$($Expected[$rel].Substring(0, 12))"; $bad++ }
}
'Other changed/untracked files under frontend/ NOT in the tested package (informational):'
$known = @($Expected.Keys | ForEach-Object { $_ })
((Git-Read @('status', '--short', '--untracked-files=all', '--', 'frontend')).Text -split "`r?`n") | Where-Object { $_.Trim() } | ForEach-Object {
    $p = ($_.Substring(3).Trim('"')) -replace '\\', '/'
    if ($known -notcontains $p) { "    $_" }
}
$Summary['payload_fingerprints'] = $(if ($bad -eq 0) { 'PASS (all 24 match)' } else { "FAIL ($bad file(s) missing/different)" })

Section '3. TOOLCHAIN'
foreach ($c in @(@('node', '-v'), @('npm.cmd', '-v'))) { $r = Invoke-Native 'cmd.exe' @('/d', '/c', ($c -join ' ')) $Front 30; "$($c[0]) $($r.Text.Trim())" }
'package.json engines require node >= 24.'

Section '4. FRONTEND CHECKS (exact package.json scripts: lint = eslint . | typecheck = tsc -b | test = vitest run | build = tsc -b && vite build)'
function Run-Check([string]$Name, [string]$NpmScript, [int]$TimeoutSec) {
    Write-Host "--- npm run $NpmScript"
    $r = Invoke-Native 'cmd.exe' @('/d', '/s', '/c', "npm.cmd run $NpmScript") $Front $TimeoutSec
    ($r.Text -split "`r?`n") | Where-Object { $_.Trim() } | Select-Object -Last 30 | ForEach-Object { Write-Host "    $_" }
    Write-Host "EXIT_CODE[$Name] = $($r.ExitCode)$(if ($r.TimedOut) { '  (TIMEOUT - child killed)' })"
    return $r
}
$lint = Run-Check 'lint' 'lint' 300;       $Summary['lint']      = $(if ($lint.ExitCode -eq 0) { 'PASS' } else { "FAIL (exit $($lint.ExitCode))" })
$tsc  = Run-Check 'typecheck' 'typecheck' 300; $Summary['typecheck'] = $(if ($tsc.ExitCode -eq 0) { 'PASS' } else { "FAIL (exit $($tsc.ExitCode))" })
$test = Run-Check 'test' 'test' 600
$clean = $test.Text -replace '\x1b\[[0-9;]*m', ''
$files = if ($clean -match 'Test Files\s+(?:\d+ failed \| )?(\d+) passed \((\d+)\)') { [int]$Matches[2] } else { -1 }
$tests = if ($clean -match 'Tests\s+(?:\d+ failed \| )?(\d+) passed \((\d+)\)') { [int]$Matches[2] } else { -1 }
$failedLine = ($clean -split "`r?`n" | Where-Object { $_ -match '^\s*(Test Files|Tests)\s+.*failed' } | Select-Object -First 2) -join ' / '
"parsed: test files total=$files tests total=$tests (author's Linux run: $ExpectedTestFiles files / $ExpectedTests tests)"; if ($failedLine) { "failures line(s): $failedLine" }
$Summary['test'] = $(if ($test.ExitCode -eq 0 -and -not $failedLine) { if ($files -eq $ExpectedTestFiles -and $tests -eq $ExpectedTests) { "PASS ($files files / $tests tests)" } else { "PASS but counts differ from the tested package ($files files / $tests tests) - explain before accepting" } } else { "FAIL (exit $($test.ExitCode))" })
$build = Run-Check 'build' 'build' 600;    $Summary['build']     = $(if ($build.ExitCode -eq 0) { 'PASS' } else { "FAIL (exit $($build.ExitCode))" })

Section 'SUMMARY - only exit code 0 counts as PASS'
$Summary.GetEnumerator() | ForEach-Object { "{0,-24} {1}" -f $_.Key, $_.Value }
'Git state was only read. No file other than git-ignored build output was written by this script.'
