"""Offline checks for release-note selection and fail-closed version validation."""

import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = next(path for path in (ROOT / "bin/release-notes.py", ROOT / "tools/release-notes.py") if path.exists())
SPEC = importlib.util.spec_from_file_location("release_notes", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class ReleaseNotesTest(unittest.TestCase):
    def extract(self, changelog, tag="v1.2.3", plugin=None, readme="Stable tag: 1.2.3\n"):
        return MODULE.release_notes(tag, changelog,
            plugin if plugin is not None else " * Version: 1.2.3\ndefine( 'TEST_VERSION', '1.2.3' );",
            readme, "TEST_VERSION")

    def test_selects_exact_version_and_preserves_bullet_formatting(self):
        changelog = "# Changelog\r\n\r\n## Unreleased\r\n- Future\r\n\r\n## 1.2.3\r\n\r\n- **Fix** `cache` behavior.\r\n  Keep this continuation.\r\n\r\n- Another fix.\r\n\r\n## 1.2.2\r\n- Old\r\n"
        self.assertEqual("- **Fix** `cache` behavior.\n  Keep this continuation.\n\n- Another fix.\n", self.extract(changelog))

    def test_rejects_invalid_tags_and_version_mismatches(self):
        for tag in ("main", "1.2.3", "v01.2.3", "v1.2.3-rc1", "v1.2.4", "v1.2.3\nmalicious"):
            with self.subTest(tag=tag), self.assertRaises(ValueError):
                self.extract("## 1.2.3\n- Fix\n", tag=tag)
        for plugin in (" * Version: 1.2.2\ndefine('TEST_VERSION', '1.2.3');", " * Version: 1.2.3\ndefine('TEST_VERSION', '1.2.2');"):
            with self.subTest(plugin=plugin), self.assertRaises(ValueError):
                self.extract("## 1.2.3\n- Fix", plugin=plugin)
        with self.assertRaises(ValueError):
            self.extract("## 1.2.3\n- Fix", readme="Stable tag: 1.2.2")

    def test_rejects_missing_duplicate_empty_and_non_bullet_sections(self):
        for text in ("## 1.2.2\n- Old", "## 1.2.3\n", "## 1.2.3\n- Fix\n## 1.2.3\n- Duplicate", "## 1.2.3\nFree prose", "## 1.2.3\n- Fix\nUnrelated prose"):
            with self.subTest(text=text), self.assertRaises(ValueError):
                self.extract(text)


if __name__ == "__main__":
    unittest.main()
