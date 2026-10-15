"""Reject a tag that does not match the plugin version or is a prerelease."""
from pathlib import Path
import re
import sys
root = Path(__file__).resolve().parents[1]
version = re.search(r"Version:\s*(\S+)", (root / 'emonks-site-factory.php').read_text()).group(1)
if not re.fullmatch(r"\d+\.\d+\.\d+", version) or sys.argv[1] != 'v' + version:
    raise SystemExit('Release tag must equal v' + version)
print('Release tag matches plugin ' + version)
