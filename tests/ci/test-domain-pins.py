import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class DomainPinsTest(unittest.TestCase):
    def check_result(self, result, expected, text):
        with tempfile.TemporaryDirectory(prefix='domain-pins-') as temporary:
            directory = Path(temporary)
            descriptor = directory / 'domains.json'
            descriptor.write_text(json.dumps({'domains': {'example': {'repo': 'owner/example', 'ref': 'a' * 40}}}))
            resolver = directory / 'resolve'
            resolver.write_text('#!/usr/bin/env python3\nprint(' + repr(json.dumps(result)) + ')\n')
            resolver.chmod(0o700)
            run = subprocess.run(['python3', str(ROOT / 'scripts/ci/validate-domain-pins.py'), '--descriptor', str(descriptor), '--resolver', str(resolver)], capture_output=True, text=True)
            self.assertEqual(run.returncode, expected, run.stdout + run.stderr)
            self.assertIn(text, run.stdout + run.stderr)

    def test_missing_commit_refuses_and_names_domain(self):
        self.check_result({'exists': False, 'behind': 0}, 1, 'example: pinned commit does not exist')

    def test_known_commit_passes(self):
        self.check_result({'exists': True, 'behind': 50}, 0, 'OK example')

    def test_stale_commit_warns_without_failing(self):
        self.check_result({'exists': True, 'behind': 51}, 0, 'WARN example: pin is 51 commits behind main')

    def test_unknown_response_fails_closed(self):
        self.check_result({}, 1, 'example: invalid resolver response')


if __name__ == '__main__':
    unittest.main()
