"""Safety checks: no credentials, network or real DNS changes."""
import importlib.util
import os
from pathlib import Path
import unittest
from unittest.mock import Mock, patch

spec = importlib.util.spec_from_file_location("hook", Path(__file__).with_name("ispconfig-dns.py"))
hook = importlib.util.module_from_spec(spec)
spec.loader.exec_module(hook)


class HookTest(unittest.TestCase):
    def test_domain_guard_precedes_login(self):
        with patch.dict(os.environ, CERTBOT_DOMAIN="other.example", CERTBOT_VALIDATION="a" * 43), \
                patch.object(hook.sys, "argv", ["hook", "auth"]), patch.object(hook, "Dns") as dns:
            with self.assertRaisesRegex(RuntimeError, "scope"):
                hook.main()
            dns.assert_not_called()

    def test_invalid_token_precedes_login(self):
        with patch.dict(os.environ, CERTBOT_DOMAIN=hook.DOMAIN, CERTBOT_VALIDATION="bad token"), \
                patch.object(hook.sys, "argv", ["hook", "cleanup"]), patch.object(hook, "Dns") as dns:
            with self.assertRaisesRegex(RuntimeError, "token"):
                hook.main()
            dns.assert_not_called()

    def test_cleanup_matches_zone_name_type_and_token(self):
        row = {"id": "1", "zone": str(hook.ZONE_ID), "name": hook.NAME, "type": "TXT", "data": "a" * 43}
        rows = [row, dict(row, id="2", data="b" * 43), dict(row, id="3", zone="118"),
                dict(row, id="4", name="another.example."), dict(row, id="5", type="A")]
        dns = object.__new__(hook.Dns)
        dns.call = Mock(side_effect=[rows, True, rows[1:]])
        dns.cleanup("a" * 43)
        self.assertEqual(dns.call.call_args_list[1].args, ("dns_txt_delete",))
        self.assertEqual(dns.call.call_args_list[1].kwargs, {"primary_id": 1, "update_serial": True})
        self.assertEqual(dns.call.call_count, 3)

    def test_cleanup_is_idempotent(self):
        dns = object.__new__(hook.Dns)
        dns.call = Mock(return_value=[])
        dns.cleanup("a" * 43)
        self.assertTrue(all(call.args[0] == "dns_txt_get" for call in dns.call.call_args_list))

    def test_zone_guard_rejects_owner_change(self):
        dns = object.__new__(hook.Dns)
        dns.call = Mock(return_value={"id": 117, "origin": hook.DOMAIN + ".", "active": "Y",
                                      "sys_userid": 11, "sys_groupid": 10, "server_id": 3})
        with self.assertRaises(RuntimeError):
            dns.zone()

    def test_dns_checks_all_resolvers(self):
        token = "a" * 43
        with patch.object(hook.subprocess, "run", return_value=Mock(
                returncode=0, stdout='status: NOERROR\nTXT "' + token + '"')) as run:
            self.assertTrue(hook.propagated(token))
            self.assertEqual(run.call_count, len(hook.RESOLVERS))

    def test_dns_failure_is_not_successful_cleanup(self):
        with patch.object(hook.subprocess, "run", return_value=Mock(returncode=0, stdout="status: SERVFAIL")):
            self.assertFalse(hook.propagated("a" * 43, present=False))

    def test_propagation_failure_cleans_own_token_and_logs_out(self):
        with patch.dict(os.environ, CERTBOT_DOMAIN=hook.DOMAIN, CERTBOT_VALIDATION="a" * 43), \
                patch.object(hook.sys, "argv", ["hook", "auth"]), patch.object(hook, "Dns") as cls, \
                patch.object(hook, "wait_dns", side_effect=RuntimeError("timeout")):
            dns = cls.return_value
            dns.zone.return_value = {"server_id": 3}
            dns.records.return_value = []
            dns.call.return_value = 999
            with self.assertRaisesRegex(RuntimeError, "timeout"):
                hook.main()
            dns.cleanup.assert_called_once_with("a" * 43)
            dns.close.assert_called_once()
            params = dns.call.call_args.kwargs["params"]
            self.assertRegex(params["stamp"], r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$")
            self.assertGreater(params["serial"], 0)


if __name__ == "__main__":
    unittest.main()
