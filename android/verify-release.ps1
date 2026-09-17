param(
    [Parameter(Mandatory = $true)]
    [string]$ApkPath,
    [string]$TargetOrigin = 'https://forum.example.com'
)

$ErrorActionPreference = 'Stop'
$apk = (Resolve-Path -LiteralPath $ApkPath).Path
$origin = [Uri]$TargetOrigin
if ($origin.Scheme -ne 'https' -or -not $origin.Host) {
    throw 'TargetOrigin must be an HTTPS origin.'
}

$properties = Join-Path $PSScriptRoot 'local.properties'
$sdk = $env:ANDROID_HOME
if (-not $sdk -and (Test-Path -LiteralPath $properties)) {
    $sdkLine = Get-Content -LiteralPath $properties | Where-Object { $_ -like 'sdk.dir=*' } | Select-Object -First 1
    if ($sdkLine) { $sdk = $sdkLine.Substring(8).Replace('/', '\') }
}
if (-not $sdk -or -not (Test-Path -LiteralPath $sdk)) {
    throw 'Android SDK path was not found.'
}

$buildTools = Get-ChildItem -LiteralPath (Join-Path $sdk 'build-tools') -Directory | Sort-Object Name -Descending | Select-Object -First 1
$apksigner = Join-Path $buildTools.FullName 'apksigner.bat'
$aapt = Join-Path $buildTools.FullName 'aapt.exe'
if (-not (Test-Path -LiteralPath $apksigner) -or -not (Test-Path -LiteralPath $aapt)) {
    throw 'Required Android build tools were not found.'
}

$certificate = & $apksigner verify --print-certs $apk 2>&1
if ($LASTEXITCODE -ne 0) { throw "APK signature verification failed.`n$certificate" }
if (($certificate | Out-String) -match 'Android Debug') { throw 'Debug signing certificate is not allowed.' }

$badging = & $aapt dump badging $apk 2>&1
if ($LASTEXITCODE -ne 0) { throw "APK metadata verification failed.`n$badging" }
$packageLine = $badging | Where-Object { $_ -like 'package:*' } | Select-Object -First 1
$versionCode = [regex]::Match($packageLine, "versionCode='([^']+)' ").Groups[1].Value
$versionName = [regex]::Match($packageLine, "versionName='([^']+)' ").Groups[1].Value
if (-not $versionCode -or -not $versionName) { throw 'APK version metadata is missing.' }

Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::OpenRead($apk)
try {
    $dexText = foreach ($entry in $archive.Entries | Where-Object { $_.FullName -like '*.dex' }) {
        $stream = $entry.Open()
        try {
            $memory = New-Object IO.MemoryStream
            $stream.CopyTo($memory)
            [Text.Encoding]::ASCII.GetString($memory.ToArray())
        } finally {
            $stream.Dispose()
        }
    }
} finally {
    $archive.Dispose()
}
$joinedDex = $dexText -join ''
if ($joinedDex -notmatch [regex]::Escape($origin.Host)) { throw "APK does not contain target host $($origin.Host)." }
if ($joinedDex -match '10\.0\.2\.2') { throw 'APK still contains the Android emulator host.' }

$hash = (Get-FileHash -LiteralPath $apk -Algorithm SHA256).Hash.ToLowerInvariant()
$size = (Get-Item -LiteralPath $apk).Length
[pscustomobject]@{
    Apk = $apk
    VersionCode = $versionCode
    VersionName = $versionName
    Sha256 = $hash
    FileSize = $size
    TargetOrigin = $origin.GetLeftPart([UriPartial]::Authority)
    Signature = 'verified, non-debug'
}
