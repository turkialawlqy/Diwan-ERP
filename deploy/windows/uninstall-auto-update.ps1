try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }
# Tasks registered under the product's former name are removed as well.
foreach ($task in @('Diwan Auto Update', 'Diwan Scheduler', 'Diwan Queue', 'Alostaz Auto Update', 'Alostaz Scheduler', 'Alostaz Queue')) {
    Unregister-ScheduledTask -TaskName $task -Confirm:$false -ErrorAction SilentlyContinue
}
Write-Host 'تم إيقاف التحديث التلقائي والمُجدول والطوابير.' -ForegroundColor Green
