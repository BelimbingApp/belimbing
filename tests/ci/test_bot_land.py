import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/ci/land-bot-maintenance.py'


class BotLandingTest(unittest.TestCase):
    def setUp(self):
        spec = importlib.util.spec_from_file_location('bot_land', SCRIPT)
        self.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.module)

    def test_required_check_must_be_successful_and_bound_to_expected_app(self):
        required = [{'context': 'ci', 'integration_id': 7}]
        check = {'name': 'ci', 'status': 'completed', 'conclusion': 'success', 'id': 1, 'app': {'id': 7}}
        self.assertTrue(self.module.checks_green(required, [check], []))
        self.assertFalse(self.module.checks_green([], [check], []))
        for conclusion in ['failure', 'cancelled', 'skipped', 'neutral', None]:
            self.assertFalse(self.module.checks_green(required, [{**check, 'conclusion': conclusion}], []))
        self.assertFalse(self.module.checks_green(required, [], []))
        self.assertFalse(self.module.checks_green(required, [{**check, 'app': {'id': 8}}], []))
        self.assertFalse(self.module.checks_green(required, [check, {**check, 'id': 2, 'status': 'in_progress'}], []))

    def test_merge_method_intersects_repository_rules_and_classic_linear_history(self):
        repo = dict(allow_merge_commit=True, allow_squash_merge=True, allow_rebase_merge=True)
        rules = [{'type': 'pull_request', 'parameters': {'allowed_merge_methods': ['merge', 'squash']}}]
        self.assertEqual('squash', self.module.merge_method(repo, rules, {'required_linear_history': {'enabled': True}}))
        with self.assertRaises(ValueError):
            self.module.merge_method(repo, rules + [{'type': 'pull_request', 'parameters': {'allowed_merge_methods': ['rebase']}}], {})

    def test_only_open_same_repository_labelled_bot_lanes_are_candidates(self):
        pr = {'state': 'open', 'draft': False, 'labels': [{'name': 'bot-maintenance'}],
              'base': {'ref': 'main'}, 'head': {'repo': {'full_name': 'BelimbingApp/belimbing'}}}
        self.assertTrue(self.module.eligible(pr, 'BelimbingApp/belimbing'))
        for change in [{'labels': []}, {'draft': True}, {'state': 'closed'},
                       {'base': {'ref': 'release'}},
                       {'labels': [{'name': 'bot-maintenance'}, {'name': 'agent:desktop-astra'}]},
                       {'head': {'repo': {'full_name': 'other/fork'}}}]:
            self.assertFalse(self.module.eligible({**pr, **change}, 'BelimbingApp/belimbing'))

    def test_land_never_calls_merge_for_red_checks_or_a_changed_live_label(self):
        repo = 'BelimbingApp/belimbing'
        sha = 'a' * 40
        pr = {'state': 'open', 'draft': False, 'labels': [{'name': 'bot-maintenance'}],
              'base': {'ref': 'main', 'sha': 'b' * 40}, 'head': {'sha': sha, 'repo': {'full_name': repo}}}
        for conclusion, labelled, expected in [('failure', True, False), ('success', False, False), ('success', True, True)]:
            writes = []
            reads = []
            def fake_api(path, *, method='GET', body=None, **kwargs):
                if method != 'GET':
                    writes.append((path, body))
                    return {'merged': True, 'sha': 'c' * 40}
                if path.endswith('/pulls/1'):
                    reads.append(path)
                    return {**pr, 'labels': pr['labels'] if labelled or len(reads) == 1 else []}
                if path == f'repos/{repo}':
                    return dict(allow_merge_commit=True, allow_squash_merge=True, allow_rebase_merge=True)
                if '/rules/branches/' in path:
                    return [[{'type': 'required_status_checks', 'parameters': {'required_status_checks': [{'context': 'ci', 'integration_id': 7}]}}]]
                if path.endswith('/protection'):
                    return {}
                if '/check-runs?' in path:
                    return [{'check_runs': [{'name': 'ci', 'id': 1, 'app': {'id': 7}, 'status': 'completed', 'conclusion': conclusion}]}]
                if '/statuses?' in path:
                    return [[]]
                self.fail(path)
            with patch.object(self.module, 'api', side_effect=fake_api), patch.object(self.module, 'policy', return_value=True):
                self.module.land(repo, 1, sha)
            self.assertEqual(expected, bool(writes))
            if expected:
                self.assertEqual({'sha': sha, 'merge_method': 'merge'}, writes[0][1])
                self.assertIn('**Type:** landed', writes[1][1]['body'])


if __name__ == '__main__':
    unittest.main()
