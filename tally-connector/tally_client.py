from __future__ import annotations

import re
from dataclasses import dataclass

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
            "<NATIVEMETHOD>Parent</NATIVEMETHOD>"
            "<NATIVEMETHOD>ClosingBalance</NATIVEMETHOD>"
            "<NATIVEMETHOD>IsDeemedPositive</NATIVEMETHOD>"
            "<COMPUTE>TALLYISDEBIT:$$IsDebit:$ClosingBalance</COMPUTE>"
            "</COLLECTION>"
            "</TDLMESSAGE></TDL>"
            "</DESC></BODY></ENVELOPE>"
        )
        raw = self._post_xml(xml, self.timeout)
        return parse_ledger_closing_balances(raw)

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
    for match in re.finditer(
        r"<(LEDGER(?:\.LIST)?)([^>]*)>(.*?)</\1>",
        xml,
        flags=re.IGNORECASE | re.DOTALL,
    ):
        attrs = match.group(2)
        block = match.group(3)
        name = _ledger_name(block) or _attr_name(attrs)
        parsed = _closing_balance(block)
        if name == "" or parsed is None:
            continue
        parsed["tally_ledger_name"] = name
        balances.append(parsed)
    return balances


def _decode_tally_xml(content: bytes, fallback: str) -> str:
    if not content:
        return fallback or ""
    for encoding in ("utf-8-sig", "utf-16", "utf-16le"):
        try:
            return content.decode(encoding)
        except UnicodeDecodeError:
            continue
    return fallback or content.decode("utf-8", errors="ignore")


def _attr_name(attrs: str) -> str:
    match = re.search(r'\bNAME="([^"]+)"', attrs, flags=re.IGNORECASE)
    return re.sub(r"\s+", " ", match.group(1)).strip() if match else ""


def _ledger_name(block: str) -> str:
    match = re.search(
        r"<NAME(?:\.LIST)?[^>]*>\s*(?:<NAME[^>]*>)?\s*([^<]+)",
        block,
        flags=re.IGNORECASE,
    )
    return re.sub(r"\s+", " ", match.group(1)).strip() if match else ""


def _closing_balance(block: str) -> dict[str, str | float | bool | None] | None:
    match = re.search(
        r"<CLOSINGBALANCE([^>]*)>(.*?)</CLOSINGBALANCE>",
        block,
        flags=re.IGNORECASE | re.DOTALL,
    )
    if match is None:
        return None
    raw = re.sub(r"<[^>]+>", "", match.group(2)).strip()
    attr_raw = f"{match.group(1)} {raw}".strip()
    return interpret_closing_balance(
        raw or attr_raw,
        tally_is_debit=_yes_no(_tag_text(block, "TALLYISDEBIT")),
        deemed_positive=_yes_no(_tag_text(block, "ISDEEMEDPOSITIVE")),
    )


def interpret_closing_balance(
    raw: str,
    *,
    tally_is_debit: bool | None = None,
    deemed_positive: bool | None = None,
) -> dict[str, str | float | bool | None]:
    """Map Tally XML ClosingBalance to ERP Dr/Cr at the XML source.

    Priority:
    1. Tally $$IsDebit:$ClosingBalance (Yes = Dr, No = Cr)
    2. Explicit Dr/Cr text on the amount
    3. XML minus sign = Dr (same as voucher AMOUNT / ISDEEMEDPOSITIVE Yes)
    4. Positive amount + IsDeemedPositive=No (liability) = Cr
    5. Positive amount otherwise = Dr (Collection often omits the minus for debtors)
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

    if tally_is_debit is True:
        balance_type = "debit"
    elif tally_is_debit is False:
        balance_type = "credit"
    elif label_debit and not label_credit:
        balance_type = "debit"
    elif label_credit and not label_debit:
        balance_type = "credit"
    elif value < 0:
        balance_type = "debit"
    elif value > 0 and deemed_positive is False:
        balance_type = "credit"
    else:
        balance_type = "debit"

    return {
        "closing_balance_raw": raw,
        "closing_balance_numeric": value,
        "closing_balance": abs(value),
        "closing_balance_type": balance_type,
        "tally_is_debit": tally_is_debit,
        "deemed_positive": deemed_positive,
        "is_closing_debit": balance_type == "debit",
    }


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
