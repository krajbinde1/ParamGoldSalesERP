import unittest

from config import Settings
from vouchers import build_voucher_xml


def _settings() -> Settings:
    return Settings(
        erp_base_url="https://erp.example.test",
        erp_token="token",
        connector_id="test-pc",
        poll_interval_seconds=30,
        pending_limit=10,
        tally_url="http://localhost:9000",
        tally_company="",
        voucher_type_sales="Sales",
        voucher_type_receipt="Receipt",
        sales_ledger="Sales",
        gst_ledger="GST",
        round_off_ledger="Round Off",
        cash_ledger="Cash",
        bank_ledger="Bank",
        receipt_debit_ledger="State Bank of India",
    )


class ReceiptVoucherLedgerTest(unittest.TestCase):
    def test_new_receipt_debits_state_bank_of_india_and_credits_party(self) -> None:
        xml = build_voucher_xml(
            {
                "voucher_type": "Receipt",
                "erp_reference": "ERP-COL-901",
                "payload": {
                    "date": "2026-09-27",
                    "party": {"tally_ledger_name": "SBI Receipt Party"},
                    "collection": {
                        "receipt_no": "RCP-SBI-1",
                        "amount": 12500,
                        "payment_mode": "Cash",
                        "debit_ledger": "State Bank of India",
                    },
                },
            },
            _settings(),
        )

        self.assertIn("<PARTYLEDGERNAME>State Bank of India</PARTYLEDGERNAME>", xml)
        self.assertIn("<BASICBASEPARTYNAME>State Bank of India</BASICBASEPARTYNAME>", xml)
        self.assertNotIn("<PARTYLEDGERNAME>SBI Receipt Party</PARTYLEDGERNAME>", xml)
        self.assertIn("<LEDGERNAME>State Bank of India</LEDGERNAME>", xml)
        self.assertIn("<ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>", xml)
        self.assertIn("<LEDGERNAME>SBI Receipt Party</LEDGERNAME>", xml)
        self.assertIn("<ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>", xml)
        self.assertNotIn("<LEDGERNAME>Cash</LEDGERNAME>", xml)
        self.assertIn("<TRANSACTIONTYPE>Inter Bank Transfer</TRANSACTIONTYPE>", xml)
        debit_at = xml.index("<LEDGERNAME>State Bank of India</LEDGERNAME>")
        credit_at = xml.index("<LEDGERNAME>SBI Receipt Party</LEDGERNAME>")
        self.assertLess(debit_at, credit_at)

    def test_unsynced_cash_payload_debits_state_bank_of_india(self) -> None:
        xml = build_voucher_xml(
            {
                "voucher_type": "Receipt",
                "erp_reference": "ERP-COL-100",
                "payload": {
                    "date": "2026-09-12",
                    "party": {"tally_ledger_name": "Legacy Party"},
                    "collection": {
                        "receipt_no": "RCP-OLD-1",
                        "amount": 1000,
                        "payment_mode": "Cash",
                        "debit_ledger": "Cash",
                    },
                },
            },
            _settings(),
        )

        self.assertIn("<PARTYLEDGERNAME>State Bank of India</PARTYLEDGERNAME>", xml)
        self.assertIn("<LEDGERNAME>State Bank of India</LEDGERNAME>", xml)
        self.assertIn("<LEDGERNAME>Legacy Party</LEDGERNAME>", xml)
        self.assertNotIn("<LEDGERNAME>Cash</LEDGERNAME>", xml)
        self.assertNotIn("<PARTYLEDGERNAME>Cash</PARTYLEDGERNAME>", xml)

    def test_legacy_receipt_without_debit_ledger_debits_state_bank_of_india(self) -> None:
        xml = build_voucher_xml(
            {
                "voucher_type": "Receipt",
                "erp_reference": "ERP-COL-101",
                "payload": {
                    "date": "2026-09-12",
                    "party": {"tally_ledger_name": "Legacy Party"},
                    "collection": {
                        "receipt_no": "RCP-OLD-2",
                        "amount": 1000,
                        "payment_mode": "Cash",
                    },
                },
            },
            _settings(),
        )

        self.assertIn("<PARTYLEDGERNAME>State Bank of India</PARTYLEDGERNAME>", xml)
        self.assertIn("<LEDGERNAME>State Bank of India</LEDGERNAME>", xml)
        self.assertNotIn("<LEDGERNAME>Cash</LEDGERNAME>", xml)
        self.assertNotIn("<PARTYLEDGERNAME>Cash</PARTYLEDGERNAME>", xml)


if __name__ == "__main__":
    unittest.main()
