from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

from dotenv import load_dotenv


CONNECTOR_DIR = Path(__file__).resolve().parent


def _required(name: str) -> str:
    value = os.getenv(name, "").strip()
    if value == "":
        raise RuntimeError(f"Missing {name} in tally-connector/.env")
    return value


def _optional(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


@dataclass(frozen=True)
class Settings:
    erp_base_url: str
    erp_token: str
    connector_id: str
    poll_interval_seconds: int
    pending_limit: int
    tally_url: str
    tally_company: str
    voucher_type_sales: str
    voucher_type_receipt: str
    sales_ledger: str
    gst_ledger: str
    round_off_ledger: str
    cash_ledger: str
    bank_ledger: str


def load_settings() -> Settings:
    load_dotenv(CONNECTOR_DIR / ".env")

    interval = int(_optional("POLL_INTERVAL_SECONDS", "30") or "30")
    limit = int(_optional("PENDING_LIMIT", "10") or "10")
    connector_id = _optional("CONNECTOR_ID") or os.environ.get("COMPUTERNAME", "office-pc")

    return Settings(
        erp_base_url=_required("ERP_BASE_URL").rstrip("/"),
        erp_token=_required("ERP_CONNECTOR_TOKEN"),
        connector_id=connector_id[:100],
        poll_interval_seconds=max(5, interval),
        pending_limit=max(1, min(limit, 50)),
        tally_url=_optional("TALLY_URL", "http://localhost:9000").rstrip("/"),
        tally_company=_optional("TALLY_COMPANY"),
        voucher_type_sales=_optional("TALLY_VOUCHER_TYPE_SALES", "Sales") or "Sales",
        voucher_type_receipt=_optional("TALLY_VOUCHER_TYPE_RECEIPT", "Receipt") or "Receipt",
        sales_ledger=_optional("TALLY_SALES_LEDGER", "Sales") or "Sales",
        gst_ledger=_optional("TALLY_GST_LEDGER", "GST") or "GST",
        round_off_ledger=_optional("TALLY_ROUND_OFF_LEDGER", "Round Off") or "Round Off",
        cash_ledger=_optional("TALLY_CASH_LEDGER", "Cash") or "Cash",
        bank_ledger=_optional("TALLY_BANK_LEDGER", "Bank") or "Bank",
    )
