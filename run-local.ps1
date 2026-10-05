# MasarHR local launcher (S46). Verifies prerequisites, starts Redis/Backend/Frontend only if not
# already running for this project, waits for health, then opens the browser.
#
# Boundaries (S46 authorization): never modifies php.ini, .env, package.json or application source;
# never runs migrations/seed/optimize/cache-clear; never stops or kills a process it did not start
# itself and cannot positively identify as belonging to this project; never changes Docker's
# restart policy or any other persistent Docker setting; never prints cookies, tokens or secrets.
# Targets Windows PowerShell 5.1 compatibility: no ternary (?:), no null-coalescing (?? / ??=),
# no pipeline chain operators (&&/||), no ForEach-Object -Parallel, no $PSStyle, no
# [System.Diagnostics.ProcessStartInfo]::ArgumentList (that property does not exist in .NET
# Framework / Windows PowerShell 5.1 - it is .NET Core-only). External command arguments are
# built as a single, correctly Windows-quoted string via ConvertTo-WindowsCommandLine /
# ConvertTo-QuotedWindowsArgument below, and assigned to ProcessStartInfo.Arguments instead.

$ErrorActionPreference = 'Stop'

# ---- Fixed configuration (edit here only; this script does not read/modify .env or php.ini) ----
$Repo              = $PSScriptRoot
$Backend           = Join-Path $Repo 'backend'
$BackendPublic     = Join-Path $Backend 'public'
$Frontend          = Join-Path $Repo 'frontend'
$PhpExe            = 'C:\Users\Lenovo\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$PhpIni            = 'C:\Users\Lenovo\.masarhr\php83\php.ini'
$RouterScript      = Join-Path $Backend 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
$DockerDesktopExe  = 'C:\Program Files\Docker\Docker\frontend\Docker Desktop.exe'
$RedisContainer    = 'masarhr-local-redis'
$BackendBindHost   = '127.0.0.1'
$BackendPort       = 8000
$FrontendBindHost  = '127.0.0.1'
$FrontendPort      = 5173
$ExpectedService   = 'masar-hr-api'

$DockerReadyTimeoutSec       = 90
$DockerInfoTimeoutSec        = 10
$DockerPsTimeoutSec          = 10
$DockerStartTimeoutSec       = 30
$DockerExecTimeoutSec        = 10
$RedisReadyTimeoutSec        = 30
$RedisTcpTimeoutMs           = 3000
$HealthReadyTimeoutSec       = 25
$CsrfTimeoutSec              = 10
$FrontendOwnershipMaxDepth   = 6

function Write-Step([string]$Text) { Write-Host ''; Write-Host "== $Text ==" -ForegroundColor Cyan }
function Write-Ok([string]$Text)   { Write-Host "OK: $Text" -ForegroundColor Green }
function Fail([string]$Text) {
    Write-Host ''
    Write-Host "FAILED: $Text" -ForegroundColor Red
    exit 1
}

# ---------------------------------------------------------------------------------------
# Helper: quote a single argument per the Windows CommandLineToArgvW / MSVCRT argv rules
# (the same rules .NET's own process-creation API expects in ProcessStartInfo.Arguments).
# A run of N backslashes followed by a quote becomes 2N+1 backslashes + an escaped quote;
# a run of N backslashes at the very end of a quoted argument becomes 2N backslashes
# (doubled, because the closing quote that follows would otherwise escape the last one).
# ---------------------------------------------------------------------------------------
function ConvertTo-QuotedWindowsArgument {
    param([string]$Argument)

    if ([string]::IsNullOrEmpty($Argument)) { return '""' }
    if ($Argument.IndexOfAny([char[]]@(' ', "`t", '"')) -lt 0) { return $Argument }

    $sb = New-Object System.Text.StringBuilder
    [void]$sb.Append('"')
    $backslashCount = 0
    for ($i = 0; $i -lt $Argument.Length; $i++) {
        $c = $Argument[$i]
        if ($c -eq '\') {
            $backslashCount++
            continue
        }
        if ($c -eq '"') {
            [void]$sb.Append('\' * (($backslashCount * 2) + 1))
            [void]$sb.Append('"')
            $backslashCount = 0
            continue
        }
        if ($backslashCount -gt 0) {
            [void]$sb.Append('\' * $backslashCount)
            $backslashCount = 0
        }
        [void]$sb.Append($c)
    }
    if ($backslashCount -gt 0) {
        [void]$sb.Append('\' * ($backslashCount * 2))
    }
    [void]$sb.Append('"')
    return $sb.ToString()
}

function ConvertTo-WindowsCommandLine {
    param([string[]]$Arguments)
    $quoted = foreach ($a in $Arguments) { ConvertTo-QuotedWindowsArgument -Argument $a }
    return ($quoted -join ' ')
}

# ---------------------------------------------------------------------------------------
# Helper: run an external command with an explicit, real timeout. On timeout, kill ONLY
# the CLI process this function started (never Docker Desktop, never a container).
# stdout/stderr are read asynchronously (Task-based, .NET Framework 4.5+, so this is fine
# under Windows PowerShell 5.1) starting immediately after Start(), in parallel with the
# process running, specifically so a chatty child process can never fill an unread pipe
# buffer and deadlock. Draining those tasks after the process exits (or is killed) is
# itself bounded by $StreamDrainTimeoutMs - it never waits unboundedly, even though the
# process has already exited, in case a child process keeps a handle to the pipe open.
# ---------------------------------------------------------------------------------------
function Invoke-WithTimeout {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [string[]]$ArgumentList = @(),
        [Parameter(Mandatory = $true)][int]$TimeoutSec,
        [int]$StreamDrainTimeoutMs = 3000
    )

    function Get-TaskResultOrDefault {
        param($Task, [int]$TimeoutMs)
        if ($Task.Wait($TimeoutMs)) { return $Task.Result }
        return ''
    }

    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName               = $FilePath
    $psi.Arguments              = ConvertTo-WindowsCommandLine -Arguments $ArgumentList
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError  = $true
    $psi.UseShellExecute        = $false
    $psi.CreateNoWindow         = $true

    $proc = [System.Diagnostics.Process]::Start($psi)
    $stdoutTask = $proc.StandardOutput.ReadToEndAsync()
    $stderrTask = $proc.StandardError.ReadToEndAsync()
    $finished = $proc.WaitForExit($TimeoutSec * 1000)

    if (-not $finished) {
        try { $proc.Kill() } catch { }
        $killedStdOut = Get-TaskResultOrDefault -Task $stdoutTask -TimeoutMs $StreamDrainTimeoutMs
        $killedStdErr = Get-TaskResultOrDefault -Task $stderrTask -TimeoutMs $StreamDrainTimeoutMs
        return @{ TimedOut = $true; ExitCode = $null; StdOut = $killedStdOut; StdErr = $killedStdErr }
    }

    $stdout = Get-TaskResultOrDefault -Task $stdoutTask -TimeoutMs $StreamDrainTimeoutMs
    $stderr = Get-TaskResultOrDefault -Task $stderrTask -TimeoutMs $StreamDrainTimeoutMs
    return @{ TimedOut = $false; ExitCode = $proc.ExitCode; StdOut = $stdout; StdErr = $stderr }
}

# ---------------------------------------------------------------------------------------
# Helper: TCP reachability with an explicit timeout (replaces unbounded Test-NetConnection).
# ---------------------------------------------------------------------------------------
function Test-TcpPortOpen {
    param(
        [Parameter(Mandatory = $true)][string]$ComputerName,
        [Parameter(Mandatory = $true)][int]$Port,
        [Parameter(Mandatory = $true)][int]$TimeoutMs
    )
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $connectTask = $client.BeginConnect($ComputerName, $Port, $null, $null)
        $signaled = $connectTask.AsyncWaitHandle.WaitOne($TimeoutMs)
        if (-not $signaled) { return $false }
        $client.EndConnect($connectTask)
        return $true
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

# =====================================================================================
# Step 1 - Pre-flight: required files and tools must exist before anything is started.
# =====================================================================================
Write-Step 'Step 1: Pre-flight checks (files and tools)'

$RequiredPaths = @(
    @{ Path = $PhpExe;        Label = 'PHP 8.3 executable' },
    @{ Path = $PhpIni;        Label = 'project php.ini' },
    @{ Path = $RouterScript;  Label = "Laravel dev-server router (vendor\laravel\framework\...\server.php)" },
    @{ Path = $BackendPublic; Label = 'backend\public' },
    @{ Path = $Frontend;      Label = 'frontend' },
    @{ Path = (Join-Path $Frontend 'package.json'); Label = 'frontend\package.json' }
)
foreach ($item in $RequiredPaths) {
    if (-not (Test-Path -LiteralPath $item.Path)) { Fail "Missing required path for '$($item.Label)': $($item.Path)" }
}

foreach ($cmd in @('docker', 'npm.cmd')) {
    if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) { Fail "Required tool not found on PATH: $cmd" }
}

Write-Ok 'php.exe, php.ini, router script, backend\public, frontend\package.json, docker and npm.cmd are all present.'

# =====================================================================================
# Step 2 - Docker Engine: start Docker Desktop only if the engine is not already answering.
# Every docker CLI call below has a real timeout; on timeout only the CLI process is killed.
# =====================================================================================
Write-Step 'Step 2: Docker Engine'

function Test-DockerEngine {
    $r = Invoke-WithTimeout -FilePath 'docker' -ArgumentList @('info') -TimeoutSec $DockerInfoTimeoutSec
    return ((-not $r.TimedOut) -and ($r.ExitCode -eq 0))
}

if (-not (Test-DockerEngine)) {
    Write-Host 'Docker Engine is not responding yet - starting Docker Desktop...'
    if (-not (Test-Path -LiteralPath $DockerDesktopExe)) {
        Fail "Docker Engine is not running and Docker Desktop was not found at: $DockerDesktopExe"
    }
    Start-Process -FilePath $DockerDesktopExe | Out-Null

    $deadline = (Get-Date).AddSeconds($DockerReadyTimeoutSec)
    $ready = $false
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 3
        if (Test-DockerEngine) { $ready = $true; break }
    }
    if (-not $ready) { Fail "Docker Engine did not become ready within $DockerReadyTimeoutSec seconds." }
}

Write-Ok 'Docker Engine is responding.'

# =====================================================================================
# Step 3 - Redis container: start the EXISTING container if needed; never create a new one,
# never change its restart policy. Exact PONG required; TCP check uses an explicit timeout.
# =====================================================================================
Write-Step "Step 3: Redis container ($RedisContainer)"

$psResult = Invoke-WithTimeout -FilePath 'docker' -ArgumentList @('ps', '-a', '--filter', "name=^/$RedisContainer`$", '--format', '{{.Names}}|{{.State}}') -TimeoutSec $DockerPsTimeoutSec
if ($psResult.TimedOut) { Fail "docker ps timed out after $DockerPsTimeoutSec seconds while looking for container '$RedisContainer'." }
if ($psResult.ExitCode -ne 0) { Fail "docker ps exited with code $($psResult.ExitCode): $($psResult.StdErr.Trim())" }

$existingLine = ($psResult.StdOut -split "`r?`n" | Where-Object { $_ -ne '' } | Select-Object -First 1)
if (-not $existingLine) {
    Fail "Container '$RedisContainer' does not exist on this machine. This script never creates containers - create it once manually, then re-run."
}
$state = ($existingLine -split '\|')[1]
if ($state -ne 'running') {
    Write-Host "Container exists but is not running (state: $state) - starting it..."
    $startResult = Invoke-WithTimeout -FilePath 'docker' -ArgumentList @('start', $RedisContainer) -TimeoutSec $DockerStartTimeoutSec
    if ($startResult.TimedOut) { Fail "docker start timed out after $DockerStartTimeoutSec seconds for container '$RedisContainer'." }
    if ($startResult.ExitCode -ne 0) { Fail "Failed to start container '$RedisContainer' (docker start exit code $($startResult.ExitCode)): $($startResult.StdErr.Trim())" }
} else {
    Write-Host "Container '$RedisContainer' is already running - reusing it."
}

$deadline = (Get-Date).AddSeconds($RedisReadyTimeoutSec)
$pong = $false
while ((Get-Date) -lt $deadline) {
    $pingResult = Invoke-WithTimeout -FilePath 'docker' -ArgumentList @('exec', $RedisContainer, 'redis-cli', 'PING') -TimeoutSec $DockerExecTimeoutSec
    if ((-not $pingResult.TimedOut) -and ($pingResult.ExitCode -eq 0) -and ($pingResult.StdOut.Trim() -ceq 'PONG')) {
        $pong = $true
        break
    }
    Start-Sleep -Seconds 2
}
if (-not $pong) { Fail "Redis did not reply exactly 'PONG' within $RedisReadyTimeoutSec seconds (docker exec redis-cli PING, each attempt capped at $DockerExecTimeoutSec seconds)." }

if (-not (Test-TcpPortOpen -ComputerName '127.0.0.1' -Port 6379 -TimeoutMs $RedisTcpTimeoutMs)) {
    Fail "Redis replied PONG inside its container, but 127.0.0.1:6379 did not accept a TCP connection within $RedisTcpTimeoutMs ms."
}

Write-Ok '127.0.0.1:6379 reachable, Redis replied exactly PONG.'

# =====================================================================================
# Step 4 - Port ownership: never start a second instance on a busy port, never stop an
# existing process. A listener whose identity cannot be read is NOT treated as a free port -
# it is a hard Fail, same as a listener that fails the ownership proof.
# =====================================================================================
Write-Step 'Step 4: Port ownership checks (8000, 5173)'

function Get-PortListener {
    param([int]$Port)
    $conn = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $conn) { return @{ State = 'None' } }
    $proc = Get-CimInstance Win32_Process -Filter "ProcessId=$($conn.OwningProcess)" -ErrorAction SilentlyContinue
    if (-not $proc) { return @{ State = 'Unknown'; ProcessId = $conn.OwningProcess } }
    return @{ State = 'Found'; Process = $proc }
}

# -- Backend (8000): require ExecutablePath to match the configured PHP, and php.ini +
#    backend\public + the router script to all appear in the command line. --
$skipBackendStart = $false
$backendListener = Get-PortListener -Port $BackendPort
if ($backendListener.State -eq 'Unknown') {
    Fail "Port $BackendPort has a listening process (PID $($backendListener.ProcessId)) whose identity could not be read (CIM lookup failed). A listener that cannot be identified is never treated as a free port. Investigate manually, then re-run."
} elseif ($backendListener.State -eq 'Found') {
    $bProc = $backendListener.Process
    $bExe = [string]$bProc.ExecutablePath
    $bCmd = [string]$bProc.CommandLine
    $backendProven = ($bExe) -and ($bExe.Equals($PhpExe, [System.StringComparison]::OrdinalIgnoreCase)) -and `
                      ($bCmd) -and ($bCmd.Contains($PhpIni)) -and ($bCmd.Contains($BackendPublic)) -and ($bCmd.Contains($RouterScript))
    if ($backendProven) {
        Write-Host "Port $BackendPort is already serving this project's backend (PID $($bProc.ProcessId)) - reusing it."
        $skipBackendStart = $true
    } else {
        Fail "Port $BackendPort is already in use by PID $($bProc.ProcessId) (ExecutablePath: '$bExe'), which does not prove itself to be this project's backend (expected PHP exe '$PhpExe' plus php.ini/public/router paths in its command line). Refusing to start a duplicate or stop an unidentified process. Free the port yourself, then re-run."
    }
}

# -- Frontend (5173): remove the old 'contains the word vite' shortcut. Walk the listening
#    process and its parent chain (up to $FrontendOwnershipMaxDepth levels); require explicit
#    evidence of BOTH the frontend path and vite in the same command line somewhere in the chain.
#    If any link in the chain cannot be read, that is inconclusive -> Fail, never silently accept. --
function Test-FrontendOwnershipChain {
    param([int]$ProcessId, [int]$MaxDepth)
    $currentId = $ProcessId
    for ($i = 0; $i -lt $MaxDepth; $i++) {
        if ((-not $currentId) -or ($currentId -eq 0)) { return @{ Result = 'NotFound' } }
        $p = Get-CimInstance Win32_Process -Filter "ProcessId=$currentId" -ErrorAction SilentlyContinue
        if (-not $p) { return @{ Result = 'Unreadable' } }
        $pCmd = [string]$p.CommandLine
        if (($pCmd) -and ($pCmd.Contains($Frontend)) -and ($pCmd -match 'vite')) {
            return @{ Result = 'Proven' }
        }
        $currentId = $p.ParentProcessId
    }
    return @{ Result = 'NotFound' }
}

$skipFrontendStart = $false
$frontendListener = Get-PortListener -Port $FrontendPort
if ($frontendListener.State -eq 'Unknown') {
    Fail "Port $FrontendPort has a listening process (PID $($frontendListener.ProcessId)) whose identity could not be read (CIM lookup failed). A listener that cannot be identified is never treated as a free port. Investigate manually, then re-run."
} elseif ($frontendListener.State -eq 'Found') {
    $fProc = $frontendListener.Process
    $chainResult = Test-FrontendOwnershipChain -ProcessId $fProc.ProcessId -MaxDepth $FrontendOwnershipMaxDepth
    if ($chainResult.Result -eq 'Proven') {
        Write-Host "Port $FrontendPort is already serving this project's frontend (PID $($fProc.ProcessId)) - reusing it."
        $skipFrontendStart = $true
    } elseif ($chainResult.Result -eq 'Unreadable') {
        Fail "Port $FrontendPort is in use by PID $($fProc.ProcessId), but its process chain could not be fully read (a CIM lookup failed partway up the parent chain). Ownership cannot be confirmed, so this is not treated as free or as ours. Investigate manually, then re-run."
    } else {
        Fail "Port $FrontendPort is already in use by PID $($fProc.ProcessId), and neither it nor its parent chain (up to $FrontendOwnershipMaxDepth levels) shows explicit evidence of Vite running from '$Frontend'. Refusing to start a duplicate or stop an unidentified process. Free the port yourself, then re-run."
    }
}

Write-Ok 'No unidentified or unproven process occupies the required ports.'

# =====================================================================================
# Step 5 - Start Backend (only if not already serving it).
# =====================================================================================
if (-not $skipBackendStart) {
    Write-Step 'Step 5: Starting Backend'
    $backendCommand = "Set-Location '$BackendPublic'; & '$PhpExe' -c '$PhpIni' -S '${BackendBindHost}:${BackendPort}' -t '$BackendPublic' '$RouterScript'"
    Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-NoExit', '-Command', $backendCommand) -WindowStyle Normal | Out-Null
    Write-Ok "Backend launch requested on ${BackendBindHost}:${BackendPort}."
} else {
    Write-Step 'Step 5: Backend already running - not starting a new one'
}

# =====================================================================================
# Step 6 - Start Frontend (only if not already serving it).
# =====================================================================================
if (-not $skipFrontendStart) {
    Write-Step 'Step 6: Starting Frontend'
    $frontendCommand = "Set-Location '$Frontend'; npm.cmd run dev -- --host $FrontendBindHost --port $FrontendPort --strictPort"
    Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-NoExit', '-Command', $frontendCommand) -WindowStyle Normal | Out-Null
    Write-Ok "Frontend launch requested on ${FrontendBindHost}:${FrontendPort} (strictPort)."
} else {
    Write-Step 'Step 6: Frontend already running - not starting a new one'
}

# =====================================================================================
# Step 7 - Wait for health: direct backend, then via Vite. Requires HTTP 200 AND a parsed
# JSON body with status == "ok" AND service == $ExpectedService (not a substring match).
# =====================================================================================
Write-Step 'Step 7: Waiting for health checks'

function Wait-HealthyService {
    param([string]$Url, [int]$TimeoutSec, [string]$ExpectedServiceName)
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    while ((Get-Date) -lt $deadline) {
        try {
            $resp = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 5
            if ($resp.StatusCode -eq 200) {
                try {
                    $json = $resp.Content | ConvertFrom-Json
                    if (($json.status -ceq 'ok') -and ($json.service -ceq $ExpectedServiceName)) {
                        return $true
                    }
                } catch {
                    # Body was not valid/parseable JSON yet - keep waiting until the deadline.
                }
            }
        } catch {
            # Not ready yet - keep waiting until the deadline.
        }
        Start-Sleep -Seconds 1
    }
    return $false
}

if (-not (Wait-HealthyService -Url "http://${BackendBindHost}:${BackendPort}/api/v1/health" -TimeoutSec $HealthReadyTimeoutSec -ExpectedServiceName $ExpectedService)) {
    Fail "Backend health check (direct, http://${BackendBindHost}:${BackendPort}/api/v1/health) did not return HTTP 200 with status=='ok' and service=='$ExpectedService' within $HealthReadyTimeoutSec seconds."
}
Write-Ok 'Backend health (direct) = 200, status=ok, correct service.'

if (-not (Wait-HealthyService -Url "http://${FrontendBindHost}:${FrontendPort}/api/v1/health" -TimeoutSec $HealthReadyTimeoutSec -ExpectedServiceName $ExpectedService)) {
    Fail "Backend health via Vite (http://${FrontendBindHost}:${FrontendPort}/api/v1/health) did not return HTTP 200 with status=='ok' and service=='$ExpectedService' within $HealthReadyTimeoutSec seconds."
}
Write-Ok 'Backend health (via Vite) = 200, status=ok, correct service.'

# =====================================================================================
# Step 8 - csrf-cookie must be 204 via Vite.
# =====================================================================================
Write-Step 'Step 8: csrf-cookie check (via Vite)'

try {
    $csrf = Invoke-WebRequest -Uri "http://${FrontendBindHost}:${FrontendPort}/api/v1/auth/csrf-cookie" -UseBasicParsing -TimeoutSec $CsrfTimeoutSec
    if ($csrf.StatusCode -ne 204) { Fail "csrf-cookie via Vite returned HTTP $($csrf.StatusCode), expected 204." }
} catch {
    Fail "csrf-cookie check via Vite failed: $($_.Exception.Message)"
}
Write-Ok 'csrf-cookie via Vite = 204.'

# =====================================================================================
# Step 9 - All checks passed: open the browser.
# =====================================================================================
Write-Step 'Step 9: All checks passed - opening browser'
Start-Process "http://${FrontendBindHost}:${FrontendPort}/"

Write-Host ''
Write-Host "MasarHR is ready: Backend http://${BackendBindHost}:${BackendPort}  |  Frontend http://${FrontendBindHost}:${FrontendPort}" -ForegroundColor Green
exit 0
