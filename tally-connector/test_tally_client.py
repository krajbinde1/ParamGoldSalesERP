import unittest

from tally_client import _closing_balance_from_text, parse_ledger_closing_balances


class ClosingBalanceSignTest(unittest.TestCase):
    def test_negative_xml_amount_is_debit(self) -> None:
        amount, balance_type = _closing_balance_from_text("-3393284.20")
        self.assertEqual(amount, 3393284.20)
        self.assertEqual(balance_type, "debit")

    def test_positive_xml_amount_is_credit(self) -> None:
        amount, balance_type = _closing_balance_from_text("3393284.20")
        self.assertEqual(amount, 3393284.20)
        self.assertEqual(balance_type, "credit")

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

    def test_ledger_collection_xml_negative_closing_is_dr(self) -> None:
        xml = """
        <ENVELOPE><BODY><DATA>
        <LEDGER NAME="Amrut Fertilizers Purna">
            <NAME>Amrut Fertilizers Purna</NAME>
            <PARENT>Sundry Debtors</PARENT>
            <CLOSINGBALANCE>-3393284.20</CLOSINGBALANCE>
        </LEDGER>
        <LEDGER NAME="Supplier Credit Party">
            <NAME>Supplier Credit Party</NAME>
            <CLOSINGBALANCE>12500.50</CLOSINGBALANCE>
        </LEDGER>
        </DATA></BODY></ENVELOPE>
        """
        rows = {row["tally_ledger_name"]: row for row in parse_ledger_closing_balances(xml)}
        self.assertEqual(rows["Amrut Fertilizers Purna"]["closing_balance"], 3393284.20)
        self.assertEqual(rows["Amrut Fertilizers Purna"]["closing_balance_type"], "debit")
        self.assertEqual(rows["Supplier Credit Party"]["closing_balance"], 12500.50)
        self.assertEqual(rows["Supplier Credit Party"]["closing_balance_type"], "credit")


if __name__ == "__main__":
    unittest.main()
