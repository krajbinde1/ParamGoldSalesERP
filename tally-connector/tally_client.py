from __future__ import annotations

import re
from dataclasses import dataclass
from datetime import date

import requests


class TallyError(Exception):
    pass


@dataclass(frozen=True)
class TallyResult:
    created: int
    altered: int
    errors: int
    exceptions: int
    last_vch_id: str
    raw: str

    @property
    def succeeded(self) -> bool:
        if self.errors > 0 or self.exceptions > 0:
            return False
        if self._line_errors():
            return False
        if self.created > 0 or self.altered > 0:
            return True
        lowered = self.raw.lower()
        if "lineerror" in lowered:
            return False
        if "imported" in lowered:
            return True
        return False

    def error_message(self) -> str:
        lines = self._line_errors()
        if lines:
            return " | ".join(lines)[:2000]
        if self.errors or self.exceptions:
            return (
                f"Tally reported errors={self.errors} exceptions={self.exceptions}. "
                f"{self.raw.strip()[:1500]}"
            ).strip()
        text = self.raw.strip()
        return text[:2000] if text else "Tally did not import the voucher."

    def _line_errors(self) -> list[str]:
        return [
            match.strip()
            for match in re.findall(
                r"<LINEERROR[^>]*>(.*?)</LINEERROR>",
                self.raw,
                flags=re.IGNORECASE | re.DOTALL,
            )
            if match.strip()
        ]


class TallyClient:
    def __init__(self, url: str, company: str = "", timeout: int = 60) -> None:
        self.url = url.rstrip("/")
        self.company = company
        self.timeout = timeout
        self.session = requests.Session()

    def ping(self) -> None:
        xml = (
            "<ENVELOPE>"
            "<HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST>"
            "<TYPE>Collection</TYPE><ID>Company</ID></HEADER>"
            "<BODY><DESC><STATICVARIABLES>"
            "<SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>"
            "</STATICVARIABLES></DESC></BODY>"
            "</ENVELOPE>"
        )
        self._post_xml(xml, min(self.timeout, 15))

    def import_voucher(self, xml: str) -> TallyResult:
        raw = self._post_xml(xml, self.timeout)
        return TallyResult(
            created=_int_tag(raw, "CREATED"),
            altered=_int_tag(raw, "ALTERED"),
            errors=_int_tag(raw, "ERRORS"),
            exceptions=_int_tag(raw, "EXCEPTIONS"),
            last_vch_id=_text_tag(raw, "LASTVCHID"),
            raw=raw,
        )

    def ledger_closing_balances(self) -> list[dict[str, str | float | bool | None]]:
        company_xml = ""
        if self.company:
            escaped = (
                self.company.replace("&", "&amp;")
                .replace("<", "&lt;")
                .replace(">", "&gt;")
            )
            company_xml = f"<SVCURRENTCOMPANY>{escaped}</SVCURRENTCOMPANY>"

        xml = (
            "<ENVELOPE>"
            "<HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST>"
            "<TYPE>Collection</TYPE><ID>AllLedgers</ID></HEADER>"
            "<BODY><DESC><STATICVARIABLES>"
            "<SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>"
            f"{company_xml}"
            "</STATICVARIABLES>"
            "<TDL><TDLMESSAGE>"
            '<COLLECTION NAME="AllLedgers" ISMODIFY="No">'
            "<TYPE>Ledger</TYPE>"
            "<NATIVEMETHOD>Name</NATIVEMETHOD>"
            "<NATIVEMETHOD>GUID</NATIVEMETHOD>"
            "<NATIVEMETHOD>MasterId</NATIVEMETHOD>"
            "<NATIVEMETHOD>Parent</NATIVEMETHOD>"
            "<NATIVEMETHOD>OpeningBalance</NATIVEMETHOD>"
            "<NATIVEMETHOD>ClosingBalance</NATIVEMETHOD>"
            "<NATIVEMETHOD>IsDeemedPositive</NATIVEMETHOD>"
            "<COMPUTE>TALLYISDEBIT:$$IsDebit:$ClosingBalance</COMPUTE>"
            "<COMPUTE>TALLYOPENISDEBIT:$$IsDebit:$OpeningBalance</COMPUTE>"
            "<COMPUTE>TALLYISNEGATIVE:$$IsNegative:$ClosingBalance</COMPUTE>"
            "</COLLECTION>"
            "</TDLMESSAGE></TDL>"
            "</DESC></BODY></ENVELOPE>"
        )
        raw = self._post_xml(xml, self.timeout)
        return parse_ledger_closing_balances(raw)

    def journal_vouchers(self, from_date: str = "2026-04-01") -> list[dict[str, str | float | bool | int | None]]:
        company_xml = ""
        if self.company:
            escaped = (
                self.company.replace("&", "&amp;")
                .replace("<", "&lt;")
                .replace(">", "&gt;")
            )
            company_xml = f"<SVCURRENTCOMPANY>{escaped}</SVCURRENTCOMPANY>"

        from_xml = _tally_date(from_date)
        to_xml = date.today().strftime("%Y%m%d")
        xml = (
            "<ENVELOPE>"
            "<HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST>"
            "<TYPE>Collection</TYPE><ID>JournalVouchers</ID></HEADER>"
            "<BODY><DESC><STATICVARIABLES>"
            "<SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>"
            f"<SVFROMDATE>{from_xml}</SVFROMDATE>"
            f"<SVTODATE>{to_xml}</SVTODATE>"
            f"{company_xml}"
            "</STATICVARIABLES>"
            "<TDL><TDLMESSAGE>"
            '<COLLECTION NAME="JournalVouchers" ISMODIFY="No">'
            "<TYPE>Voucher</TYPE>"
            "<FETCH>Date, VoucherNumber, VoucherTypeName, Narration, GUID, MasterId, "
            "IsCancelled, AllLedgerEntries.*, LedgerEntries.*</FETCH>"
            "<FILTER>IsJournalVoucher</FILTER>"
            "</COLLECTION>"
            '<SYSTEM TYPE="Formulae" NAME="IsJournalVoucher">'
            "$$IsSysNameEqual:$VoucherTypeName:Journal"
            "</SYSTEM>"
            "</TDLMESSAGE></TDL>"
            "</DESC></BODY></ENVELOPE>"
        )
        raw = self._post_xml(xml, max(self.timeout, 180))
        return parse_journal_vouchers(raw)


    def _post_xml(self, xml: str, timeout: int) -> str:
        try:
            response = self.session.post(
                self.url,
                data=xml.encode("utf-8"),
                headers={"Content-Type": "application/xml"},
                timeout=timeout,
            )
        except requests.RequestException as exc:
            raise TallyError(
                f"Tally Prime is not reachable at {self.url}. Open Tally and enable the HTTP server. ({exc})"
            ) from exc

        if response.status_code >= 400:
            raise TallyError(
                f"Tally Prime HTTP {response.status_code} at {self.url}: {response.text[:500]}"
            )

        return _decode_tally_xml(response.content, response.text)


def parse_ledger_closing_balances(xml: str) -> list[dict[str, str | float | bool | None]]:
    balances: list[dict[str, str | float | bool | None]] = []
    occupied: list[tuple[int, int]] = []
    for match in re.finditer(
        r"<(LEDGER(?:\.LIST)?)([^>]*)>(.*?)</\1>",
        xml,
        flags=re.IGNORECASE | re.DOTALL,
    ):
        start, end = match.start(), match.end()
        if any(outer_start < start and end <= outer_end for outer_start, outer_end in occupied):
            continue
        attrs = match.group(2)
        block = match.group(3)
        name = _ledger_name(block) or _attr_name(attrs)
        parsed = _closing_balance(block)
        if name == "" or parsed is None:
            continue
        parsed["tally_ledger_name"] = name
        parsed["tally_ledger_guid"] = _ledger_guid(block, attrs)
        balances.append(parsed)
        occupied.append((start, end))
    return balances


def parse_journal_vouchers(xml: str) -> list[dict[str, str | float | bool | int | None]]:
    entries: list[dict[str, str | float | bool | int | None]] = []
    occupied: list[tuple[int, int]] = []
    for match in re.finditer(
        r"<VOUCHER([^>]*)>(.*?)</VOUCHER>",
        xml,
        flags=re.IGNORECASE | re.DOTALL,
    ):
        start, end = match.start(), match.end()
        if any(outer_start < start and end <= outer_end for outer_start, outer_end in occupied):
            continue
        attrs = match.group(1)
        block = match.group(2)
        occupied.append((start, end))
        voucher_type = _voucher_type(block, attrs)
        if not _is_journal_type(voucher_type):
            continue
        guid = _ledger_guid(block, attrs)
        master_id = _tag_text(block, "MASTERID") or _attr_value(attrs, "MASTERID")
        voucher_no = _tag_text(block, "VOUCHERNUMBER") or _attr_value(attrs, "VOUCHERNUMBER")
        date = _voucher_date(block, attrs)
        narration = _tag_text(block, "NARRATION")
        cancelled = _yes_no(_tag_text(block, "ISCANCELLED")) is True or _yes_no(
            _attr_value(attrs, "ISCANCELLED")
        ) is True
        ledger_blocks = _voucher_ledger_blocks(block)
        for index, ledger_block in enumerate(ledger_blocks):
            ledger_name = _entry_ledger_name(ledger_block)
            if ledger_name == "":
                continue
            debit, credit = _entry_debit_credit(ledger_block)
            if debit <= 0 and credit <= 0 and not cancelled:
                continue
            entries.append(
                {
                    "voucher_type": "Journal",
                    "voucher_guid": guid,
                    "master_id": master_id.strip()[:100],
                    "voucher_no": voucher_no.strip(),
                    "date": date,
                    "narration": narration.strip()[:2000],
                    "cancelled": cancelled,
                    "party_ledger_name": ledger_name,
                    "party_ledger_guid": _ledger_guid(ledger_block),
                    "debit": debit,
                    "credit": credit,
                    "entry_index": index,
                }
            )
    return entries


def _tally_date(value: str) -> str:
    text = (value or "").strip()
    if len(text) >= 10 and text[4] == "-" and text[7] == "-":
        return text[:10].replace("-", "")
    digits = re.sub(r"\D", "", text)
    if len(digits) >= 8:
        return digits[:8]
    return "20260401"


def _voucher_type(block: str, attrs: str) -> str:
    name = _tag_text(block, "VOUCHERTYPENAME") or _attr_value(attrs, "VCHTYPE")
    return re.sub(r"\s+", " ", name).strip()


def _is_journal_type(voucher_type: str) -> bool:
    normalized = re.sub(r"[\s_]+", " ", (voucher_type or "").strip().lower())
    return normalized == "journal" or normalized.startswith("journal ")


def _voucher_date(block: str, attrs: str) -> str:
    raw = _tag_text(block, "DATE") or _attr_value(attrs, "DATE")
    digits = re.sub(r"\D", "", raw)
    if len(digits) >= 8:
        return f"{digits[0:4]}-{digits[4:6]}-{digits[6:8]}"
    return ""


def _voucher_ledger_blocks(block: str) -> list[str]:
    blocks: list[str] = []
    for tag in ("ALLLEDGERENTRIES.LIST", "LEDGERENTRIES.LIST"):
        for match in re.finditer(
            rf"<{re.escape(tag)}[^>]*>(.*?)</{re.escape(tag)}>",
            block,
            flags=re.IGNORECASE | re.DOTALL,
        ):
            blocks.append(match.group(1))
        if blocks:
            return blocks
    return blocks


def _entry_ledger_name(block: str) -> str:
    name = _tag_text(block, "LEDGERNAME") or _ledger_name(block)
    return _clean_ledger_name(name)


def _entry_debit_credit(block: str) -> tuple[float, float]:
    deemed = _yes_no(_tag_text(block, "ISDEEMEDPOSITIVE"))
    amount = _xml_amount(_tag_text(block, "AMOUNT"))
    if amount == 0.0:
        return 0.0, 0.0
    if deemed is True or amount < 0:
        return round(abs(amount), 2), 0.0
    return 0.0, round(abs(amount), 2)


def _xml_amount(raw: str) -> float:
    text = (
        (raw or "")
        .replace("\u2212", "-")
        .replace("\u2013", "-")
        .replace("\u2014", "-")
    )
    text = re.sub(r"[₹,\s]", "", text)
    match = re.search(r"-?\d+(?:\.\d+)?", text)
    return float(match.group(0)) if match is not None else 0.0


def _decode_tally_xml(content: bytes, fallback: str) -> str:
    if not content:
        return fallback or ""
    for encoding in ("utf-8-sig", "utf-16", "utf-16le"):
        try:
            return content.decode(encoding)
        except UnicodeDecodeError:
            continue
    return fallback or content.decode("utf-8", errors="ignore")


def _ledger_guid(block: str, attrs: str = "") -> str:
    guid = _clean_guid(_tag_text(block, "GUID"))
    if guid == "":
        guid = _clean_guid(_attr_value(attrs, "GUID"))
    return guid


def _clean_guid(value: str) -> str:
    text = (value or "").strip().lower().replace("{", "").replace("}", "")
    text = re.sub(r"\s+", "", text)
    if text in {"", "00000000-0000-0000-0000-000000000000"}:
        return ""
    return text[:80]


def _attr_value(attrs: str, key: str) -> str:
    match = re.search(rf'\b{key}="([^"]+)"', attrs, flags=re.IGNORECASE)
    return match.group(1) if match else ""


def _attr_name(attrs: str) -> str:
    match = re.search(r'\bNAME="([^"]+)"', attrs, flags=re.IGNORECASE)
    return _clean_ledger_name(match.group(1) if match else "")


def _ledger_name(block: str) -> str:
    match = re.search(
        r"<NAME(?:\.LIST)?[^>]*>\s*(?:<NAME[^>]*>)?\s*([^<]+)",
        block,
        flags=re.IGNORECASE,
    )
    return _clean_ledger_name(match.group(1) if match else "")


def _clean_ledger_name(name: str) -> str:
    text = name or ""
    text = re.sub(
        r"&#(?:x0*4|0*4);",
        "\x04",
        text,
        flags=re.IGNORECASE,
    )
    text = re.sub(
        r"&#(\d+);|&#x([0-9a-fA-F]+);",
        lambda match: chr(int(match.group(1) or "0")) if match.group(1) else chr(int(match.group(2), 16)),
        text,
    )
    text = (
        text.replace("&amp;", "&")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&quot;", '"')
        .replace("&apos;", "'")
    )
    text = text.split("\x04", 1)[0]
    text = text.replace("\u00a0", " ").replace("\u202f", " ").replace("\u2007", " ")
    return re.sub(r"\s+", " ", text).strip()


def _closing_balance(block: str) -> dict[str, str | float | bool | None] | None:
    match = re.search(
        r"<CLOSINGBALANCE([^>]*)>(.*?)</CLOSINGBALANCE>",
        block,
        flags=re.IGNORECASE | re.DOTALL,
    )
    opening_match = re.search(
        r"<OPENINGBALANCE([^>]*)>(.*?)</OPENINGBALANCE>",
        block,
        flags=re.IGNORECASE | re.DOTALL,
    )
    raw = ""
    if match is not None:
        raw = re.sub(r"<[^>]+>", "", match.group(2)).strip()
        if raw == "":
            raw = match.group(1).strip()
    if raw == "" and opening_match is not None:
        raw = re.sub(r"<[^>]+>", "", opening_match.group(2)).strip()
    if match is None and opening_match is None:
        return None
    return interpret_closing_balance(
        raw,
        tally_is_debit=_yes_no(_tag_text(block, "TALLYISDEBIT")),
        opening_is_debit=_yes_no(_tag_text(block, "TALLYOPENISDEBIT")),
        tally_is_negative=_yes_no(_tag_text(block, "TALLYISNEGATIVE")),
        deemed_positive=_yes_no(_tag_text(block, "ISDEEMEDPOSITIVE")),
        parent=_tag_text(block, "PARENT"),
    )


def interpret_closing_balance(
    raw: str,
    *,
    tally_is_debit: bool | None = None,
    opening_is_debit: bool | None = None,
    tally_is_negative: bool | None = None,
    deemed_positive: bool | None = None,
    parent: str | None = None,
) -> dict[str, str | float | bool | None]:
    """Map Tally XML ClosingBalance to ERP Dr/Cr using Tally indicators.

    Never uses a hardcoded "positive = Cr / negative = Dr" rule.
    Dr/Cr comes from $$IsDebit, $$IsNegative, IsDeemedPositive, and ledger parent.
    """
    raw = (
        (raw or "")
        .replace("\u2212", "-")
        .replace("\u2013", "-")
        .replace("\u2014", "-")
        .strip()
    )
    lowered = raw.lower()
    label_credit = bool(re.search(r"\bcr\b|\bcredit\b", lowered)) or bool(
        re.search(r"\(-\)\s*$", raw)
    )
    label_debit = bool(re.search(r"\bdr\b|\bdebit\b", lowered))
    numeric = re.sub(r"[₹,\s]", "", raw)
    number = re.search(r"-?\d+(?:\.\d+)?", numeric)
    value = float(number.group(0)) if number is not None else 0.0
    nature = _ledger_nature(deemed_positive, parent)
    is_negative = tally_is_negative
    if is_negative is None and number is not None:
        is_negative = value < 0

    if label_debit and not label_credit:
        balance_type = "debit"
    elif label_credit and not label_debit:
        balance_type = "credit"
    elif tally_is_debit is True or opening_is_debit is True:
        balance_type = "debit"
    elif nature == "debit" and is_negative is True:
        # Unnatural side for assets/debtors = Credit (advance).
        balance_type = "credit"
    elif nature == "credit" and is_negative is True:
        # Unnatural side for liabilities/creditors/deposits = Debit.
        balance_type = "debit"
    elif nature == "credit":
        balance_type = "credit"
    else:
        # Sundry Debtors / unknown party ledgers: a positive opening-only
        # amount is still Dr. $$IsDebit=No is not trusted as Cr here because
        # Collection XML often drops the debit flag on opening balances.
        balance_type = "debit"

    return {
        "closing_balance_raw": raw,
        "closing_balance_numeric": value,
        "closing_balance": abs(value),
        "closing_balance_type": balance_type,
        "tally_is_debit": tally_is_debit,
        "opening_is_debit": opening_is_debit,
        "tally_is_negative": tally_is_negative,
        "deemed_positive": deemed_positive,
        "ledger_parent": (parent or "").strip() or None,
        "is_closing_debit": balance_type == "debit",
    }


def _ledger_nature(deemed_positive: bool | None, parent: str | None) -> str | None:
    if deemed_positive is True:
        return "debit"
    if deemed_positive is False:
        return "credit"
    text = re.sub(r"\s+", " ", (parent or "").strip().lower())
    if text == "":
        return None
    credit_names = (
        "sundry creditor",
        "sundry creditors",
        "current liabilit",
        "deposits",
        "deposit (liability)",
        "bank od",
        "duties & tax",
        "duties and tax",
    )
    debit_names = (
        "sundry debtor",
        "sundry debtors",
        "current asset",
        "cash-in-hand",
        "bank accounts",
        "direct expenses",
        "indirect expenses",
    )
    if any(name in text for name in credit_names):
        return "credit"
    if any(name in text for name in debit_names):
        return "debit"
    return None


def _closing_balance_from_text(raw: str) -> tuple[float, str]:
    parsed = interpret_closing_balance(raw)
    return float(parsed["closing_balance"] or 0), str(parsed["closing_balance_type"])


def _tag_text(block: str, tag: str) -> str:
    match = re.search(
        rf"<{tag}[^>]*>(.*?)</{tag}>",
        block,
        flags=re.IGNORECASE | re.DOTALL,
    )
    if match is None:
        return ""
    return re.sub(r"<[^>]+>", "", match.group(1)).strip()


def _yes_no(value: str) -> bool | None:
    lowered = value.strip().lower()
    if lowered in {"yes", "true", "1", "y"}:
        return True
    if lowered in {"no", "false", "0", "n"}:
        return False
    return None


def _int_tag(xml: str, tag: str) -> int:
    match = re.search(rf"<{tag}[^>]*>(.*?)</{tag}>", xml, flags=re.IGNORECASE | re.DOTALL)
    if match is None:
        return 0
    try:
        return int(re.sub(r"\D", "", match.group(1)) or "0")
    except ValueError:
        return 0


def _text_tag(xml: str, tag: str) -> str:
    match = re.search(rf"<{tag}[^>]*>(.*?)</{tag}>", xml, flags=re.IGNORECASE | re.DOTALL)
    if match is None:
        return ""
    return re.sub(r"<[^>]+>", "", match.group(1)).strip()
