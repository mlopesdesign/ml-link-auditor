#requires -Version 5.1
<#
.SYNOPSIS
    Fluxo completo de release: backup, sync, package, instrucoes de commit/tag/push/release.

.DESCRIPTION
    Nao executa git push nem cria release sozinho - so prepara tudo e imprime
    os comandos para voce revisar. Apos rodar, copie/cole os comandos sugeridos.

.PARAMETER Version
    Versao no formato X.Y.Z (sem prefixo v). Sera convertida para vX.Y.Z como tag.

.PARAMETER Title
    Titulo da release (ex.: "ML Link Auditor v1.3.3 - Correcao de update").

.PARAMETER Notes
    Notas da release (markdown). Se vazio, gera um template basico.

.EXAMPLE
    .\release.ps1 -Version 1.3.3 -Title "ML Link Auditor v1.3.3 - Fix" -Notes "Corrige X."
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$Version,
    [string]$Title = "",
    [string]$Notes = ""
)

$ErrorActionPreference = "Stop"
$root = Resolve-Path (Join-Path $PSScriptRoot "..")
Set-Location $root

if ($Version -notmatch '^\d+\.\d+\.\d+$') {
    Write-Error "[release] Versao invalida: '$Version' (esperado X.Y.Z)"
    exit 1
}

$tag           = "v$Version"
$zipName       = "ml-link-auditor-v$Version.zip"
$distZip       = Join-Path $root "dist\$zipName"
$changesReadme = Join-Path $root "ml-link-auditor\readme.txt"

# 1) Sync
Write-Host "`n=== [1/4] Validando sincronizacao de versao ===" -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "sync-version.ps1")
if ($LASTEXITCODE -ne 0) { exit 1 }

# 2) Backup pre-release
Write-Host "`n=== [2/4] Backup pre-release ===" -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "backup.ps1") -Reason "pre-release-$tag"
if ($LASTEXITCODE -ne 0) { exit 1 }

# 3) Build ZIP
Write-Host "`n=== [3/4] Empacotando ===" -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "package.ps1") -Version $Version
if ($LASTEXITCODE -ne 0) { exit 1 }

# 4) Resumo + proximos passos
Write-Host "`n=== [4/4] Resumo da release ===" -ForegroundColor Cyan

if (-not $Title) {
    $Title = "ML Link Auditor v$Version"
}

$sha  = (Get-FileHash -Path $distZip -Algorithm SHA256).Hash
$size = (Get-Item $distZip).Length

Write-Host ""
Write-Host "  Tag    : $tag"  -ForegroundColor Yellow
Write-Host "  Titulo : $Title" -ForegroundColor Yellow
Write-Host "  ZIP    : $zipName ($size bytes)" -ForegroundColor Yellow
Write-Host "  SHA-256: $sha"   -ForegroundColor Yellow
Write-Host ""

# Notas default
if (-not $Notes) {
    $Notes = @"
## ML Link Auditor $tag

### ZIP
\`$zipName\`

### SHA-256
\`$sha\`

### Tamanho
$size bytes

### Mudancas
Veja `ml-link-auditor/readme.txt` para o changelog completo.
"@
}

Write-Host "Comandos sugeridos:" -ForegroundColor Green
Write-Host ""
Write-Host "  git add -A"
Write-Host "  git -c user.name=mlopesdesign -c user.email=mlopesdesign@gmail.com commit -m `"release: $tag`""
Write-Host "  git -c user.name=mlopesdesign -c user.email=mlopesdesign@gmail.com tag -a $tag -m `"$Title`""
Write-Host "  git push origin main --follow-tags"
Write-Host ""
Write-Host "Depois (com gh autenticado):"
Write-Host ""
Write-Host "  gh release create $tag `"$distZip`" --title `"$Title`" --notes @`"$changesReadme`""
Write-Host ""