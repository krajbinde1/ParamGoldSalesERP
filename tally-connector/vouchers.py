from __future__ import annotations

from datetime import datetime
from typing import Any
from xml.sax.saxutils import escape

from config import Settings


class VoucherBuildError(Exception):
    pass


def build_voucher_xml(voucher: dict[str, Any], settings: Settings) -> str:
    voucher_type = str(voucher.get("voucher_type") or "").strip()
    payload = voucher.get("payload") if isinstance(voucher.get("payload"), dict) else {}
    remote_id = str(voucher.get("erp_reference") or payload.get("erp_reference") or "").strip()
    if remote_id == "":
        raise VoucherBuildError("Voucher is missing erp_reference / REMOTEID.")

    party_name = _party_ledger_name(payload)
    if voucher_type == "Sales":
        body = _sales_voucher(payload, remote_id, party_name, settings)
        tally_type = settings.voucher_type_sales
    elif voucher_type == "Receipt":
        body = _receipt_voucher(payload, remote_id, party_name, settings)
        tally_type = settings.voucher_type_receipt
    else:
        raise VoucherBuildError(f"Unsupported voucher type: {voucher_type or '(empty)'}")

    company_xml = ""
    if settings.tally_company:
        company_xml = (
            "<STATICVARIABLES>"
            f"<SVCURRENTCOMPANY>{_x(settings.tally_company)}</SVCURRENTCOMPANY>"
            "</STATICVARIABLES>"
        )

    return (
        "<ENVELOPE>"
        "<HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER>"
        "<BODY><IMPORTDATA>"
        "<REQUESTDESC>"
        "<REPORTNAME>Vouchers</REPORTNAME>"
        f"{company_xml}"
        "</REQUESTDESC>"
        "<REQUESTDATA>"
        '<TALLYMESSAGE xmlns:UDF="TallyUDF">'
        f'<VOUCHER REMOTEID="{_x(remote_id)}" VCHTYPE="{_x(tally_type)}" '
        'ACTION="Create" OBJVIEW="Accounting Voucher View">'
        f"{body}"
        "</VOUCHER>"
        "</TALLYMESSAGE>"
        "</REQUESTDATA>"
        "</IMPORTDATA></BODY>"
        "</ENVELOPE>"
    )


def _party_ledger_name(payload: dict[str, Any]) -> str:
    party = payload.get("party") if isinstance(payload.get("party"), dict) else {}
    name = str(party.get("tally_ledger_name") or "").strip()
    if name == "":
        raise VoucherBuildError(
            "Dealer has no Tally ledger mapping. Map this dealer in ERP before the voucher can be sent to Tally."
        )
    return name


def _sales_voucher(payload: dict[str, Any], remote_id: str, party_name: str, settings: Settings) -> str:
    order = payload.get("order") if isinstance(payload.get("order"), dict) else {}
    date_xml = _tally_date(payload.get("date") or order.get("bill_date"))
    grand_total = _money(order.get("grand_total"))
    gst_amount = _money(order.get("gst_amount"))
    round_off = _money(order.get("round_off"))
    sales_amount = _money(grand_total - gst_amount - round_off)
    if grand_total <= 0:
        raise VoucherBuildError("Sales voucher grand total must be greater than zero.")
    if sales_amount <= 0:
        raise VoucherBuildError("Sales ledger amount is not positive after GST and round off.")

    voucher_no = str(order.get("bill_number") or order.get("order_no") or remote_id).strip()
    narration = _sales_narration(order, remote_id)
    entries = [
        _ledger_entry(party_name, debit=grand_total, is_party=True),
        _ledger_entry(settings.sales_ledger, credit=sales_amount),
    ]
    if gst_amount != 0:
        entries.append(_gst_entry(settings.gst_ledger, gst_amount))
    if round_off != 0:
        entries.append(_round_off_entry(settings.round_off_ledger, round_off))

    return (
        f"<DATE>{date_xml}</DATE>"
        f"<VOUCHERTYPENAME>{_x(settings.voucher_type_sales)}</VOUCHERTYPENAME>"
        f"<VOUCHERNUMBER>{_x(voucher_no)}</VOUCHERNUMBER>"
        f"<REFERENCE>{_x(remote_id)}</REFERENCE>"
        f"<PARTYLEDGERNAME>{_x(party_name)}</PARTYLEDGERNAME>"
        f"<BASICBASEPARTYNAME>{_x(party_name)}</BASICBASEPARTYNAME>"
        "<PERSISTEDVIEW>Accounting Voucher View</PERSISTEDVIEW>"
        f"<EFFECTIVEDATE>{date_xml}</EFFECTIVEDATE>"
        "<ISINVOICE>No</ISINVOICE>"
        f"<NARRATION>{_x(narration)}</NARRATION>"
        f"{''.join(entries)}"
    )


def _receipt_voucher(payload: dict[str, Any], remote_id: str, party_name: str, settings: Settings) -> str:
    collection = payload.get("collection") if isinstance(payload.get("collection"), dict) else {}
    date_xml = _tally_date(payload.get("date") or collection.get("collection_date"))
    amount = _money(collection.get("amount"))
    if amount <= 0:
        raise VoucherBuildError("Receipt voucher amount must be greater than zero.")

    cash_ledger = _cash_or_bank_ledger(str(collection.get("payment_mode") or "Cash"), settings)
    voucher_no = str(collection.get("receipt_no") or remote_id).strip()
    narration = _receipt_narration(collection, remote_id)

    return (
        f"<DATE>{date_xml}</DATE>"
        f"<VOUCHERTYPENAME>{_x(settings.voucher_type_receipt)}</VOUCHERTYPENAME>"
        f"<VOUCHERNUMBER>{_x(voucher_no)}</VOUCHERNUMBER>"
        f"<REFERENCE>{_x(remote_id)}</REFERENCE>"
        f"<PARTYLEDGERNAME>{_x(party_name)}</PARTYLEDGERNAME>"
        f"<BASICBASEPARTYNAME>{_x(party_name)}</BASICBASEPARTYNAME>"
        "<PERSISTEDVIEW>Accounting Voucher View</PERSISTEDVIEW>"
        f"<EFFECTIVEDATE>{date_xml}</EFFECTIVEDATE>"
        f"<NARRATION>{_x(narration)}</NARRATION>"
        f"{_ledger_entry(cash_ledger, debit=amount)}"
        f"{_ledger_entry(party_name, credit=amount, is_party=True)}"
    )


def _cash_or_bank_ledger(payment_mode: str, settings: Settings) -> str:
    mode = payment_mode.strip().lower()
    if mode in {"", "cash"}:
        return settings.cash_ledger
    return settings.bank_ledger


def _sales_narration(order: dict[str, Any], remote_id: str) -> str:
    order_no = str(order.get("order_no") or "").strip()
    bill_no = str(order.get("bill_number") or "").strip()
    parts = [f"ERP {remote_id}"]
    if order_no:
        parts.append(f"Order {order_no}")
    if bill_no:
        parts.append(f"Bill {bill_no}")
    return " | ".join(parts)


def _receipt_narration(collection: dict[str, Any], remote_id: str) -> str:
    parts = [f"ERP {remote_id}"]
    receipt_no = str(collection.get("receipt_no") or "").strip()
    mode = str(collection.get("payment_mode") or "").strip()
    txn = str(collection.get("transaction_number") or "").strip()
    bank = str(collection.get("bank_name") or "").strip()
    remarks = str(collection.get("remarks") or "").strip()
    if receipt_no:
        parts.append(f"Receipt {receipt_no}")
    if mode:
        parts.append(mode)
    if bank:
        parts.append(bank)
    if txn:
        parts.append(f"Txn {txn}")
    if remarks:
        parts.append(remarks)
    return " | ".join(parts)


def _ledger_entry(
    name: str,
    *,
    debit: float | None = None,
    credit: float | None = None,
    is_party: bool = False,
) -> str:
    if debit is not None:
        deemed = "Yes"
        amount = -abs(debit)
    elif credit is not None:
        deemed = "No"
        amount = abs(credit)
    else:
        raise VoucherBuildError("Ledger entry is missing debit/credit.")

    party = "Yes" if is_party else "No"
    return (
        "<ALLLEDGERENTRIES.LIST>"
        f"<LEDGERNAME>{_x(name)}</LEDGERNAME>"
        f"<ISDEEMEDPOSITIVE>{deemed}</ISDEEMEDPOSITIVE>"
        f"<ISPARTYLEDGER>{party}</ISPARTYLEDGER>"
        "<LEDGERFROMITEM>No</LEDGERFROMITEM>"
        f"<AMOUNT>{_amount(amount)}</AMOUNT>"
        "</ALLLEDGERENTRIES.LIST>"
    )


def _gst_entry(ledger_name: str, amount: float) -> str:
    if amount > 0:
        return _ledger_entry(ledger_name, credit=amount)
    return _ledger_entry(ledger_name, debit=abs(amount))


def _round_off_entry(ledger_name: str, amount: float) -> str:
    if amount > 0:
        return _ledger_entry(ledger_name, credit=amount)
    return _ledger_entry(ledger_name, debit=abs(amount))


def _tally_date(value: Any) -> str:
    text = str(value or "").strip()
    if len(text) >= 10 and text[4] == "-" and text[7] == "-":
        return text[:10].replace("-", "")
    try:
        parsed = datetime.fromisoformat(text.replace("Z", "+00:00"))
        return parsed.strftime("%Y%m%d")
    except ValueError:
        return datetime.now().strftime("%Y%m%d")


def _money(value: Any) -> float:
    try:
        return round(float(value or 0), 2)
    except (TypeError, ValueError):
        return 0.0


def _amount(value: float) -> str:
    return f"{value:.2f}"


def _x(value: str) -> str:
    return escape(value, {'"': "&quot;", "'": "&apos;"})
