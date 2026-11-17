$ErrorActionPreference = 'Stop'

$phpPath = (Get-Command php -ErrorAction Stop).Source
$definitions = @(
    @{ Name = 'SIA101-LifeCycleTrack-Central-Outbox'; Root = 'D:\xampp\htdocs\LifeCycleTrack'; Script = 'scripts\publish_central_outbox.php'; Args = '' },
    @{ Name = 'SIA101-LifeCycleTrack-Central-Inbox'; Root = 'D:\xampp\htdocs\LifeCycleTrack'; Script = 'scripts\consume_central_inbox.php'; Args = '10' },
    @{ Name = 'SIA101-MaintainTrack-Central-Outbox'; Root = 'D:\xampp\htdocs\MaintainTrack'; Script = 'scripts\publish_central_outbox.php'; Args = '20' },
    @{ Name = 'SIA101-MaintainTrack-Central-Inbox'; Root = 'D:\xampp\htdocs\MaintainTrack'; Script = 'scripts\maintain_integration_worker.php'; Args = '--once' },
    @{ Name = 'SIA101-UtilityTrack-Central-Outbox'; Root = 'D:\xampp\htdocs\UtilityTrack'; Script = 'scripts\publish_central_outbox.php'; Args = '20' },
    @{ Name = 'SIA101-UtilityTrack-Central-Inbox'; Root = 'D:\xampp\htdocs\UtilityTrack'; Script = 'scripts\central_inbox_worker.php'; Args = '10' }
)

foreach ($definition in $definitions) {
    $scriptPath = Join-Path $definition.Root $definition.Script
    if (-not (Test-Path -LiteralPath $scriptPath)) { throw "Worker script not found: $scriptPath" }

    $arguments = if ($definition.Args) { "$($definition.Script) $($definition.Args)" } else { $definition.Script }
    $action = New-ScheduledTaskAction -Execute $phpPath -Argument $arguments -WorkingDirectory $definition.Root
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
        -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
    $principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" `
        -LogonType Interactive -RunLevel Limited

    Register-ScheduledTask -TaskName $definition.Name -Action $action -Trigger $trigger `
        -Settings $settings -Principal $principal -Description 'Processes SIA101 Central integration events.' -Force | Out-Null
    Write-Host "Registered $($definition.Name)"
}

Write-Host 'Subsystem workers are scheduled every minute while this Windows user is signed in.'
