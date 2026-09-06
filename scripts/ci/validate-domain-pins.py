#!/usr/bin/env python3
"""Verify pinned Domain commits; warn above 50 commits behind main."""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


def github(path):
    headers = {'Accept': 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28'}
    token = os.environ.get('GITHUB_TOKEN') or os.environ.get('GH_TOKEN')
    if token:
        headers['Authorization'] = 'Bearer ' + token
    with urlopen(Request('https://api.github.com/' + path, headers=headers), timeout=20) as response:
        return json.load(response)


def resolve(repo, sha):
    try:
        commit = github(f'repos/{repo}/commits/{sha}')
    except HTTPError as error:
        if error.code == 404:
            return {'exists': False}
        raise
    if commit.get('sha') != sha:
        raise ValueError('resolved commit differs from pinned SHA')
    comparison = github(f'repos/{repo}/compare/{sha}...main')
    return {'exists': True, 'behind': comparison['ahead_by']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--descriptor', type=Path, default=Path(__file__).with_name('domain-repos.json'))
    parser.add_argument('--resolver', help='Test resolver executable: REPO SHA arguments; JSON exists/behind output')
    args = parser.parse_args()
    try:
        domains = json.loads(args.descriptor.read_text())['domains']
        if not isinstance(domains, dict) or not domains:
            raise ValueError('descriptor domains must be a nonempty object')
    except (OSError, ValueError, KeyError) as error:
        print(f'ERROR descriptor: {error}', file=sys.stderr)
        return 1
    failed = False
    for name, domain in domains.items():
        try:
            repo, sha = domain['repo'], domain['ref']
            if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repo) or not re.fullmatch(r'[0-9a-f]{40}', sha):
                raise ValueError('repository or immutable SHA is invalid')
            if args.resolver:
                output = subprocess.run([args.resolver, repo, sha], check=True, capture_output=True, text=True, timeout=30)
                result = json.loads(output.stdout)
            else:
                result = resolve(repo, sha)
            if not isinstance(result, dict) or type(result.get('exists')) is not bool:
                raise ValueError('invalid resolver response')
            if not result['exists']:
                raise ValueError('pinned commit does not exist or is inaccessible upstream')
            behind = result.get('behind')
            if type(behind) is not int or behind < 0:
                raise ValueError('invalid resolver behind count')
            if behind > 50:
                print(f'WARN {name}: pin is {behind} commits behind main ({repo}@{sha})')
            else:
                print(f'OK {name}: pinned commit exists, {behind} commits behind main')
        except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError, URLError) as error:
            # Never print resolver stderr or authenticated request headers.
            print(f'ERROR {name}: {error}', file=sys.stderr)
            failed = True
    return int(failed)


if __name__ == '__main__':
    sys.exit(main())
