from pathlib import Path
import json
import re


root = Path(__file__).resolve().parents[1]
manifest_path = root / "app/public/app-update.json"
manifest_bytes = manifest_path.read_bytes()
assert not manifest_bytes.startswith(b"\xef\xbb\xbf")
manifest = json.loads(manifest_bytes.decode("utf-8"))

required = {
    "releaseAvailable",
    "versionCode",
    "versionName",
    "downloadUrl",
    "sha256",
    "fileSize",
    "changelog",
    "minSupportedVersionCode",
}
assert required <= manifest.keys()
assert manifest["releaseAvailable"] is False
assert manifest["downloadUrl"] is None
assert manifest["sha256"] is None
assert manifest["fileSize"] is None
assert not list((root / "app/public/downloads").glob("*debug*.apk"))

gradle = (root / "android/app/build.gradle.kts").read_text(encoding="utf-8")
assert 'orElse("https://forum.example.com")' in gradle
assert "10.0.2.2" not in gradle
version_code = int(re.search(r"versionCode\s*=\s*(\d+)", gradle).group(1))
version_name = re.search(r'versionName\s*=\s*"([^"]+)"', gradle).group(1)
assert version_code == manifest["versionCode"]
assert version_name == manifest["versionName"]

activity = (root / "android/app/src/main/java/com/resourceforum/app/MainActivity.kt").read_text(encoding="utf-8")
for evidence in [
    "onShowFileChooser",
    "FileChooserParams.parseResult",
    "setSupportZoom(true)",
    "trimStart('\\uFEFF')",
    "isTrustedUpdateUri",
    'uri.scheme.equals("https"',
]:
    assert evidence in activity

assert (root / "android/verify-release.ps1").is_file()
print("Android release configuration tests passed")
