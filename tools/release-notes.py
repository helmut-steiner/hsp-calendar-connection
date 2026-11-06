#!/usr/bin/env python3
"""Validate a WordPress release tag and extract its exact changelog bullet list."""

import argparse
from pathlib import Path
import re


def release_notes(tag, changelog, plugin, readme, constant):
    """Refuse mismatched versions, missing sections, and empty release messages."""
    if not re.fullmatch(r"v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)", tag):
        raise ValueError("Use a stable version tag such as v0.10.1.")
    version = tag[1:]
    header = re.search(r"^\s*\* Version:\s*([^\r\n]+)", plugin, re.MULTILINE)
    runtime = re.search(
        r"define\(\s*['\"]" + re.escape(constant) + r"['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)", plugin,
    )
    stable = re.search(r"^Stable tag:\s*([^\r\n]+)", readme, re.MULTILINE)
    for label, match in (("Plugin header", header), (constant, runtime), ("Readme stable tag", stable)):
        if not match or match.group(1).strip() != version:
            raise ValueError(f"{label} must match {version} before publishing {tag}.")
    sections = list(re.finditer(r"^##\s+([^\r\n]+)\r?$", changelog, re.MULTILINE))
    matches = [index for index, section in enumerate(sections) if section.group(1).strip() == version]
    if len(matches) != 1:
        raise ValueError(f"Expected exactly one '## {version}' section in CHANGELOG.md.")
    index = matches[0]
    end = sections[index + 1].start() if index + 1 < len(sections) else len(changelog)
    body = changelog[sections[index].end():end].strip().replace("\r\n", "\n")
    if not body or not re.match(r"[-*] \S", body):
        raise ValueError("Release notes must contain changelog bullet points.")
    if any(line.strip() and not re.match(r"(?:[-*] |[ \t]+\S)", line) for line in body.splitlines()):
        raise ValueError("Release notes must contain only bullet points and their continuation lines.")
    return body + "\n"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tag", required=True)
    parser.add_argument("--plugin", required=True, type=Path)
    parser.add_argument("--constant", required=True)
    parser.add_argument("--readme", type=Path, default=Path("readme.txt"))
    parser.add_argument("--changelog", type=Path, default=Path("CHANGELOG.md"))
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    try:
        notes = release_notes(args.tag, args.changelog.read_text(encoding="utf-8-sig"),
                              args.plugin.read_text(encoding="utf-8-sig"),
                              args.readme.read_text(encoding="utf-8-sig"), args.constant)
    except (ValueError, OSError) as error:
        parser.exit(1, f"Release validation failed: {error}\n")
    args.output.write_text(notes, encoding="utf-8", newline="\n")


if __name__ == "__main__":
    main()
