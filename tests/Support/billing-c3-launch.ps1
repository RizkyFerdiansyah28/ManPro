param(
    [Parameter(Mandatory = $true)][string] $ApprovedManifest,
    [Parameter(Mandatory = $true)][string] $ApprovedDependencyPolicy,
    [switch] $VerifyOnly
)

$ErrorActionPreference = 'Stop'

function Assert-Condition([bool] $Condition, [string] $Reason) {
    if (!$Condition) { throw [InvalidOperationException]::new($Reason) }
}

function Assert-NativePath([string] $Path, [bool] $Directory) {
    Assert-Condition ($Path -match '^[A-Za-z]:[\\/]') 'billing_c3_dependency_path_alias'
    Assert-Condition ($Path -notmatch '[~\x00-\x1f]' -and $Path -notmatch '[ .]([\\/]|$)') 'billing_c3_dependency_path_alias'
    $normalized = $Path.Replace('/', '\')
    Assert-Condition ([IO.Path]::GetFullPath($normalized) -ieq $normalized) 'billing_c3_dependency_path_alias'
    $cursor = $normalized
    while ($cursor) {
        $attrs = [IO.File]::GetAttributes($cursor)
        Assert-Condition (($attrs -band [IO.FileAttributes]::ReparsePoint) -eq 0) 'billing_c3_dependency_path_reparse'
        $expectedDir = ($cursor -ine $normalized) -or $Directory
        Assert-Condition ((($attrs -band [IO.FileAttributes]::Directory) -ne 0) -eq $expectedDir) 'billing_c3_dependency_path_type'
        $cursor = [IO.Path]::GetDirectoryName($cursor)
    }
}

function Read-ByteHash([string] $Path) {
    $algorithm = [Security.Cryptography.SHA256]::Create()
    $stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
    try { return [BitConverter]::ToString($algorithm.ComputeHash($stream)).Replace('-', '').ToLowerInvariant() }
    finally { $stream.Dispose(); $algorithm.Dispose() }
}

function Read-PinnedRecord([string] $Path, [string] $Digest) {
    Assert-Condition ([IO.File]::Exists($Path)) 'billing_c3_dependency_policy_missing'
    Assert-NativePath $Path $false
    Assert-Condition ($Digest -cmatch '^[a-f0-9]{64}$' -and [IO.FileInfo]::new($Path).Length -le 1000000) 'billing_c3_dependency_policy_invalid'
    # Parse exactly the bytes whose expected digest was provided by the reviewer.
    $bytes = [IO.File]::ReadAllBytes($Path)
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try { $actual = [BitConverter]::ToString($algorithm.ComputeHash($bytes)).Replace('-', '').ToLowerInvariant() }
    finally { $algorithm.Dispose() }
    Assert-Condition ($actual -ceq $Digest) 'billing_c3_dependency_policy_digest_mismatch'
    return ConvertFrom-Json -InputObject ([Text.Encoding]::UTF8.GetString($bytes))
}

function Add-TreeRecords([string] $Dir, [string] $Prefix, $Algorithm, [Diagnostics.Stopwatch] $Clock) {
    $entries = [string[]][IO.Directory]::GetFileSystemEntries($Dir)
    [Array]::Sort($entries, [StringComparer]::Ordinal)
    foreach ($entry in $entries) {
        Assert-Condition ($Clock.Elapsed.TotalSeconds -lt 60) 'billing_c3_dependency_deadline_exceeded'
        $name = [IO.Path]::GetFileName($entry)
        Assert-Condition ($name -cnotmatch '[\x00-\x1f\x7f-\uffff]') 'billing_c3_dependency_path_invalid'
        $relative = $Prefix + $name
        $directory = [IO.Directory]::Exists($entry)
        Assert-NativePath $entry $directory
        $record = if ($directory) { "D`t$relative`n" } else { "F`t$relative`t$(Read-ByteHash $entry)`n" }
        $bytes = [Text.Encoding]::UTF8.GetBytes($record)
        [void]$Algorithm.TransformBlock($bytes, 0, $bytes.Length, $bytes, 0)
        if ($directory) { Add-TreeRecords $entry ($relative + '/') $Algorithm $Clock }
    }
}

function Read-TreeHash([string] $Root, [Diagnostics.Stopwatch] $Clock) {
    Assert-Condition ($Clock.Elapsed.TotalSeconds -lt 60) 'billing_c3_dependency_deadline_exceeded'
    Assert-NativePath $Root $true
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try {
        Add-TreeRecords $Root '' $algorithm $Clock
        Assert-Condition ($Clock.Elapsed.TotalSeconds -lt 60) 'billing_c3_dependency_deadline_exceeded'
        [void]$algorithm.TransformFinalBlock([byte[]]@(), 0, 0)
        return [BitConverter]::ToString($algorithm.Hash).Replace('-', '').ToLowerInvariant()
    }
    finally { $algorithm.Dispose() }
}

try {
    $bootstrapClock = [Diagnostics.Stopwatch]::StartNew()
    $system = [Environment]::SystemDirectory
    $windows = [IO.Directory]::GetParent($system).FullName
    $expectedShell = [IO.Path]::Combine($system, 'WindowsPowerShell\v1.0\powershell.exe')
    $shell = [Diagnostics.Process]::GetCurrentProcess().MainModule.FileName
    Assert-Condition ([Environment]::Is64BitProcess -and $shell -ieq $expectedShell) 'billing_c3_windows_helper_profile_required'
    Assert-NativePath $shell $false
    Assert-Condition ((Read-ByteHash $shell) -ceq '95ce2acbc6cd9d7f4a22759d39a8d40fc2a53ceeecf068ef856849f0d2076961') 'billing_c3_windows_helper_hash_mismatch'
    Assert-Condition (($env:SystemRoot -eq $null -or $env:SystemRoot -ieq $windows) -and ($env:WINDIR -eq $null -or $env:WINDIR -ieq $windows) -and
        ($env:ComSpec -eq $null -or $env:ComSpec -ieq "$system\cmd.exe")) 'billing_c3_windows_environment_override'
    $env:PSModulePath = $PSHOME + '\Modules'
    $project = [IO.Directory]::GetParent([IO.Directory]::GetParent($PSScriptRoot).FullName).FullName
    $manifest = Read-PinnedRecord "$PSScriptRoot\billing-c3-source-manifest.json" $ApprovedManifest
    $policy = Read-PinnedRecord "$PSScriptRoot\billing-c3-dependency-policy.json" $ApprovedDependencyPolicy
    Assert-Condition ($policy.version -eq 1 -and $policy.source_manifest_sha256 -ceq $ApprovedManifest) 'billing_c3_dependency_policy_invalid'

    foreach ($name in @('billing-c3-launch.ps1', 'billing-c3-supervisor.php', 'BillingC3ProcessJob.php',
        'run-payment-r2-integration.php', 'BillingC3Admission.php', 'BillingC3Bootstrap.php')) {
        $source = "$PSScriptRoot\$name"
        Assert-NativePath $source $false
        $pin = $manifest.files.PSObject.Properties["tests/Support/$name"].Value
        Assert-Condition ((Read-ByteHash $source) -ceq $pin) 'billing_c3_dependency_source_pin_mismatch'
    }
    $phpRoot = [string]$policy.roots.php.path
    $php = [string]$manifest.tools.php.path
    Assert-Condition ("$phpRoot\php.exe" -ieq $php) 'billing_c3_dependency_runtime_root_mismatch'
    Assert-Condition ((Read-TreeHash $phpRoot $bootstrapClock) -ceq $policy.roots.php.sha256) 'billing_c3_dependency_tree_mismatch'
    Assert-Condition ((Read-ByteHash $php) -ceq $manifest.tools.php.sha256) 'billing_c3_dependency_php_executable_mismatch'
    Assert-Condition ($bootstrapClock.Elapsed.TotalSeconds -lt 60) 'billing_c3_dependency_deadline_exceeded'
    if ($VerifyOnly) {
        [Console]::WriteLine('{"status":"PHP_LAUNCH_PROFILE_VERIFIED"}')
        exit 0
    }

    $ext = "$phpRoot/ext"
    $arguments = @('-n', '-d', "extension_dir=$ext", '-d', 'ffi.enable=1', '-d', 'opcache.enable_cli=0', '-d', 'opcache.enable=0',
        '-d', 'include_path=.', '-d', 'zend.exception_ignore_args=1', '-d', 'display_errors=0', '-d', 'log_errors=0')
    foreach ($name in @('curl', 'ffi', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_pgsql')) {
        $arguments += @('-d', "extension=$ext/php_$name.dll")
    }
    $arguments += @("$project\tests\Support\billing-c3-supervisor.php", '--launch', $policy.roots.'postgres-bin'.path, $ApprovedManifest)
    $quoted = foreach ($argument in $arguments) {
        Assert-Condition ($argument -notmatch '["\x00-\x1f]' -and $argument -notmatch '\\$') 'billing_c3_php_boot_argument_invalid'
        '"' + $argument + '"'
    }
    $start = [Diagnostics.ProcessStartInfo]::new($php, ($quoted -join ' '))
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.WorkingDirectory = $system
    $start.EnvironmentVariables.Clear()
    $start.EnvironmentVariables['SystemRoot'] = $windows
    $start.EnvironmentVariables['WINDIR'] = $windows
    $start.EnvironmentVariables['ComSpec'] = "$system\cmd.exe"
    $start.EnvironmentVariables['PATH'] = $system
    $start.EnvironmentVariables['TEMP'] = [IO.Path]::GetTempPath()
    $start.EnvironmentVariables['TMP'] = $start.EnvironmentVariables['TEMP']
    $start.EnvironmentVariables['SILANSIA_BILLING_C3_DEPENDENCY_POLICY'] = $ApprovedDependencyPolicy
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $bytes = [byte[]]::new(32)
        $generator.GetBytes($bytes)
        $jobToken = [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
    } finally { $generator.Dispose() }
    $start.EnvironmentVariables['SILANSIA_BILLING_C3_JOB'] = $jobToken
    $launcher = [Diagnostics.Process]::GetCurrentProcess()
    try {
        $start.EnvironmentVariables['SILANSIA_BILLING_C3_SHELL_PID'] = [string]$launcher.Id
        $start.EnvironmentVariables['SILANSIA_BILLING_C3_SHELL_BIRTH'] = $launcher.StartTime.ToFileTimeUtc().ToString('x16')
    } finally { $launcher.Dispose() }
    $process = [Diagnostics.Process]::Start($start)
    try {
        # Independent watchdog. The native shim normally finishes its job deadline and drain first.
        if (!$process.WaitForExit(2030000)) {
            $event = $null
            try {
                $event = [Threading.EventWaitHandle]::OpenExisting("Local\SilansiaC3-$jobToken-Revoked")
                if (!$event.Set()) { throw [InvalidOperationException]::new('billing_c3_job_revocation_unverified') }
            } catch [Threading.WaitHandleCannotBeOpenedException] {
                # No event can exist before the shim creates the job. A failed shim may also have closed it.
            } finally {
                if ($null -ne $event) { $event.Dispose() }
            }
            if (!$process.HasExited) { $process.Kill() }
            Assert-Condition ($process.WaitForExit(12000)) 'billing_c3_job_exit_unverified'
            throw [InvalidOperationException]::new('billing_c3_supervisor_watchdog')
        }
        exit $process.ExitCode
    }
    finally { $process.Dispose() }
} catch {
    $reason = $_.Exception.Message
    if ($reason -cnotmatch '^billing_c3_[a-z0-9_]+$') { $reason = 'billing_c3_native_launch_verification_failed' }
    [Console]::WriteLine('{"status":"BLOCKED","reason":"' + $reason + '"}')
    exit 78
}
