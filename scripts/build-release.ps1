param(
    [Parameter(Mandatory = $true)][string]$Destination,
    [switch]$SkipBuild,
    [switch]$AllowDirty
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$destinationPath = [System.IO.Path]::GetFullPath($Destination)
if ($destinationPath.StartsWith($projectRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Destination must be outside the project tree.'
}
if (Test-Path -LiteralPath $destinationPath) {
    throw 'Destination already exists; choose a new empty path.'
}
if (-not $AllowDirty -and (git -C $projectRoot status --porcelain)) {
    throw 'Working tree is not clean; commit/review changes or explicitly use -AllowDirty for a rehearsal.'
}

$allow = @('app', 'bootstrap', 'config', 'resources', 'routes')
New-Item -ItemType Directory -Path $destinationPath | Out-Null
foreach ($item in $allow) {
    $source = Join-Path $projectRoot $item
    if (Test-Path -LiteralPath $source) {
        Copy-Item -LiteralPath $source -Destination (Join-Path $destinationPath $item) -Recurse
    }
}
$databaseDestination = Join-Path $destinationPath 'database'
New-Item -ItemType Directory -Path $databaseDestination | Out-Null
foreach ($item in @('factories', 'migrations', 'seeders')) {
    $source = Join-Path $projectRoot "database/$item"
    if (Test-Path -LiteralPath $source) { Copy-Item -LiteralPath $source -Destination (Join-Path $databaseDestination $item) -Recurse }
}
$publicDestination = Join-Path $destinationPath 'public'
New-Item -ItemType Directory -Path $publicDestination -Force | Out-Null
Get-ChildItem -LiteralPath (Join-Path $projectRoot 'public') -Force | Where-Object Name -ne 'storage' | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $publicDestination $_.Name) -Recurse
}
foreach ($file in @('artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json')) {
    $source = Join-Path $projectRoot $file
    if (Test-Path -LiteralPath $source) { Copy-Item -LiteralPath $source -Destination $destinationPath }
}

if ($SkipBuild) {
    Copy-Item -LiteralPath (Join-Path $projectRoot 'vendor') -Destination (Join-Path $destinationPath 'vendor') -Recurse
} else {
    Push-Location $destinationPath
    try {
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts
        if ($LASTEXITCODE -ne 0) { throw 'Composer production install in staging failed.' }
        npm.cmd ci
        if ($LASTEXITCODE -ne 0) { throw 'npm ci in staging failed.' }
        npm.cmd run build
        if ($LASTEXITCODE -ne 0) { throw 'Frontend build in staging failed.' }
        $nodeModules = Join-Path $destinationPath 'node_modules'
        if (Test-Path -LiteralPath $nodeModules) { Remove-Item -LiteralPath $nodeModules -Recurse -Force }
    } finally {
        Pop-Location
    }
}

$runtimeDirectories = @(
    'storage/app/private', 'storage/app/public', 'storage/framework/cache',
    'storage/framework/sessions', 'storage/framework/views', 'storage/logs'
)
foreach ($directory in $runtimeDirectories) {
    New-Item -ItemType Directory -Path (Join-Path $destinationPath $directory) -Force | Out-Null
}

$forbidden = Get-ChildItem -LiteralPath $destinationPath -Recurse -Force | Where-Object {
    $_.Name -in @('.env', '.env.production', 'database.sqlite') -or
    $_.FullName -match '[\\/](node_modules|\.git|tests|test-results|playwright-report)([\\/]|$)' -or
    $_.Extension -in @('.log', '.mp4', '.mov', '.avi', '.mkv')
}
if ($forbidden) {
    throw ('Release denylist violation: ' + (($forbidden | ForEach-Object FullName) -join ', '))
}

Write-Host "Release staging created at $destinationPath"
Write-Host 'No .env, credentials, database, logs, tests, upload data, or video binaries were included.'
