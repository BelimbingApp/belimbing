#!/usr/bin/env python3
"""Land only policy-confined bot PRs with successful required checks at the same head."""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess


def api(path, *, method='GET', body=None, pages=False, unprotected=False):
    command = ['gh', 'api', path, '--method', method]
    if pages:
        command += ['--paginate', '--slurp']
    if body is not None:
        command += ['--input', '-']
    result = subprocess.run(command, input=json.dumps(body) if body is not None else None,
                            capture_output=True, text=True)
    if result.returncode:
        expected = {'message': 'Branch not protected', 'status': '404',
                    'documentation_url': 'https://docs.github.com/rest/branches/branch-protection#get-branch-protection'}
        if unprotected and json.loads(result.stdout) == expected:
            return {}
        raise ValueError(f'GitHub request refused: {method} {path}')
    return json.loads(result.stdout)


def eligible(pr, repo):
    labels = {label['name'] for label in pr['labels']}
    return (pr['state'] == 'open' and not pr['draft'] and 'bot-maintenance' in labels
            and not any(label.startswith(('agent:', 'hold:')) for label in labels)
            and pr['base']['ref'] == 'main' and (pr['head']['repo'] or {}).get('full_name') == repo)


def checks_green(required, checks, statuses):
    if not required:
        return False
    for requirement in required:
        context = requirement['context']
        app = requirement.get('integration_id')
        matching = [check for check in checks if check['name'] == context
                    and (app in (None, -1) or check['app']['id'] == app)]
        if matching:
            latest = max(matching, key=lambda check: check['id'])
            if latest['status'] != 'completed' or latest['conclusion'] != 'success':
                return False
        else:
            matching_statuses = [status for status in statuses if status['context'] == context]
            if app not in (None, -1) or not matching_statuses or max(matching_statuses, key=lambda status: status['id'])['state'] != 'success':
                return False
    return True


def merge_method(repo, rules, classic):
    allowed = {method for method, setting in [('merge', 'allow_merge_commit'), ('squash', 'allow_squash_merge'), ('rebase', 'allow_rebase_merge')] if repo[setting]}
    if classic.get('required_linear_history', {}).get('enabled'):
        allowed.discard('merge')
    for rule in rules:
        if rule['type'] == 'required_linear_history':
            allowed.discard('merge')
        elif rule['type'] == 'pull_request':
            methods = rule['parameters']['allowed_merge_methods']
            if not methods or not set(methods) <= {'merge', 'squash', 'rebase'}:
                raise ValueError('Invalid merge methods in branch rules')
            allowed.intersection_update(methods)
    for method in ['merge', 'squash', 'rebase']:
        if method in allowed:
            return method
    raise ValueError('No merge method allowed by every policy layer')


def policy(pr, repo):
    if not eligible(pr, repo):
        return False
    base, head = pr['base']['sha'], pr['head']['sha']
    if not all(re.fullmatch('[0-9a-f]{40}', sha) for sha in [base, head]):
        raise ValueError('Invalid commit identity')
    subprocess.run(['git', 'fetch', '--quiet', '--no-tags', 'origin', base, head], check=True)
    return subprocess.run(['bash', str(Path(__file__).with_name('bot-pr-policy.sh')), base, head],
                          stdout=subprocess.DEVNULL).returncode == 0


def discover(repo, sha):
    if not re.fullmatch('[0-9a-f]{40}', sha):
        raise ValueError('Invalid completed workflow head')
    candidates = []
    for page in api(f'repos/{repo}/commits/{sha}/pulls?per_page=100', pages=True):
        for summary in page:
            pr = api(f'repos/{repo}/pulls/{summary["number"]}')
            if pr['head']['sha'] == sha and policy(pr, repo):
                candidates.append({'pr': pr['number'], 'sha': sha})
    with open(os.environ['GITHUB_OUTPUT'], 'a') as output:
        output.write('accepted=' + str(bool(candidates)).lower() + '\n')
        output.write('matrix=' + json.dumps({'include': candidates}) + '\n')


def land(repo, number, sha):
    pr_path = f'repos/{repo}/pulls/{number}'
    pr = api(pr_path)
    if pr['head']['sha'] != sha or not policy(pr, repo):
        return
    settings = api(f'repos/{repo}')
    rules = [rule for page in api(f'repos/{repo}/rules/branches/main?per_page=100', pages=True) for rule in page]
    classic = api(f'repos/{repo}/branches/main/protection', unprotected=True)
    required = [check for rule in rules if rule['type'] == 'required_status_checks'
                for check in rule['parameters']['required_status_checks']]
    status_policy = classic.get('required_status_checks') or {}
    required += [{'context': check['context'], 'integration_id': check.get('app_id')} for check in status_policy.get('checks', [])]
    required += [{'context': context} for context in status_policy.get('contexts', [])]
    checks = [check for page in api(f'repos/{repo}/commits/{sha}/check-runs?per_page=100', pages=True) for check in page['check_runs']]
    statuses = [status for page in api(f'repos/{repo}/commits/{sha}/statuses?per_page=100', pages=True) for status in page]
    if not checks_green(required, checks, statuses):
        print(f'PR #{number}: required checks are not all successful; waiting for another completed run')
        return
    method = merge_method(settings, rules, classic)
    latest = api(pr_path)
    if not eligible(latest, repo) or latest['head']['sha'] != sha or latest['base']['sha'] != pr['base']['sha']:
        return
    merged = api(pr_path + '/merge', method='PUT', body={'sha': sha, 'merge_method': method})
    if merged.get('merged') is not True:
        raise ValueError(f'GitHub did not merge PR #{number}')
    api(f'repos/{repo}/issues/{number}/comments', method='POST', body={'body':
        f'**From:** bot-maintenance\n\n**Type:** landed\n\nMerged `{sha}` at `{merged["sha"]}` after file-policy acceptance and successful required checks, using `{method}`.'})


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['discover', 'land'])
    parser.add_argument('--repo', required=True)
    parser.add_argument('--sha', required=True)
    parser.add_argument('--pr', type=int)
    args = parser.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', args.repo):
        parser.error('invalid repository')
    if args.command == 'discover':
        discover(args.repo, args.sha)
    else:
        if not args.pr:
            parser.error('--pr is required for land')
        land(args.repo, args.pr, args.sha)
