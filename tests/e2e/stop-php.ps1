# Stops only the e2e PHP server (127.0.0.1:8765) and the e2e queue worker (--name=e2e); nothing else.
# The developer's own `queue:listen` children (plain `queue:work --once`) are left alone.
Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
    Where-Object { $_.CommandLine -match '127\.0\.0\.1:8765' -or $_.CommandLine -match 'queue:work.*--name=e2e' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -Confirm:$false }
