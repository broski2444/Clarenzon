# One-time setup: verify clarenzonmarketing.online in Resend and add its DNS records at name.com.
# Run in your own PowerShell window:  powershell -ExecutionPolicy Bypass -File .\setup_resend_domain.ps1
# Delete this file afterwards; it is not part of the app.

$ErrorActionPreference = 'Stop'
$domain = 'clarenzonmarketing.online'

function Read-Secret([string]$prompt) {
    $s = Read-Host -Prompt $prompt -AsSecureString
    [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))
}

$resendKey = Read-Secret 'Resend API key (re_..., needs Full access)'
$ncUser    = Read-Host  'name.com username'
$ncToken   = Read-Secret 'name.com API token (name.com > Account > Security > API Token)'

$rsHeaders = @{ Authorization = "Bearer $resendKey"; 'Content-Type' = 'application/json' }

# 1. Find or create the domain in Resend
$existing = (Invoke-RestMethod -Uri 'https://api.resend.com/domains' -Headers $rsHeaders).data |
    Where-Object { $_.name -eq $domain } | Select-Object -First 1
if ($existing) {
    Write-Host "Domain already in Resend ($($existing.id))."
    $dom = Invoke-RestMethod -Uri "https://api.resend.com/domains/$($existing.id)" -Headers $rsHeaders
} else {
    Write-Host 'Adding domain to Resend...'
    $dom = Invoke-RestMethod -Method Post -Uri 'https://api.resend.com/domains' -Headers $rsHeaders `
        -Body (@{ name = $domain } | ConvertTo-Json)
}

# 2. Add each required record at name.com
$pair = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("${ncUser}:${ncToken}"))
$ncHeaders = @{ Authorization = "Basic $pair"; 'Content-Type' = 'application/json' }
$ncUrl = "https://api.name.com/v4/domains/$domain/records"
$current = @((Invoke-RestMethod -Uri $ncUrl -Headers $ncHeaders).records)

foreach ($r in $dom.records) {
    $host_ = ($r.name -replace "\.?$([regex]::Escape($domain))$", '')
    $dupe = $current | Where-Object { $_.host -eq $host_ -and $_.type -eq $r.type -and $_.answer -eq $r.value }
    if ($dupe) { Write-Host "Exists:  $($r.type) $host_"; continue }
    $body = @{ host = $host_; type = $r.type; answer = $r.value; ttl = 300 }
    if ($r.type -eq 'MX') { $body.priority = if ($r.priority) { [int]$r.priority } else { 10 } }
    Invoke-RestMethod -Method Post -Uri $ncUrl -Headers $ncHeaders -Body ($body | ConvertTo-Json) | Out-Null
    Write-Host "Added:   $($r.type) $host_"
}

# 3. Ask Resend to verify (DNS may take a few minutes; re-run this script to retry)
Invoke-RestMethod -Method Post -Uri "https://api.resend.com/domains/$($dom.id)/verify" -Headers $rsHeaders | Out-Null
Write-Host ''
Write-Host 'Verification requested. Check status at https://resend.com/domains'
Write-Host 'Then in Railway > Variables add:  RESEND_FROM=noreply@clarenzonmarketing.online'
