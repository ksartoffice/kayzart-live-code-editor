[CmdletBinding()]
param(
    [string]$SvnPath = "..\kayzart-svn",
    [switch]$SkipBuild,
    [switch]$DryRun,
    [switch]$AllowExistingTag
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$zipFileName = "kayzart-live-code-editor.zip"
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path -Path $PSScriptRoot -ChildPath ".."))

function Resolve-AbsolutePath {
    param(
        [Parameter(Mandatory = $true)]
        [string]$PathValue,
        [Parameter(Mandatory = $true)]
        [string]$BasePath
    )

    if ([System.IO.Path]::IsPathRooted($PathValue)) {
        return [System.IO.Path]::GetFullPath($PathValue)
    }

    return [System.IO.Path]::GetFullPath((Join-Path -Path $BasePath -ChildPath $PathValue))
}

function Assert-DirectoryExists {
    param(
        [Parameter(Mandatory = $true)]
        [string]$DirectoryPath,
        [Parameter(Mandatory = $true)]
        [string]$Label
    )

    if (-not (Test-Path -LiteralPath $DirectoryPath -PathType Container)) {
        throw "$Label not found: $DirectoryPath"
    }
}

function Assert-CommandAvailable {
    param(
        [Parameter(Mandatory = $true)]
        [string]$CommandName
    )

    if (-not (Get-Command -Name $CommandName -ErrorAction SilentlyContinue)) {
        throw "Required command '$CommandName' was not found in PATH."
    }
}

function Invoke-CommandStep {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Description,
        [Parameter(Mandatory = $true)]
        [string]$WorkingDirectory,
        [Parameter(Mandatory = $true)]
        [string]$CommandName,
        [Parameter(Mandatory = $true)]
        [string[]]$Arguments
    )

    Write-Host ""
    Write-Host "==> $Description"
    Write-Host "$CommandName $($Arguments -join ' ')"

    Push-Location -LiteralPath $WorkingDirectory
    try {
        & $CommandName @Arguments
        $exitCode = $LASTEXITCODE
        if ($null -ne $exitCode -and $exitCode -ne 0) {
            throw "Command failed with exit code ${exitCode}: $CommandName $($Arguments -join ' ')"
        }
    }
    finally {
        Pop-Location
    }
}

function Invoke-RobocopyMirror {
    param(
        [Parameter(Mandatory = $true)]
        [string]$SourcePath,
        [Parameter(Mandatory = $true)]
        [string]$DestinationPath,
        [Parameter(Mandatory = $true)]
        [switch]$PreviewOnly
    )

    $arguments = @(
        $SourcePath,
        $DestinationPath,
        "/MIR",
        "/R:2",
        "/W:1",
        "/NFL",
        "/NDL",
        "/NJH",
        "/NJS",
        "/NP"
    )

    if ($PreviewOnly) {
        $arguments += "/L"
    }

    Write-Host ""
    if ($PreviewOnly) {
        Write-Host "==> Preview sync with robocopy"
    }
    else {
        Write-Host "==> Sync with robocopy"
    }
    Write-Host "robocopy $($arguments -join ' ')"

    & robocopy @arguments
    $exitCode = $LASTEXITCODE
    if ($exitCode -gt 7) {
        throw "robocopy failed with exit code $exitCode."
    }
}

function Get-StableTagVersion {
    param(
        [Parameter(Mandatory = $true)]
        [string]$ReadmePath
    )

    $readme = Get-Content -LiteralPath $ReadmePath -Raw
    $match = [regex]::Match($readme, "(?m)^Stable tag:\s*(\d+\.\d+\.\d+)\s*$")
    if (-not $match.Success) {
        throw "Could not read 'Stable tag' from $ReadmePath"
    }

    return $match.Groups[1].Value
}

function Get-PackageName {
    param(
        [Parameter(Mandatory = $true)]
        [string]$PackageJsonPath
    )

    $packageJson = Get-Content -LiteralPath $PackageJsonPath -Raw | ConvertFrom-Json
    $packageName = [string]$packageJson.name
    if ([string]::IsNullOrWhiteSpace($packageName)) {
        throw "package.json 'name' is missing or empty: $PackageJsonPath"
    }

    return $packageName.Trim()
}

$resolvedSvnPath = Resolve-AbsolutePath -PathValue $SvnPath -BasePath $projectRoot
$trunkPath = Join-Path -Path $resolvedSvnPath -ChildPath "trunk"
$tagsPath = Join-Path -Path $resolvedSvnPath -ChildPath "tags"
$readmePath = Join-Path -Path $projectRoot -ChildPath "readme.txt"
$packageJsonPath = Join-Path -Path $projectRoot -ChildPath "package.json"
$zipPath = Join-Path -Path $projectRoot -ChildPath $zipFileName
$temporaryRoot = $null

Write-Host "Project root : $projectRoot"
Write-Host "SVN root     : $resolvedSvnPath"
if ($DryRun) {
    Write-Host "Mode         : DryRun"
}
else {
    Write-Host "Mode         : Apply"
}

Assert-CommandAvailable -CommandName "npm"
Assert-CommandAvailable -CommandName "composer"
Assert-CommandAvailable -CommandName "svn"
Assert-CommandAvailable -CommandName "robocopy"

Assert-DirectoryExists -DirectoryPath $resolvedSvnPath -Label "SVN root"
Assert-DirectoryExists -DirectoryPath $trunkPath -Label "SVN trunk"
Assert-DirectoryExists -DirectoryPath $tagsPath -Label "SVN tags"

$version = Get-StableTagVersion -ReadmePath $readmePath
$packageName = Get-PackageName -PackageJsonPath $packageJsonPath
$tagDestinationPath = Join-Path -Path $tagsPath -ChildPath $version

Write-Host "Stable tag   : $version"
Write-Host "Package name : $packageName"
Write-Host "ZIP path     : $zipPath"
Write-Host "Tag path     : $tagDestinationPath"

if ($DryRun -and -not $SkipBuild) {
    Write-Host ""
    Write-Host "[DryRun] Build step is skipped. Existing ZIP will be used."
    $SkipBuild = $true
}

if (-not $SkipBuild) {
    Invoke-CommandStep `
        -Description "Build and package plugin ZIP" `
        -WorkingDirectory $projectRoot `
        -CommandName "npm" `
        -Arguments @("run", "plugin-zip")
}
else {
    Write-Host ""
    Write-Host "==> Skip build step"
}

if (-not (Test-Path -LiteralPath $zipPath -PathType Leaf)) {
    throw "ZIP file not found: $zipPath"
}

if ((Test-Path -LiteralPath $tagDestinationPath -PathType Container) -and -not $AllowExistingTag) {
    throw "Tag directory already exists: $tagDestinationPath`nUse -AllowExistingTag to overwrite."
}

try {
    $temporaryRoot = Join-Path -Path ([System.IO.Path]::GetTempPath()) -ChildPath ("kayzart-release-" + [guid]::NewGuid().ToString("N"))
    New-Item -ItemType Directory -Path $temporaryRoot -Force | Out-Null

    Write-Host ""
    Write-Host "==> Expand ZIP"
    Expand-Archive -LiteralPath $zipPath -DestinationPath $temporaryRoot -Force

    $extractedRoot = Join-Path -Path $temporaryRoot -ChildPath $packageName
    Assert-DirectoryExists -DirectoryPath $extractedRoot -Label "Extracted package root"

    Invoke-RobocopyMirror -SourcePath $extractedRoot -DestinationPath $trunkPath -PreviewOnly:$DryRun

    if (-not (Test-Path -LiteralPath $tagDestinationPath -PathType Container)) {
        if ($DryRun) {
            Write-Host ""
            Write-Host "[DryRun] Would create tag directory: $tagDestinationPath"
        }
        else {
            New-Item -ItemType Directory -Path $tagDestinationPath -Force | Out-Null
        }
    }

    Invoke-RobocopyMirror -SourcePath $trunkPath -DestinationPath $tagDestinationPath -PreviewOnly:$DryRun
}
finally {
    if ($null -ne $temporaryRoot -and (Test-Path -LiteralPath $temporaryRoot -PathType Container)) {
        Remove-Item -LiteralPath $temporaryRoot -Recurse -Force
    }
}

Invoke-CommandStep `
    -Description "SVN status for trunk" `
    -WorkingDirectory $resolvedSvnPath `
    -CommandName "svn" `
    -Arguments @("status", "trunk")

Invoke-CommandStep `
    -Description "SVN status for tags/$version" `
    -WorkingDirectory $resolvedSvnPath `
    -CommandName "svn" `
    -Arguments @("status", ("tags/" + $version))

Write-Host ""
Write-Host "Release sync finished."
if ($DryRun) {
    Write-Host "DryRun mode did not modify files."
}
