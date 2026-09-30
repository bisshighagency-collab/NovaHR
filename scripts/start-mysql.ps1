# Starts NovaHR's own MySQL server on port 3399. Its data files live in
# %LOCALAPPDATA%\NovaHR\mysql-data - deliberately NOT inside this project folder,
# because MySQL/InnoDB fails to start when its data sits on the Windows Desktop
# (and Desktop/OneDrive folders would also try to sync the database files).
#
# First run: creates the database files and imports database/schema.sql + seed.sql.
# Later runs: applies any new database/migrations/NNN_*.sql files the existing
# database hasn't had yet (the applied schema version is kept in the marker file),
# so app updates never require wiping your data.
#
# Uses the MySQL that ships with Laragon; change $mysqlBase if yours lives elsewhere.
# NOVAHR_DB_PORT / NOVAHR_DATA_DIR environment variables override the port and data folder.

$ErrorActionPreference = 'Continue'

$root      = Split-Path -Parent $PSScriptRoot
$mysqlBase = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64'
$mysqld    = Join-Path $mysqlBase 'bin\mysqld.exe'
$mysql     = Join-Path $mysqlBase 'bin\mysql.exe'
$mysqladm  = Join-Path $mysqlBase 'bin\mysqladmin.exe'
$dataDir   = if ($env:NOVAHR_DATA_DIR) { $env:NOVAHR_DATA_DIR } else { Join-Path $env:LOCALAPPDATA 'NovaHR\mysql-data' }
$marker    = Join-Path $dataDir '.novahr-seeded'
$port      = if ($env:NOVAHR_DB_PORT) { [int]$env:NOVAHR_DB_PORT } else { 3399 }
$migrationsDir = Join-Path $root 'database\migrations'

foreach ($exe in @($mysqld, $mysql, $mysqladm)) {
    if (-not (Test-Path $exe)) {
        Write-Host "MySQL not found at $exe" -ForegroundColor Red
        Write-Host 'Edit $mysqlBase at the top of scripts/start-mysql.ps1 to point at your MySQL install.' -ForegroundColor Red
        exit 1
    }
}

function Test-MySqlUp {
    & $mysqladm --no-defaults --host=127.0.0.1 --port=$port --user=root ping 2>$null | Out-Null
    return ($LASTEXITCODE -eq 0)
}

function Get-Migrations {
    if (-not (Test-Path $migrationsDir)) { return @() }
    Get-ChildItem $migrationsDir -Filter '*.sql' |
        Where-Object { $_.Name -match '^(\d+)_' } |
        ForEach-Object { [pscustomobject]@{ Version = [int]($_.Name -replace '^(\d+)_.*', '$1'); File = $_.FullName; Name = $_.Name } } |
        Sort-Object Version
}

function Get-LatestVersion {
    $m = @(Get-Migrations)
    if ($m.Count -eq 0) { return 1 }
    return ($m | Select-Object -Last 1).Version
}

# Version 1 = the original schema (an empty marker from older launchers counts as 1).
function Get-SchemaVersion {
    if (-not (Test-Path $marker)) { return 0 }
    $text = (Get-Content $marker -Raw -ErrorAction SilentlyContinue)
    if ($text -and $text.Trim() -match '^\d+$') { return [int]$text.Trim() }
    return 1
}

function Invoke-SqlFile([string]$path, [string]$database) {
    $unix = $path -replace '\\', '/'
    $dbArgs = @()
    if ($database) { $dbArgs = @($database) }
    & $mysql --no-defaults --host=127.0.0.1 --port=$port --user=root --default-character-set=utf8mb4 @dbArgs -e "source $unix"
    return ($LASTEXITCODE -eq 0)
}

function Update-Schema {
    $current = Get-SchemaVersion
    foreach ($m in (Get-Migrations | Where-Object { $_.Version -gt $current })) {
        Write-Host "Updating the database: applying $($m.Name)..."
        if (-not (Invoke-SqlFile $m.File 'novahr')) { throw "Migration $($m.Name) failed." }
        Set-Content -Path $marker -Value $m.Version -Encoding ascii
    }
}

if (Test-MySqlUp) {
    Write-Host "MySQL is already running on port $port - reusing it."
    if (Test-Path $marker) { Update-Schema }
    Write-Host "NovaHR database ready on 127.0.0.1:$port (already running)"
    while ($true) { Start-Sleep -Seconds 3600 }
}

# A data folder without the marker is a half-finished first run: start clean.
if ((Test-Path $dataDir) -and -not (Test-Path $marker)) {
    Write-Host 'Removing incomplete database folder from an earlier interrupted run...'
    Remove-Item -Recurse -Force $dataDir
}

$firstRun = -not (Test-Path $dataDir)
if ($firstRun) {
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dataDir) | Out-Null
    Write-Host 'First run: creating the NovaHR database files (this can take a minute or two)...'
    & $mysqld --no-defaults --initialize-insecure --console "--basedir=$mysqlBase" "--datadir=$dataDir"
    if ($LASTEXITCODE -ne 0) { Write-Host 'MySQL initialisation failed.' -ForegroundColor Red; exit 1 }
}

$serverArgs = @(
    '--no-defaults', '--console',
    "--basedir=`"$mysqlBase`"", "--datadir=`"$dataDir`"",
    "--port=$port", '--bind-address=127.0.0.1',
    '--mysqlx=OFF', '--skip-log-bin',
    '--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci'
)
$server = Start-Process -FilePath $mysqld -ArgumentList $serverArgs -NoNewWindow -PassThru

try {
    $ready = $false
    for ($i = 0; $i -lt 90; $i++) {
        if ($server.HasExited) { break }
        if (Test-MySqlUp) { $ready = $true; break }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) {
        Write-Host "MySQL did not start (is another program using port ${port}?)." -ForegroundColor Red
        exit 1
    }

    if ($firstRun) {
        Write-Host 'Importing schema.sql and seed.sql...'
        if (-not (Invoke-SqlFile (Join-Path $root 'database\schema.sql') '')) { throw 'Importing schema.sql failed.' }
        if (-not (Invoke-SqlFile (Join-Path $root 'database\seed.sql') 'novahr')) { throw 'Importing seed.sql failed.' }
        # schema.sql already includes every migration, so start at the latest version.
        Set-Content -Path $marker -Value (Get-LatestVersion) -Encoding ascii
        Write-Host 'Database created and seeded with the demo accounts.'
    } else {
        Update-Schema
    }

    Write-Host "NovaHR database ready on 127.0.0.1:$port"
    $server.WaitForExit()
}
finally {
    if (-not $server.HasExited) {
        & $mysqladm --no-defaults --host=127.0.0.1 --port=$port --user=root shutdown 2>$null | Out-Null
        $server.WaitForExit(15000) | Out-Null
    }
}
