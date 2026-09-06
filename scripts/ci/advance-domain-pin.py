#!/usr/bin/env python3
"""Advance one composed Domain pin only after the candidate passes upstream validation."""
import argparse
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('domain_id')
    parser.add_argument('ref')
    parser.add_argument('--descriptor', type=Path, default=Path(__file__).with_name('domain-repos.json'))
    parser.add_argument('--resolver', help='Hermetic validator resolver executable')
    args = parser.parse_args()
    if not re.fullmatch(r'[0-9a-f]{40}', args.ref):
        parser.error('ref must be a full lowercase 40-character commit SHA')
    descriptor = json.loads(args.descriptor.read_text())
    if args.domain_id not in descriptor['domains']:
        parser.error('domain-id must identify a Domain in the descriptor')
    descriptor['domains'][args.domain_id]['ref'] = args.ref
    # Validate a candidate outside the tracked descriptor: an absent upstream
    # commit or any other invalid pin leaves the original bytes untouched.
    with tempfile.TemporaryDirectory(prefix='advance-domain-pin-') as directory:
        candidate = Path(directory) / 'domain-repos.json'
        candidate.write_text(json.dumps(descriptor, indent=4) + '\n')
        command = [sys.executable, str(Path(__file__).with_name('validate-domain-pins.py')), '--descriptor', str(candidate)]
        if args.resolver:
            command += ['--resolver', args.resolver]
        subprocess.run(command, check=True)
        args.descriptor.write_text(candidate.read_text())
    return 0


if __name__ == '__main__':
    sys.exit(main())
