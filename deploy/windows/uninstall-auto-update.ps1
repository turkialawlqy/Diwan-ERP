try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }
Unregister-ScheduledTask -TaskName 'Alostaz Auto Update' -Confirm:$false -ErrorAction SilentlyContinue
Write-Host 'تم إيقاف التحديث التلقائي.' -ForegroundColor Green
