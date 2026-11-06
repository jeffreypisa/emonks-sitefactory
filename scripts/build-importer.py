"""Build temporary standalone importer using the same validated content adapter."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import re
root=Path(__file__).resolve().parents[1]
version=re.search(r'Version:\s*([0-9.]+)',(root/'emonks-site-factory.php').read_text()).group(1)
main=(root/'importer/emonks-site-importer.php').read_text()
assert f'Version: {version}' in main
files=['assets/importer.css','assets/importer.js','shared-schema.json','includes/Factory.php','includes/ThemeRelease.php','includes/Package.php','includes/Handover.php','includes/HandoverLibrary.php','includes/Standalone.php','includes/StandaloneContent.php','includes/DeliverySoftware.php','includes/FullSite.php','includes/UploadTransport.php','includes/Online/Receiver.php']
output=root/'dist'/f'emonks-site-importer-{version}.zip'
output.parent.mkdir(exist_ok=True)
with ZipFile(output,'w',ZIP_DEFLATED) as z:
    z.writestr('emonks-site-importer/emonks-site-importer.php',main)
    for name in files:
        assert not (root/name).is_symlink()
        z.write(root/name,'emonks-site-importer/'+name)
with ZipFile(output) as z:
    assert z.testzip() is None
print(output)
