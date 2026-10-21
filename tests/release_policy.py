#!/usr/bin/env python3
"""Fail CI if updating main would implicitly publish the site.

No external Python modules or GitHub API required. No network calls.
"""
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
workflow = (root / ".github/workflows/ci-deploy.yml").read_text(encoding="utf-8")
assert re.search(r"(?m)^  push:\s*\n    branches: \[main\]", workflow), (
    "Main push must still run the full verification workflow"
)
assert "  workflow_dispatch:" in workflow and "confirm_release:" in workflow
assert re.search(r"(?m)^\s+- 'YES'$", workflow)
assert "default: 'NO'" in workflow
verify, marker, deploy = workflow.partition("\n  deploy:\n")
assert marker, "Dedicated deployment job required"
guard = ("if: github.event_name == 'workflow_dispatch' "
         "&& github.ref == 'refs/heads/main' "
         "&& inputs.confirm_release == 'YES'")
assert guard in deploy, "Deployment MUST require explicit YES from manual main dispatch"
assert "if: github.event_name == 'push'" not in deploy
assert "needs: verify" in deploy, "Successful verification must gate release"
assert "contents: read" in verify, "Workflow-wide permissions must be read-only"
assert "contents: write" in deploy, "Write permission only for release job"
assert "git push origin HEAD:deploy" in deploy, "Manual action must target deploy only"
assert "git restore --source=origin/main --staged --worktree -- index.php .htaccess app" in deploy, (
    "Release may copy only reviewed application files"
)
assert (root / "scripts/release_preflight.php").is_file()
assert (root / "scripts/.htaccess").is_file()
print("Release policy passed: main push verifies only; explicit manual YES on main publishes")
