import unittest

from tally_client import (
    interpret_closing_balance,
    parse_journal_vouchers,
    parse_ledger_closing_balances,
)


class ClosingBalanceSignTest(unittest.TestCase):
    def test_opening_only_debtor_positive_amount_is_debit(self) -> None:
        parsed = interpret_closing_balance(
            "30003",
            tally_is_debit=False,
            deemed_positive=True,
            parent="Sundry Debtors",
        )
        self.assertEqual(parsed["closing_balance"], 30003.0)
        self.assertEqual(parsed["closing_balance_type"], "debit")

    def test_opening_only_debtor_xml_without_isdebit_is_debit(self) -> None:
        xml = """
        <LEDGER NAME="Kakde Patil Krushi seva Kendra (Dhabadi)">
            <NAME>Kakde Patil Krushi seva Kendra (Dhabadi)</NAME>
            <PARENT>Sundry Debtors</PARENT>
            <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>
            <TALLYISDEBIT>No</TALLYISDEBIT>
            <OPENINGBALANCE>30003.00</OPENINGBALANCE>
            <CLOSINGBALANCE>30003.00</CLOSINGBALANCE>
        </LEDGER>
        """
        rows = parse_ledger_closing_balances(f"<ENVELOPE>{xml}</ENVELOPE>")
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["closing_balance"], 30003.0)
        self.assertEqual(rows[0]["closing_balance_type"], "debit")

    def test_debtor_with_transactions_uses_isdebit_yes(self) -> None:
        parsed = interpret_closing_balance(
            "-3393284.20",
            tally_is_debit=True,
            deemed_positive=True,
            parent="Sundry Debtors",
        )
        self.assertEqual(parsed["closing_balance_type"], "debit")
        self.assertEqual(parsed["closing_balance"], 3393284.20)

    def test_credit_balance_on_debtor_uses_negative_unnatural_side(self) -> None:
        parsed = interpret_closing_balance(
            "-12500.50",
            tally_is_debit=False,
            tally_is_negative=True,
            deemed_positive=True,
            parent="Sundry Debtors",
        )
        self.assertEqual(parsed["closing_balance_type"], "credit")
        self.assertEqual(parsed["closing_balance"], 12500.50)

    def test_deposit_creditor_positive_amount_is_credit(self) -> None:
        parsed = interpret_closing_balance(
            "8000",
            tally_is_debit=False,
            deemed_positive=False,
            parent="Sundry Creditors",
        )
        self.assertEqual(parsed["closing_balance_type"], "credit")
        self.assertEqual(parsed["closing_balance"], 8000.0)

    def test_deposit_parent_without_deemed_flag_is_credit(self) -> None:
        parsed = interpret_closing_balance(
            "4500",
            parent="Deposits",
        )
        self.assertEqual(parsed["closing_balance_type"], "credit")

    def test_explicit_dr_suffix_wins(self) -> None:
        parsed = interpret_closing_balance("30,003.00 Dr")
        self.assertEqual(parsed["closing_balance"], 30003.0)
        self.assertEqual(parsed["closing_balance_type"], "debit")

    def test_explicit_cr_suffix_wins(self) -> None:
        parsed = interpret_closing_balance("30,003.00 Cr")
        self.assertEqual(parsed["closing_balance"], 30003.0)
        self.assertEqual(parsed["closing_balance_type"], "credit")

    def test_opening_is_debit_yes_for_opening_only_ledger(self) -> None:
        parsed = interpret_closing_balance(
            "30003",
            tally_is_debit=False,
            opening_is_debit=True,
            deemed_positive=True,
        )
        self.assertEqual(parsed["closing_balance_type"], "debit")

    def test_does_not_use_positive_equals_credit(self) -> None:
        parsed = interpret_closing_balance("30003")
        self.assertEqual(parsed["closing_balance_type"], "debit")

    def test_nested_ledger_xml_is_not_parsed_twice(self) -> None:
        xml = """
        <LEDGER.LIST>
            <LEDGER NAME="Mirai Krushi Seva Kendra (Murtajapur)">
                <NAME>Mirai Krushi Seva Kendra (Murtajapur)&#4;MIRAI</NAME>
                <PARENT>Sundry Debtors</PARENT>
                <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>
                <CLOSINGBALANCE>-445161.00</CLOSINGBALANCE>
            </LEDGER>
        </LEDGER.LIST>
        """
        rows = parse_ledger_closing_balances(f"<ENVELOPE>{xml}</ENVELOPE>")
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["tally_ledger_name"], "Mirai Krushi Seva Kendra (Murtajapur)")
        self.assertEqual(rows[0]["closing_balance"], 445161.0)

    def test_encoded_parentheses_stay_in_ledger_name(self) -> None:
        xml = """
        <LEDGER NAME="Mirai Krushi Seva Kendra &#40;Murtajapur&#41;">
            <NAME>Mirai Krushi Seva Kendra &#40;Murtajapur&#41;</NAME>
            <PARENT>Sundry Debtors</PARENT>
            <CLOSINGBALANCE>-445161.00</CLOSINGBALANCE>
        </LEDGER>
        """
        rows = parse_ledger_closing_balances(f"<ENVELOPE>{xml}</ENVELOPE>")
        self.assertEqual(len(rows), 1)
        self.assertEqual(
            rows[0]["tally_ledger_name"],
            "Mirai Krushi Seva Kendra (Murtajapur)",
        )

    def test_parses_ledger_guid_from_xml(self) -> None:
        xml = """
        <LEDGER NAME="Mirai Krushi Seva Kendra (Murtajapur)">
            <NAME>Mirai Krushi Seva Kendra (Murtajapur)</NAME>
            <GUID>A1B2C3D4-E5F6-7890-ABCD-EF1234567890</GUID>
            <PARENT>Sundry Debtors</PARENT>
            <CLOSINGBALANCE>-445161.00</CLOSINGBALANCE>
        </LEDGER>
        """
        rows = parse_ledger_closing_balances(f"<ENVELOPE>{xml}</ENVELOPE>")
        self.assertEqual(len(rows), 1)
        self.assertEqual(
            rows[0]["tally_ledger_guid"],
            "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
        )


class JournalVoucherParseTest(unittest.TestCase):
    def test_parses_journal_debit_and_credit_lines(self) -> None:
        xml = """
        <ENVELOPE>
          <VOUCHER DATE="20260516" VCHTYPE="Journal">
            <DATE>20260516</DATE>
            <GUID>bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb</GUID>
            <MASTERID>901</MASTERID>
            <VOUCHERTYPENAME>Journal</VOUCHERTYPENAME>
            <VOUCHERNUMBER>JV-12</VOUCHERNUMBER>
            <NARRATION>Interest receivable</NARRATION>
            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>Journal Party Agro</LEDGERNAME>
              <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>
              <AMOUNT>-1500.00</AMOUNT>
            </ALLLEDGERENTRIES.LIST>
            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>Interest Account</LEDGERNAME>
              <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
              <AMOUNT>1500.00</AMOUNT>
            </ALLLEDGERENTRIES.LIST>
          </VOUCHER>
        </ENVELOPE>
        """
        rows = parse_journal_vouchers(xml)
        self.assertEqual(len(rows), 2)
        self.assertEqual(rows[0]["voucher_type"], "Journal")
        self.assertEqual(rows[0]["voucher_no"], "JV-12")
        self.assertEqual(rows[0]["date"], "2026-05-16")
        self.assertEqual(rows[0]["narration"], "Interest receivable")
        self.assertEqual(rows[0]["party_ledger_name"], "Journal Party Agro")
        self.assertEqual(rows[0]["debit"], 1500.0)
        self.assertEqual(rows[0]["credit"], 0.0)
        self.assertEqual(rows[0]["master_id"], "901")
        self.assertFalse(rows[0]["cancelled"])
        self.assertEqual(rows[1]["party_ledger_name"], "Interest Account")
        self.assertEqual(rows[1]["credit"], 1500.0)

    def test_ignores_sales_vouchers(self) -> None:
        xml = """
        <VOUCHER VCHTYPE="Sales">
            <VOUCHERTYPENAME>Sales</VOUCHERTYPENAME>
            <VOUCHERNUMBER>SL-1</VOUCHERNUMBER>
            <DATE>20260516</DATE>
            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>Journal Party Agro</LEDGERNAME>
              <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>
              <AMOUNT>-2000.00</AMOUNT>
            </ALLLEDGERENTRIES.LIST>
        </VOUCHER>
        """
        self.assertEqual(parse_journal_vouchers(xml), [])

    def test_marks_cancelled_journal(self) -> None:
        xml = """
        <VOUCHER VCHTYPE="Journal">
            <VOUCHERTYPENAME>Journal</VOUCHERTYPENAME>
            <GUID>cccccccc-cccc-cccc-cccc-cccccccccccc</GUID>
            <DATE>20260520</DATE>
            <VOUCHERNUMBER>JV-13</VOUCHERNUMBER>
            <ISCANCELLED>Yes</ISCANCELLED>
            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>Journal Party Agro</LEDGERNAME>
              <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
              <AMOUNT>800.00</AMOUNT>
            </ALLLEDGERENTRIES.LIST>
        </VOUCHER>
        """
        rows = parse_journal_vouchers(xml)
        self.assertEqual(len(rows), 1)
        self.assertTrue(rows[0]["cancelled"])
        self.assertEqual(rows[0]["credit"], 800.0)


if __name__ == "__main__":
    unittest.main()
