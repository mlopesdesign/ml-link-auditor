#requires -Version 5.1
<#
.SYNOPSIS
    Verifica se a versao esta sincronizada em 3 pontos:
    - plugin header (Version:)
    - constante PHP (MLLA_VERSION)
    - readme.txt (Stable tag:)

.DESCRIPTION
    Falha (exit 1) se qualquer um estiver diferente.
    Tambem detecta formato invalido (espera X.Y.Z).

.EXAMPLE
    .\sync-version.ps1
#>
[CmdletBinding()]
param()

$ErrorActionPreference = "Stop"

$root        = Resolve-Path (Join-Path $PSScriptRoot "..")
$pluginFile  = Join-Path $root "ml-link-auditor\ml-link-auditor.php"
$readmeFile  = Join-Path $root "ml-link-auditor\readme.txt"

if (-not (Test-Path $pluginFile)) { Write-Error "[sync] Nao achei $pluginFile"; exit 1 }
if (-not (Test-Path $readmeFile)) { Write-Error "[sync] Nao achei $readmeFile"; exit 1 }

$header = (Select-String -Path $pluginFile -Pattern '^\s*\*\s*Version:\s*(\S+)' | Select-Object -First 1).Matches.Groups[1].Value
$const  = (Select-String -Path $pluginFile -Pattern "define\(\s*'MLLA_VERSION'\s*,\s*'([^']+)'" | Select-Object -First 1).Matches.Groups[1].Value
$readme = (Select-String -Path $readmeFile -Pattern '^\s*Stable tag:\s*(\S+)' | Select-Object -First 1).Matches.Groups[1].Value

$re = '^\d+\.\d+\.\d+$'

function Test-Format([string]$v) {
    if ($v -notmatch $re) { Write-Error "[sync] Formato invalido: '$v' (esperado X.Y.Z)"; exit 1 }
}

Test-Format $header
Test-Format $const
Test-Format $readme

Write-Host "Header   : $header"
Write-Host "Constante: $const"
Write-Host "Readme   : $readme"

if ($header -eq $const -and $const -eq $readme) {
    Write-Host "[sync] OK - todas as versoes batem em $header"
    exit 0
}

Write-Error "[sync] FALHA - header/constante/readme estao diferentes"
exit 1