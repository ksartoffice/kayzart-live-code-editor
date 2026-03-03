param(
	[Parameter(Position = 0, Mandatory = $true)]
	[string]$DbName,

	[Parameter(Position = 1, Mandatory = $true)]
	[string]$DbUser,

	[Parameter(Position = 2)]
	[AllowEmptyString()]
	[string]$DbPass = "",

	[Parameter(Position = 3)]
	[string]$DbHost = "localhost",

	[Parameter(Position = 4)]
	[string]$WpVersion = "latest",

	[Parameter(Position = 5)]
	[bool]$SkipDbCreate = $false
)

$ErrorActionPreference = "Stop"

function Get-TempDir {
	if ($env:TMPDIR -and $env:TMPDIR.Trim() -ne "") {
		return $env:TMPDIR
	}
	return [System.IO.Path]::GetTempPath().TrimEnd('\')
}

function Download-File {
	param(
		[Parameter(Mandatory = $true)][string]$Url,
		[Parameter(Mandatory = $true)][string]$Destination,
		[int]$MaxRetries = 5
	)

	$destDir = Split-Path -Parent $Destination
	if (-not (Test-Path $destDir)) {
		New-Item -ItemType Directory -Path $destDir | Out-Null
	}

	$hasInvokeWebRequest = [bool](Get-Command Invoke-WebRequest -ErrorAction SilentlyContinue)
	$hasBitsTransfer = [bool](Get-Command Start-BitsTransfer -ErrorAction SilentlyContinue)
	if (-not $hasInvokeWebRequest -and -not $hasBitsTransfer) {
		throw "No download tool available (Invoke-WebRequest / Start-BitsTransfer)."
	}

	for ($attempt = 1; $attempt -le $MaxRetries; $attempt++) {
		try {
			if ($hasInvokeWebRequest) {
				Invoke-WebRequest -Uri $Url -OutFile $Destination -ErrorAction Stop
			} elseif ($hasBitsTransfer) {
				Start-BitsTransfer -Source $Url -Destination $Destination -ErrorAction Stop
			}
			return
		} catch {
			if (Test-Path $Destination) {
				Remove-Item -Force $Destination -ErrorAction SilentlyContinue
			}

			if ($attempt -eq $MaxRetries) {
				throw "Download failed from $Url after $MaxRetries attempts. $($_.Exception.Message)"
			}

			Write-Warning "Download failed from $Url (attempt $attempt/$MaxRetries): $($_.Exception.Message)"
			Start-Sleep -Seconds ([Math]::Min(10, $attempt * 2))
		}
	}
}

function Test-ZipArchive {
	param(
		[Parameter(Mandatory = $true)][string]$Path
	)

	if (-not (Test-Path $Path)) {
		return $false
	}

	Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction SilentlyContinue
	try {
		$archive = [System.IO.Compression.ZipFile]::OpenRead($Path)
		$archive.Dispose()
		return $true
	} catch {
		return $false
	}
}

function Show-DownloadPreview {
	param(
		[Parameter(Mandatory = $true)][string]$Path
	)

	if (-not (Test-Path $Path)) {
		return
	}

	Write-Warning "Download preview (first 5 lines):"
	Get-Content -Path $Path -TotalCount 5 -ErrorAction SilentlyContinue | ForEach-Object {
		Write-Warning $_
	}
}

function Get-TestsArchiveUrls {
	param(
		[Parameter(Mandatory = $true)][string]$Version
	)

	$urls = @()
	if ($Version -eq "latest" -or $Version -eq "nightly" -or $Version -eq "trunk") {
		$urls += "https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.zip"
		return $urls
	}

	$urls += "https://github.com/WordPress/wordpress-develop/archive/refs/tags/$Version.zip"
	if ($Version -match '^\d+\.\d+$') {
		$urls += "https://github.com/WordPress/wordpress-develop/archive/refs/tags/$Version.0.zip"
	}
	$urls += "https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.zip"

	return $urls
}

function Install-TestSuite {
	param(
		[Parameter(Mandatory = $true)][string]$Version,
		[Parameter(Mandatory = $true)][string]$TestsDir,
		[Parameter(Mandatory = $true)][string]$TempDir
	)

	$testsMarker = Join-Path $TestsDir "includes/class-basic-object.php"
	$testsConfig = Join-Path $TestsDir "wp-tests-config.php"
	if ((Test-Path $testsMarker) -and (Test-Path $testsConfig)) {
		return
	}

	if (Test-Path $TestsDir) {
		Remove-Item -Recurse -Force $TestsDir
	}
	New-Item -ItemType Directory -Path $TestsDir | Out-Null

	$testsZip = Join-Path $TempDir "wordpress-develop-tests.zip"
	$downloaded = $false
	foreach ($url in (Get-TestsArchiveUrls -Version $Version)) {
		Write-Host "Downloading WordPress develop tests from: $url"
		try {
			Download-File -Url $url -Destination $testsZip
			if (Test-ZipArchive -Path $testsZip) {
				$downloaded = $true
				break
			}

			Write-Warning "Downloaded file is not a valid zip: $url"
			Show-DownloadPreview -Path $testsZip
			Remove-Item -Force $testsZip -ErrorAction SilentlyContinue
		} catch {
			Write-Warning "Download failed from ${url}: $($_.Exception.Message)"
			$downloaded = $false
		}
	}

	if (-not $downloaded) {
		throw "Failed to download WordPress develop tests."
	}

	$extractDir = Join-Path $TempDir "wordpress-develop-tests"
	if (Test-Path $extractDir) {
		Remove-Item -Recurse -Force $extractDir
	}
	Expand-Archive -Path $testsZip -DestinationPath $extractDir -Force

	$rootDir = Get-ChildItem -Path $extractDir -Directory | Where-Object {
		Test-Path (Join-Path $_.FullName "tests/phpunit")
	} | Select-Object -First 1
	if (-not $rootDir -and (Test-Path (Join-Path $extractDir "tests/phpunit"))) {
		$rootDir = Get-Item -Path $extractDir
	}
	if (-not $rootDir) {
		throw "Could not locate wordpress-develop directory in tests archive."
	}

	$testsSource = Join-Path $rootDir.FullName "tests/phpunit"
	if (-not (Test-Path $testsSource)) {
		throw "tests/phpunit not found in tests archive."
	}

	Copy-Item -Path (Join-Path $testsSource "*") -Destination $TestsDir -Recurse -Force

	$sampleConfigSource = Join-Path $rootDir.FullName "wp-tests-config-sample.php"
	if (Test-Path $sampleConfigSource) {
		Copy-Item -Path $sampleConfigSource -Destination (Join-Path $TestsDir "wp-tests-config-sample.php") -Force
	}
}

function Find-MySqlCommand {
	$mysql = Get-Command mysql -ErrorAction SilentlyContinue
	if ($mysql) {
		return $mysql.Source
	}

	$candidates = @()
	if ($env:XAMPP_HOME -and $env:XAMPP_HOME.Trim() -ne "") {
		$candidates += (Join-Path $env:XAMPP_HOME "mysql\bin\mysql.exe")
	}

	$root = $PSScriptRoot
	if ($root) {
		$index = $root.ToLower().IndexOf('\htdocs\')
		if ($index -gt 0) {
			$xamppRoot = $root.Substring(0, $index)
			$candidates += (Join-Path $xamppRoot "mysql\bin\mysql.exe")
		}
	}

	$candidates += "C:\xampp\mysql\bin\mysql.exe"
	$candidates += "C:\xampp8.2.12\mysql\bin\mysql.exe"

	foreach ($candidate in $candidates) {
		if (Test-Path $candidate) {
			return $candidate
		}
	}

	return $null
}

$tmpDir = Get-TempDir
$projectRoot = Split-Path -Parent $PSScriptRoot
$defaultTestsDir = Join-Path $projectRoot ".wordpress-tests-lib"
$defaultCoreDir = Join-Path $projectRoot ".wordpress"
$wpTestsDir = if ($env:WP_TESTS_DIR -and $env:WP_TESTS_DIR.Trim() -ne "") { $env:WP_TESTS_DIR } else { $defaultTestsDir }
$wpCoreDir = if ($env:WP_CORE_DIR -and $env:WP_CORE_DIR.Trim() -ne "") { $env:WP_CORE_DIR } else { $defaultCoreDir }

if (-not (Test-Path $wpCoreDir)) {
	New-Item -ItemType Directory -Path $wpCoreDir | Out-Null
}

if (-not (Test-Path (Join-Path $wpCoreDir "wp-settings.php"))) {
	if ($WpVersion -eq "latest") {
		$coreUrl = "https://wordpress.org/latest.zip"
	} elseif ($WpVersion -match '^\d+\.\d+(\.\d+)?$') {
		$coreUrl = "https://wordpress.org/wordpress-$WpVersion.zip"
	} elseif ($WpVersion -eq "nightly" -or $WpVersion -eq "trunk") {
		$coreUrl = "https://wordpress.org/nightly-builds/wordpress-latest.zip"
	} else {
		$coreUrl = "https://wordpress.org/wordpress-$WpVersion.zip"
	}

	$coreZip = Join-Path $tmpDir "wordpress.zip"
	Download-File -Url $coreUrl -Destination $coreZip

	$extractDir = Join-Path $tmpDir "wordpress-extract"
	if (Test-Path $extractDir) {
		Remove-Item -Recurse -Force $extractDir
	}
	Expand-Archive -Path $coreZip -DestinationPath $extractDir -Force

	$coreSource = Join-Path $extractDir "wordpress"
	if (-not (Test-Path $coreSource)) {
		$coreSource = $extractDir
	}
	Copy-Item -Path (Join-Path $coreSource "*") -Destination $wpCoreDir -Recurse -Force
}

$wpConfigPath = Join-Path $wpCoreDir "wp-config.php"
if (-not (Test-Path $wpConfigPath)) {
	$wpConfigSample = Join-Path $wpCoreDir "wp-config-sample.php"
	if (-not (Test-Path $wpConfigSample)) {
		throw "wp-config-sample.php not found in $wpCoreDir"
	}
	$content = Get-Content -Raw -Path $wpConfigSample
	$content = $content -replace "database_name_here", $DbName
	$content = $content -replace "username_here", $DbUser
	$content = $content -replace "password_here", $DbPass
	$content = $content -replace "localhost", $DbHost
	Set-Content -Path $wpConfigPath -Value $content -NoNewline
}

if (-not (Test-Path $wpTestsDir)) {
	New-Item -ItemType Directory -Path $wpTestsDir | Out-Null
}

Install-TestSuite -Version $WpVersion -TestsDir $wpTestsDir -TempDir $tmpDir

$wpTestsConfig = Join-Path $wpTestsDir "wp-tests-config.php"
if (-not (Test-Path $wpTestsConfig)) {
	$sampleConfig = Join-Path $wpTestsDir "wp-tests-config-sample.php"
	if (-not (Test-Path $sampleConfig)) {
		throw "wp-tests-config-sample.php not found in $wpTestsDir (download may have failed)."
	}
	Copy-Item -Path $sampleConfig -Destination $wpTestsConfig -Force
}

if (Test-Path $wpTestsConfig) {
	$config = Get-Content -Raw -Path $wpTestsConfig
	$config = $config -replace "youremptytestdbnamehere", $DbName
	$config = $config -replace "yourusernamehere", $DbUser
	$config = $config -replace "yourpasswordhere", $DbPass
	$config = $config -replace "localhost", $DbHost

	$coreDirNormalized = ($wpCoreDir -replace '\\', '/').TrimEnd('/') + '/'
	$config = $config -replace "define\\( 'ABSPATH'.*\\);", "define( 'ABSPATH', '$coreDirNormalized' );"
	Set-Content -Path $wpTestsConfig -Value $config -NoNewline
}

$testsSrc = Join-Path $wpTestsDir "src"
if (-not (Test-Path $testsSrc)) {
	try {
		New-Item -ItemType Junction -Path $testsSrc -Target $wpCoreDir | Out-Null
	} catch {
		Copy-Item -Path (Join-Path $wpCoreDir "*") -Destination $testsSrc -Recurse -Force
	}
}

if (-not $SkipDbCreate) {
	$mysqlPath = Find-MySqlCommand
	if ($mysqlPath) {
		$pwdPart = if ($DbPass -eq "") { "" } else { "-p$DbPass" }
		$escapedDbName = $DbName -replace '`', '``'
		$sql = "CREATE DATABASE IF NOT EXISTS " + '`' + $escapedDbName + '`' + " DEFAULT CHARACTER SET utf8mb4;"
		& $mysqlPath -u $DbUser $pwdPart -h $DbHost -e $sql
	} else {
		Write-Warning "mysql command not found; create the database manually: $DbName"
	}
}

Write-Host "WP core: $wpCoreDir"
Write-Host "WP tests: $wpTestsDir"
