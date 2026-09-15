"""Hermetic checks for PostgreSQL setup repair commands; no database is contacted."""
import os
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
SOURCE = (ROOT / "scripts/setup-steps/40-database.sh").read_text()
DIAGNOSE = re.search(r"^diagnose_postgresql_connection\(\) \{\n.*?^\}", SOURCE, re.M | re.S).group()


class PostgreSQLDiagnosticsTest(unittest.TestCase):
    def diagnose(self, database="belimbing_sbg", user="postgres", auth_ok=True, database_ok=False):
        env = os.environ | {
            "TEST_DATABASE": database, "TEST_USER": user,
            "TEST_AUTH_OK": str(int(auth_ok)), "TEST_DATABASE_OK": str(int(database_ok)),
        }
        script = r'''set -u
RED= GREEN= YELLOW= CYAN= NC=
ENV_KEY_DB_HOST=DB_HOST ENV_KEY_DB_PORT=DB_PORT ENV_KEY_DB_DATABASE=DB_DATABASE
ENV_KEY_DB_USERNAME=DB_USERNAME ENV_KEY_DB_PASSWORD=DB_PASSWORD
get_env_var() {
    case "$1" in
        DB_HOST) printf '%s' '127.0.0.1' ;;
        DB_PORT) printf '%s' '5433' ;;
        DB_DATABASE) printf '%s' "$TEST_DATABASE" ;;
        DB_USERNAME) printf '%s' "$TEST_USER" ;;
        DB_PASSWORD) printf '%s' 'sentinel-secret' ;;
    esac
}
pg_isready() { return 0; }
psql() {
    local database=
    while (( $# )); do
        if [[ "$1" == -d ]]; then database=$2; shift; fi
        shift
    done
    [[ "$TEST_AUTH_OK" == 1 ]] || return 1
    [[ "$database" == postgres || "$TEST_DATABASE_OK" == 1 ]]
}
'''
        result = subprocess.run(["bash", "-c", script + DIAGNOSE + "\ndiagnose_postgresql_connection"], env=env, text=True, capture_output=True, check=False)
        output = result.stdout + result.stderr
        self.assertNotIn("sentinel-secret", output)
        return result.returncode, output

    def test_missing_database_hint_targets_the_verified_server_and_quotes_names(self):
        for database, user in [("belimbing_sbg", "postgres"), ('odd db;$(printf injected)"', "app role")]:
            with self.subTest(database=database):
                status, output = self.diagnose(database, user)
                self.assertEqual(status, 1)
                hint = next((line.strip() for line in output.splitlines() if line.strip().startswith("createdb ")), None)
                self.assertIsNotNone(hint, output)
                # Execute only a shell stub to verify copy/paste preserves every argument.
                result = subprocess.run(["bash", "-c", "createdb() { printf '%s\\0' \"$@\"; }; " + hint], capture_output=True, check=True)
                self.assertEqual(result.stdout.decode().split("\0")[:-1], ["-h", "127.0.0.1", "-p", "5433", "-U", user, "--maintenance-db=postgres", "--owner", user, "--", database])
                self.assertNotIn("sudo -u postgres psql", output)

    def test_authentication_failure_does_not_offer_a_reset_on_an_unverified_cluster(self):
        status, output = self.diagnose(auth_ok=False)
        self.assertEqual(status, 1)
        self.assertIn("Authentication failed", output)
        self.assertIn("127.0.0.1:5433", output)
        self.assertNotIn("ALTER USER", output)
        self.assertNotIn("createdb ", output)

    def test_accessible_database_does_not_offer_creation(self):
        _, output = self.diagnose(database_ok=True)
        self.assertIn("Database belimbing_sbg is accessible", output)
        self.assertNotIn("createdb ", output)


if __name__ == "__main__":
    unittest.main()
