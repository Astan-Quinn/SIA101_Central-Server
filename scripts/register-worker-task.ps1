param(
    [string] $PhpPath = (Get-Command php -ErrorAction Stop).Source,
    [string] $TaskName = 'SIA101-Central-Supabase-Queue-Worker'
)

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$workerPath = Join-Path $projectRoot 'src\worker.php'
if (-not (Test-Path -LiteralPath $workerPath)) { throw "Worker script not found: $workerPath" }
if (-not (Test-Path -LiteralPath $PhpPath)) { throw "PHP executable not found: $PhpPath" }

$action = New-ScheduledTaskAction -Execute $PhpPath -Argument 'src\worker.php 10' -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" `
    -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
    -Settings $settings -Principal $principal -Description 'Processes Supabase central_sync_events and retries visible messages.' -Force | Out-Null
Write-Host "Registered $TaskName to poll every minute while this Windows user is signed in."
