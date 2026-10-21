import re
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


def _tag(xml: str, tag: str) -> str:
    match = re.search(rf"<{tag}>(.*?)</{tag}>", xml, flags=re.DOTALL)
    return match.group(1) if match else ""


def _ledger_blocks(xml: str) -> list[str]:
    return re.findall(
        r"<ALLLEDGERENTRIES\.LIST>(.*?)</ALLLEDGERENTRIES\.LIST>",
        xml,
        flags=re.DOTALL,
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

        self._assert_receipt_ledgers(
            xml,
            debit_ledger="State Bank of India",
            credit_ledger="SBI Receipt Party",
            amount="12500.00",
            remote_id="ERP-COL-901",
            transaction_type="Inter Bank Transfer",
        )

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

        self._assert_receipt_ledgers(
            xml,
            debit_ledger="State Bank of India",
            credit_ledger="Legacy Party",
            amount="1000.00",
            remote_id="ERP-COL-100",
            transaction_type="Inter Bank Transfer",
        )

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

        self._assert_receipt_ledgers(
            xml,
            debit_ledger="State Bank of India",
            credit_ledger="Legacy Party",
            amount="1000.00",
            remote_id="ERP-COL-101",
            transaction_type="Inter Bank Transfer",
        )

    def test_cheque_receipt_keeps_bank_allocation_on_state_bank_of_india(self) -> None:
        xml = build_voucher_xml(
            {
                "voucher_type": "Receipt",
                "erp_reference": "ERP-COL-202",
                "payload": {
                    "date": "2026-10-21",
                    "party": {"tally_ledger_name": "Cheque Dealer"},
                    "collection": {
                        "receipt_no": "RCP-CHQ-1",
                        "amount": 2500.5,
                        "payment_mode": "Cheque",
                        "transaction_number": "445566",
                        "debit_ledger": "State Bank of India",
                    },
                },
            },
            _settings(),
        )

        self._assert_receipt_ledgers(
            xml,
            debit_ledger="State Bank of India",
            credit_ledger="Cheque Dealer",
            amount="2500.50",
            remote_id="ERP-COL-202",
            transaction_type="Cheque/DD",
            instrument_number="445566",
        )
        self.assertNotIn("<TRANSACTIONTYPE>Cheque</TRANSACTIONTYPE>", xml)

    def test_sales_voucher_ledger_xml_is_unchanged(self) -> None:
        xml = build_voucher_xml(
            {
                "voucher_type": "Sales",
                "erp_reference": "ERP-SO-1",
                "payload": {
                    "date": "2026-09-27",
                    "party": {"tally_ledger_name": "Sales Party"},
                    "order": {
                        "bill_number": "BILL-1",
                        "grand_total": 1000,
                        "gst_amount": 0,
                        "round_off": 0,
                    },
                },
            },
            _settings(),
        )

        self.assertIn("<PARTYLEDGERNAME>Sales Party</PARTYLEDGERNAME>", xml)
        self.assertNotIn("<VCHENTRYMODE>", xml)
        self.assertNotIn("<BANKALLOCATIONS.LIST>", xml)
        self.assertNotIn("State Bank of India", xml)
        blocks = _ledger_blocks(xml)
        self.assertGreaterEqual(len(blocks), 2)
        self.assertEqual(_tag(blocks[0], "LEDGERNAME"), "Sales Party")
        self.assertEqual(_tag(blocks[0], "ISDEEMEDPOSITIVE"), "Yes")
        self.assertEqual(_tag(blocks[0], "AMOUNT"), "-1000.00")
        self.assertNotIn("<REMOVEZEROENTRIES>", blocks[0])

    def _assert_receipt_ledgers(
        self,
        xml: str,
        *,
        debit_ledger: str,
        credit_ledger: str,
        amount: str,
        remote_id: str,
        transaction_type: str,
        instrument_number: str = "",
    ) -> None:
        self.assertIn('<VOUCHER REMOTEID="{}"'.format(remote_id), xml)
        self.assertIn('VCHTYPE="Receipt"', xml)
        self.assertIn("<VOUCHERTYPENAME>Receipt</VOUCHERTYPENAME>", xml)
        self.assertEqual(_tag(xml, "PARTYLEDGERNAME"), credit_ledger)
        self.assertEqual(_tag(xml, "BASICBASEPARTYNAME"), credit_ledger)
        self.assertEqual(_tag(xml, "VCHENTRYMODE"), "Accounting Voucher")
        self.assertEqual(_tag(xml, "PERSISTEDVIEW"), "Accounting Voucher View")
        self.assertEqual(_tag(xml, "ISINVOICE"), "No")
        self.assertEqual(_tag(xml, "CHANGEVCHMODE"), "No")
        self.assertNotIn("<LEDGERNAME>Cash</LEDGERNAME>", xml)
        self.assertNotIn("<PARTYLEDGERNAME>Cash</PARTYLEDGERNAME>", xml)
        self.assertNotIn("<LEDGERNAME>Bank</LEDGERNAME>", xml)

        blocks = _ledger_blocks(xml)
        self.assertEqual(len(blocks), 2, xml)

        debit, credit = blocks
        self.assertEqual(_tag(debit, "LEDGERNAME"), debit_ledger)
        self.assertEqual(_tag(debit, "ISDEEMEDPOSITIVE"), "Yes")
        self.assertEqual(_tag(debit, "ISLASTDEEMEDPOSITIVE"), "Yes")
        self.assertEqual(_tag(debit, "ISPARTYLEDGER"), "No")
        self.assertEqual(_tag(debit, "AMOUNT"), f"-{amount}")
        self.assertIn("<BANKALLOCATIONS.LIST>", debit)
        self.assertNotIn("<BANKALLOCATIONS.LIST>", credit)
        self.assertEqual(_tag(debit, "NAME"), remote_id)
        self.assertEqual(_tag(debit, "TRANSACTIONTYPE"), transaction_type)
        self.assertEqual(_tag(debit, "PAYMENTMODE"), "Transacted")
        self.assertEqual(_tag(debit, "PAYMENTFAVOURING"), credit_ledger)
        self.assertEqual(_tag(debit, "BANKPARTYNAME"), credit_ledger)
        self.assertEqual(_tag(debit, "INSTRUMENTNUMBER"), instrument_number)
        bank_amount = _tag(debit[debit.index("<BANKALLOCATIONS.LIST>") :], "AMOUNT")
        self.assertEqual(bank_amount, f"-{amount}")

        self.assertEqual(_tag(credit, "LEDGERNAME"), credit_ledger)
        self.assertEqual(_tag(credit, "ISDEEMEDPOSITIVE"), "No")
        self.assertEqual(_tag(credit, "ISLASTDEEMEDPOSITIVE"), "No")
        self.assertEqual(_tag(credit, "ISPARTYLEDGER"), "Yes")
        self.assertEqual(_tag(credit, "AMOUNT"), amount)

        signed = [float(_tag(block, "AMOUNT")) for block in blocks]
        self.assertEqual(round(sum(signed), 2), 0.0)


if __name__ == "__main__":
    unittest.main()
