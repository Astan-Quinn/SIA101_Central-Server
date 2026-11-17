$ErrorActionPreference = 'Stop'

$phpPath = (Get-Command php -ErrorAction Stop).Source
$definitions = @(
    @{ Name = 'SIA101-LifeCycleTrack-Central-Reconcile'; Root = 'D:\xampp\htdocs\LifeCycleTrack'; Script = 'scripts\backfill_central_assets.php'; Args = '100 --reconcile' },
    @{ Name = 'SIA101-MaintainTrack-Central-Reconcile'; Root = 'D:\xampp\htdocs\MaintainTrack'; Script = 'scripts\backfill_central_records.php'; Args = '--reconcile --limit=100' },
    @{ Name = 'SIA101-UtilityTrack-Central-Reconcile'; Root = 'D:\xampp\htdocs\UtilityTrack'; Script = 'scripts\backfill_central_records.php'; Args = '--reconcile --limit=100' }
)

foreach ($definition in $definitions) {
    $scriptPath = Join-Path $definition.Root $definition.Script
    if (-not (Test-Path -LiteralPath $scriptPath)) { throw "Reconciliation script not found: $scriptPath" }
    $action = New-ScheduledTaskAction -Execute $phpPath `
        -Argument "$($definition.Script) $($definition.Args)" -WorkingDirectory $definition.Root
    $trigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Sunday -At ([datetime]::Today.AddDays(1).Date.AddHours(3).AddMinutes(30))
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
        -ExecutionTimeLimit (New-TimeSpan -Hours 2)
    $principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" `
        -LogonType Interactive -RunLevel Limited
    Register-ScheduledTask -TaskName $definition.Name -Action $action -Trigger $trigger `
        -Settings $settings -Principal $principal `
        -Description 'Reconciles authoritative subsystem snapshots through the Central integration hub.' -Force | Out-Null
    Write-Host "Registered weekly reconciliation: $($definition.Name)"
}

Write-Host 'Reconciliation runs Sundays at 03:30 while this Windows user is signed in; missed runs start when available.'
