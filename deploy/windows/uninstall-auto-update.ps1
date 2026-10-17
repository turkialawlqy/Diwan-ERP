try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }
foreach ($task in @('Alostaz Auto Update', 'Alostaz Scheduler', 'Alostaz Queue')) {
    Unregister-ScheduledTask -TaskName $task -Confirm:$false -ErrorAction SilentlyContinue
}
Write-Host 'تم إيقاف التحديث التلقائي والمُجدول والطوابير.' -ForegroundColor Green
