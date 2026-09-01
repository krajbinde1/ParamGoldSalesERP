from __future__ import annotations

import argparse
import sys
import time
from typing import Any

from config import Settings, load_settings
from erp_client import ErpApiError, ErpClient
from logutil import log
from tally_client import TallyClient, TallyError
from vouchers import VoucherBuildError, build_voucher_xml


def main() -> int:
    parser = argparse.ArgumentParser(description="ParamGold ERP → Tally Prime connector")
    parser.add_argument(
        "--once",
        action="store_true",
        help="Poll ERP once, process pending vouchers, then exit",
    )
    args = parser.parse_args()

    try:
        settings = load_settings()
    except RuntimeError as exc:
        log("Failed", str(exc))
        return 1

    erp = ErpClient(settings.erp_base_url, settings.erp_token, settings.connector_id)
    tally = TallyClient(settings.tally_url, settings.tally_company)

    try:
        erp.pending(1)
    except ErpApiError as exc:
        log("Failed", f"ERP connection failed: {exc}")
        return 1

    log(
        "Connected",
        f"ERP={settings.erp_base_url}  Connector={settings.connector_id}",
    )
    try:
        tally.ping()
        log(
            "Connected",
            f"Tally={settings.tally_url}  Company={settings.tally_company or '(currently open)'}",
        )
    except TallyError as exc:
        log("Failed", str(exc))

    try:
        if args.once:
            process_pending(erp, tally, settings)
            return 0

        while True:
            process_pending(erp, tally, settings)
            time.sleep(settings.poll_interval_seconds)
    except KeyboardInterrupt:
        print("Connector stopped.", flush=True)
        return 0


def process_pending(erp: ErpClient, tally: TallyClient, settings: Settings) -> None:
    try:
        tally.ping()
    except TallyError as exc:
        log("Failed", str(exc))
        return

    try:
        pending = erp.pending(settings.pending_limit)
    except ErpApiError as exc:
        log("Failed", f"Could not fetch pending vouchers: {exc}")
        return

    log("Pending", f"{len(pending)} voucher(s)")
    for voucher in pending:
        process_voucher(erp, tally, settings, voucher)


def process_voucher(
    erp: ErpClient,
    tally: TallyClient,
    settings: Settings,
    voucher: dict[str, Any],
) -> None:
    voucher_id = int(voucher.get("id") or 0)
    reference = str(voucher.get("erp_reference") or f"#{voucher_id}")
    voucher_type = str(voucher.get("voucher_type") or "")

    if voucher_id <= 0:
        log("Failed", f"{reference}  Missing voucher id from ERP")
        return

    log("Syncing", f"{reference}  {voucher_type}")

    try:
        claimed = erp.claim(voucher_id)
        claimed_payload = claimed.get("data") if isinstance(claimed.get("data"), dict) else voucher
        xml = build_voucher_xml(claimed_payload, settings)
        result = tally.import_voucher(xml)
        if not result.succeeded:
            raise TallyError(result.error_message())

        display_no = _display_voucher_no(claimed_payload, result.last_vch_id)
        erp.synced(voucher_id, display_no, result.last_vch_id or None)
        log("Synced", f"{reference}  Tally voucher={display_no or result.last_vch_id or 'created'}")
    except VoucherBuildError as exc:
        _mark_failed(erp, voucher_id, reference, str(exc))
    except TallyError as exc:
        _mark_failed(erp, voucher_id, reference, str(exc))
    except ErpApiError as exc:
        log("Failed", f"{reference}  ERP: {exc}")


def _mark_failed(erp: ErpClient, voucher_id: int, reference: str, error: str) -> None:
    log("Failed", f"{reference}  {error}")
    try:
        erp.failed(voucher_id, error)
    except ErpApiError as exc:
        log("Failed", f"{reference}  Could not report failure to ERP: {exc}")


def _display_voucher_no(voucher: dict[str, Any], last_vch_id: str) -> str:
    payload = voucher.get("payload") if isinstance(voucher.get("payload"), dict) else {}
    order = payload.get("order") if isinstance(payload.get("order"), dict) else {}
    collection = payload.get("collection") if isinstance(payload.get("collection"), dict) else {}
    return str(
        order.get("bill_number")
        or order.get("order_no")
        or collection.get("receipt_no")
        or voucher.get("erp_reference")
        or last_vch_id
        or ""
    )


if __name__ == "__main__":
    sys.exit(main())
