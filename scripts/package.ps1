#requires -Version 5.1
<#
.SYNOPSIS
    Empacota ml-link-auditor/ em um ZIP instalavel seguindo AGENT_RULES.md.

.DESCRIPTION
    - Pasta raiz do ZIP: ml-link-auditor/
    - Exclui .git/, .DS_Store, Thumbs.db, *.log, node_modules/, .idea/, .vscode/
    - Nome do arquivo: ml-link-auditor-vX.Y.Z.zip (lowercase, dots)
    - Roda sync-version.ps1 antes de empacotar.

.PARAMETER Version
    Versao no formato X.Y.Z (sem prefixo v).

.EXAMPLE
    .\package.ps1 -Version 1.3.3
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$Version
)

$ErrorActionPreference = "Stop"

$root       = Resolve-Path (Join-Path $PSScriptRoot "..")
$pluginDir  = Join-Path $root "ml-link-auditor"
$distDir    = Join-Path $root "dist"
$pluginFile = Join-Path $pluginDir "ml-link-auditor.php"

# Valida formato
if ($Version -notmatch '^\d+\.\d+\.\d+$') {
    Write-Error "[package] Versao invalida: '$Version' (esperado X.Y.Z)"
    exit 1
}

# Sincronizacao de versao
& (Join-Path $PSScriptRoot "sync-version.ps1") | Out-Null
if ($LASTEXITCODE -ne 0) { exit 1 }

# Confere que a versao pedida eh a que esta no plugin
$headerVer = (Select-String -Path $pluginFile -Pattern '^\s*\*\s*Version:\s*(\S+)' | Select-Object -First 1).Matches.Groups[1].Value
if ($headerVer -ne $Version) {
    Write-Error "[package] Versao do header ($headerVer) difere da pedida ($Version)"
    exit 1
}

# Caminho do ZIP (lowercase com pontos - convencao do ml-link-auditor)
$zipName     = "ml-link-auditor-v$Version.zip"
$zipPath     = Join-Path $distDir $zipName

New-Item -ItemType Directory -Force -Path $distDir | Out-Null
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

# Build a staging copy of the plugin folder so we can drop the ZIP root at
# "ml-link-auditor/" (required by AGENT_RULES) without nesting ml-link-auditor/
# inside itself. Compress-Archive cannot exclude entries on its own, so we
# stage a clean tree first and then archive it.
$parentDir  = Split-Path $pluginDir -Parent
$folderName = Split-Path $pluginDir -Leaf
$stageDir   = Join-Path $env:TEMP ("mlla-stage-" + [Guid]::NewGuid().ToString('N'))
$stagePlugin = Join-Path $stageDir $folderName
New-Item -ItemType Directory -Force -Path $stagePlugin | Out-Null

# Exclusion patterns applied relative to the staging root.
$exclude = @(
    '\.git(/|\\|$)',
    '\.DS_Store$',
    'Thumbs\.db$',
    'Desktop\.ini$',
    '\.log$',
    '\.swp$',
    '\.swo$',
    'node_modules(/|\\|$)',
    'vendor(/|\\|$)',
    '\.zip$'
)

function Test-Excluded([string]$relative) {
    foreach ($pattern in $exclude) {
        if ($relative -match $pattern) { return $true }
    }
    return $false
}

Get-ChildItem -Path $pluginDir -Recurse -Force | ForEach-Object {
    $relative = $_.FullName.Substring($pluginDir.Length).TrimStart('\', '/')
    if ([string]::IsNullOrEmpty($relative)) { return }
    if (Test-Excluded $relative) { return }

    $target = Join-Path $stagePlugin $relative
    if ($_.PSIsContainer) {
        New-Item -ItemType Directory -Force -Path $target | Out-Null
    } else {
        $targetDir = Split-Path $target -Parent
        if (-not (Test-Path $targetDir)) {
            New-Item -ItemType Directory -Force -Path $targetDir | Out-Null
        }
        Copy-Item -LiteralPath $_.FullName -Destination $target -Force
    }
}

Push-Location $stageDir
try {
    Compress-Archive -Path $folderName -DestinationPath $zipPath -CompressionLevel Optimal
} finally {
    Pop-Location
}

# Windows PowerShell writes entry paths with "\" separators; the rest of the
# release pipeline (and the WP plugin uploader) expects POSIX "/" separators.
# Normalize every entry name and add explicit directory entries to match the
# structure of previous releases (the WordPress uploader and CI tooling expect
# to see every folder as its own entry).
Add-Type -AssemblyName System.IO.Compression.FileSystem
$tempZip = $zipPath + ".tmp"
$source   = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
$dest     = [System.IO.Compression.ZipFile]::Open($tempZip, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $seenDirs = New-Object 'System.Collections.Generic.HashSet[string]'

    foreach ($entry in $source.Entries) {
        $normalized = $entry.FullName -replace '\\', '/'

        # Emit explicit directory entries for every folder in the path so the
        # resulting archive mirrors the layout produced by the bash packager.
        $parts = $normalized.Split('/')
        for ($i = 0; $i -lt $parts.Length - 1; $i++) {
            $dirPath = ($parts[0..$i] -join '/') + '/'
            if ($seenDirs.Add($dirPath)) {
                $dest.CreateEntry($dirPath)
            }
        }

        # If the source entry is itself a directory, skip writing it again
        # (we already created the explicit entry above).
        if ($normalized.EndsWith('/')) {
            continue
        }

        $writer = $dest.CreateEntry($normalized, [System.IO.Compression.CompressionLevel]::Optimal)
        $readerStream = $entry.Open()
        $writerStream = $writer.Open()
        try {
            $readerStream.CopyTo($writerStream)
        } finally {
            $readerStream.Dispose()
            $writerStream.Dispose()
        }
    }
} finally {
    $source.Dispose()
    $dest.Dispose()
}
Remove-Item -LiteralPath $zipPath -Force
Move-Item -LiteralPath $tempZip -Destination $zipPath -Force

Remove-Item -LiteralPath $stageDir -Recurse -Force

$sha  = (Get-FileHash -Path $zipPath -Algorithm SHA256).Hash
$size = (Get-Item $zipPath).Length

Write-Host "[package] OK"
Write-Host "  Arquivo: $zipName"
Write-Host "  Tamanho: $size bytes"
Write-Host "  SHA-256: $sha"
Write-Host "  Caminho: $zipPath"