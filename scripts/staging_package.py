#!/usr/bin/env python3
"""Build a source-only ZIP for manual Hostinger test-site acceptance.

Never include the repository, tests, secrets, SQL, PDFs, or documentation in the
webroot package. Production deployment continues using the separate protected
manual release job; this package does not publish or modify any branch.
"""
from pathlib import Path
import re
import sys
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUT = Path(sys.argv[1] if len(sys.argv) == 2 else "/tmp/ad-client-manager-staging.zip")
required = {
    ".htaccess", "index.php", "app/.htaccess", "app/core.php",
    "scripts/.htaccess", "scripts/staging_migrate.php",
    "scripts/release_preflight.php", "scripts/compare_restore.php",
}
files = {
    Path(".htaccess"),
    Path("index.php"),
    *Path("app").glob("*.php"),
    Path("app/.htaccess"),
    Path("scripts/.htaccess"),
    Path("scripts/staging_migrate.php"),
    Path("scripts/release_preflight.php"),
    Path("scripts/compare_restore.php"),
}
selected = {p.as_posix() for p in files}
if not required.issubset(selected):
    raise SystemExit("ERROR: missing required webroot or CLI migration assets")
if not files or any(not (ROOT / p).is_file() or (ROOT / p).is_symlink() for p in files):
    raise SystemExit("ERROR: missing or symlinked staging file")
if any(not (
    p.as_posix() in required
    or re.fullmatch(r"app/[A-Za-z0-9_-]+\.php", p.as_posix())
) for p in files):
    raise SystemExit("ERROR: staging archive contains a non-approved path")

OUT.parent.mkdir(parents=True, exist_ok=True)
with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED) as archive:
    for p in sorted(files, key=lambda x: x.as_posix()):
        archive.write(ROOT / p, p.as_posix())
with zipfile.ZipFile(OUT) as archive:
    if set(archive.namelist()) != selected:
        raise SystemExit("ERROR: packaging mismatch")
print(f"Staging-only zip created: {len(files)} allowlisted files, no secrets or data")
