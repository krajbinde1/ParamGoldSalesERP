import unittest

from tally_client import (
    _closing_balance_from_text,
    interpret_closing_balance,
    parse_ledger_closing_balances,
)


class ClosingBalanceSignTest(unittest.TestCase):
    def test_negative_xml_amount_is_debit(self) -> None:
        parsed = interpret_closing_balance("-3393284.20")
        self.assertEqual(parsed["closing_balance"], 3393284.20)
        self.assertEqual(parsed["closing_balance_numeric"], -3393284.20)
        self.assertEqual(parsed["closing_balance_type"], "debit")

    def test_positive_xml_amount_without_flags_is_debit_for_debtors(self) -> None:
        amount, balance_type = _closing_balance_from_text("3393284.20")
        self.assertEqual(amount, 3393284.20)
        self.assertEqual(balance_type, "debit")

    def test_tally_is_debit_yes_wins_over_positive_number(self) -> None:
        parsed = interpret_closing_balance("3393284.20", tally_is_debit=True)
        self.assertEqual(parsed["closing_balance_type"], "debit")

    def test_tally_is_debit_no_wins_over_negative_number(self) -> None:
        parsed = interpret_closing_balance("-3393284.20", tally_is_debit=False)
        self.assertEqual(parsed["closing_balance_type"], "credit")

    def test_positive_liability_deemed_not_positive_is_credit(self) -> None:
        parsed = interpret_closing_balance("12500.50", deemed_positive=False)
        self.assertEqual(parsed["closing_balance_type"], "credit")
        self.assertEqual(parsed["closing_balance"], 12500.50)

    def test_explicit_dr_suffix_wins(self) -> None:
        amount, balance_type = _closing_balance_from_text("33,93,284.20 Dr")
        self.assertEqual(amount, 3393284.20)
        self.assertEqual(balance_type, "debit")

    def test_explicit_cr_suffix_wins(self) -> None:
        amount, balance_type = _closing_balance_from_text("33,93,284.20 Cr")
        self.assertEqual(amount, 3393284.20)
        self.assertEqual(balance_type, "credit")

    def test_zero_is_debit(self) -> None:
        amount, balance_type = _closing_balance_from_text("0.00")
        self.assertEqual(amount, 0.0)
        self.assertEqual(balance_type, "debit")

    def test_ledger_collection_xml_uses_tally_is_debit_and_raw_sign(self) -> None:
        xml = """
        <ENVELOPE><BODY><DATA>
        <LEDGER NAME="Amrut Fertilizers Purna">
            <NAME>Amrut Fertilizers Purna</NAME>
            <PARENT>Sundry Debtors</PARENT>
            <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>
            <TALLYISDEBIT>Yes</TALLYISDEBIT>
            <CLOSINGBALANCE>-3393284.20</CLOSINGBALANCE>
        </LEDGER>
        <LEDGER NAME="Supplier Credit Party">
            <NAME>Supplier Credit Party</NAME>
            <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
            <TALLYISDEBIT>No</TALLYISDEBIT>
            <CLOSINGBALANCE>12500.50</CLOSINGBALANCE>
        </LEDGER>
        </DATA></BODY></ENVELOPE>
        """
        rows = {row["tally_ledger_name"]: row for row in parse_ledger_closing_balances(xml)}
        debtor = rows["Amrut Fertilizers Purna"]
        creditor = rows["Supplier Credit Party"]
        self.assertEqual(debtor["closing_balance_raw"], "-3393284.20")
        self.assertEqual(debtor["closing_balance_numeric"], -3393284.20)
        self.assertEqual(debtor["closing_balance"], 3393284.20)
        self.assertEqual(debtor["closing_balance_type"], "debit")
        self.assertEqual(creditor["closing_balance_type"], "credit")


if __name__ == "__main__":
    unittest.main()
