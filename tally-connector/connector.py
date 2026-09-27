from __future__ import annotations

import argparse
import sys
import time
from typing import Any

from config import Settings, load_settings
from erp_client import ErpApiError, ErpClient
from logutil import log
from tally_client import TallyClient, TallyError
from vouchers import VoucherBuildError, build_voucher_xml, receipt_debit_ledger


def main() -> int:
    parser = argparse.ArgumentParser(description="ParamGold ERP → Tally Prime connector")
    parser.add_argument(
        "--once",
        action="store_true",
        help="Poll ERP once, process pending vouchers, then exit",
    )
    parser.add_argument(
        "--trace-voucher",
        metavar="TALLY_VOUCHER_NO",
        help="Look up a synced ERP receipt by Tally voucher number and print XML without posting",
    )
    args = parser.parse_args()

    try:
        settings = load_settings()
    except RuntimeError as exc:
        log("Failed", str(exc))
        return 1

    erp = ErpClient(settings.erp_base_url, settings.erp_token, settings.connector_id)

    try:
        erp.heartbeat(settings.tally_company)
    except ErpApiError as exc:
        if exc.status_code == 404:
            try:
                erp.pending(1)
            except ErpApiError as pending_exc:
                log("Failed", f"ERP connection failed: {pending_exc}")
                return 1
        else:
            log("Failed", f"ERP connection failed: {exc}")
            return 1

    log(
        "Connected",
        f"ERP={settings.erp_base_url}  Connector={settings.connector_id}",
    )

    try:
        if args.trace_voucher:
            return trace_receipt_xml(erp, settings, str(args.trace_voucher))

        tally = TallyClient(settings.tally_url, settings.tally_company)
        try:
            tally.ping()
            log(
                "Connected",
                f"Tally={settings.tally_url}  Company={settings.tally_company or '(currently open)'}",
            )
        except TallyError as exc:
            log("Failed", str(exc))

        if args.once:
            send_heartbeat(erp, settings)
            process_pending(erp, tally, settings)
            sync_live_balances(erp, tally)
            sync_journal_vouchers(erp, tally)
            return 0

        while True:
            send_heartbeat(erp, settings)
            process_pending(erp, tally, settings)
            sync_live_balances(erp, tally)
            sync_journal_vouchers(erp, tally)
            time.sleep(settings.poll_interval_seconds)
    except KeyboardInterrupt:
        print("Connector stopped.", flush=True)
        return 0


def trace_receipt_xml(erp: ErpClient, settings: Settings, tally_voucher_no: str) -> int:
    try:
        result = erp.lookup_receipt(tally_voucher_no=tally_voucher_no)
    except ErpApiError as exc:
        log("Failed", f"ERP lookup: {exc}")
        return 1

    mapping = str(result.get("receipt_debit_ledger") or settings.receipt_debit_ledger)
    rows = result.get("data") if isinstance(result.get("data"), list) else []
    log("Trace", f"production_mapping={mapping}  matches={len(rows)}")
    if not rows:
        log("Failed", f"No outbox receipt found for Tally voucher {tally_voucher_no}")
        return 1

    for voucher in rows:
        payload = voucher.get("payload") if isinstance(voucher.get("payload"), dict) else {}
        collection = payload.get("collection") if isinstance(payload.get("collection"), dict) else {}
        party = payload.get("party") if isinstance(payload.get("party"), dict) else {}
        log(
            "Trace",
            "outbox_id={id} collection_id={cid} status={status} "
            "erp_ref={ref} stored_debit={debit} credit={credit}".format(
                id=voucher.get("id"),
                cid=collection.get("id"),
                status=voucher.get("status"),
                ref=voucher.get("erp_reference"),
                debit=collection.get("debit_ledger"),
                credit=party.get("tally_ledger_name"),
            ),
        )
        try:
            xml = build_voucher_xml(voucher, settings)
        except VoucherBuildError as exc:
            log("Failed", str(exc))
            return 1
        log("TraceXML", xml)
        log(
            "Trace",
            "xml_party={party}  xml_has_sbi={sbi}  xml_has_cash_ledger={cash}".format(
                party=_xml_tag(xml, "PARTYLEDGERNAME"),
                sbi="State Bank of India" in xml,
                cash="<LEDGERNAME>Cash</LEDGERNAME>" in xml,
            ),
        )

    log("Trace", "XML printed only. No voucher was posted to Tally.")
    return 0


def _xml_tag(xml: str, tag: str) -> str:
    start = xml.find(f"<{tag}>")
    end = xml.find(f"</{tag}>")
    if start < 0 or end < 0:
        return ""
    return xml[start + len(tag) + 2 : end]


def send_heartbeat(erp: ErpClient, settings: Settings) -> None:
    try:
        erp.heartbeat(settings.tally_company)
    except ErpApiError as exc:
        log("Failed", f"ERP heartbeat: {exc}")


def sync_live_balances(erp: ErpClient, tally: TallyClient) -> None:
    force_sync = False
    try:
        poll = erp.live_balance_poll()
        force_sync = bool(poll.get("force_sync"))
    except ErpApiError as exc:
        log("Failed", f"Live Tally poll: {exc}")

    if force_sync:
        log("Pending", "Admin requested live Tally balance sync")

    try:
        tally.ping()
    except TallyError as exc:
        log("Failed", f"Live Tally balances: {exc}")
        try:
            erp.post_live_balances(False, [])
        except ErpApiError as report_exc:
            log("Failed", f"Could not report Tally offline to ERP: {report_exc}")
        return

    try:
        balances = tally.ledger_closing_balances()
        _log_parsed_balances(balances, detailed=force_sync)
        matched = 0
        unmatched = 0
        ambiguous = 0
        chunk_size = 4000
        for index in range(0, max(len(balances), 1), chunk_size):
            chunk = balances[index : index + chunk_size]
            result = erp.post_live_balances(True, chunk)
            data = result.get("data") if isinstance(result.get("data"), dict) else {}
            matched += int(data.get("matched") or 0)
            unmatched += int(data.get("unmatched") or 0)
            ambiguous += int(data.get("ambiguous") or 0)
        log(
            "Synced",
            "Live Tally balances  "
            f"ledgers={len(balances)} matched={matched} unmatched={unmatched} ambiguous={ambiguous}",
        )
    except TallyError as exc:
        log("Failed", f"Live Tally balances: {exc}")
        try:
            erp.post_live_balances(False, [])
        except ErpApiError as report_exc:
            log("Failed", f"Could not report Tally offline to ERP: {report_exc}")
    except ErpApiError as exc:
        log("Failed", f"Could not store live Tally balances: {exc}")


def sync_journal_vouchers(erp: ErpClient, tally: TallyClient) -> None:
    from_date = "2026-04-01"
    try:
        poll = erp.live_balance_poll()
        if str(poll.get("journal_from_date") or "").strip():
            from_date = str(poll.get("journal_from_date")).strip()
    except ErpApiError:
        pass

    try:
        tally.ping()
    except TallyError as exc:
        log("Failed", f"Tally journals: {exc}")
        return

    try:
        entries = tally.journal_vouchers(from_date)
    except TallyError as exc:
        log("Failed", f"Tally journals: {exc}")
        return

    seen: list[str] = []
    seen_set: set[str] = set()
    for row in entries:
        guid = str(row.get("voucher_guid") or "").strip()
        if guid != "" and guid not in seen_set:
            seen_set.add(guid)
            seen.append(guid)

    try:
        result = erp.post_journal_vouchers(
            True,
            entries,
            sync_complete=True,
            seen_voucher_guids=seen,
        )
        data = result.get("data") if isinstance(result.get("data"), dict) else {}
        log(
            "Synced",
            "Tally journals  "
            f"lines={len(entries)} created={data.get('created', 0)} "
            f"updated={data.get('updated', 0)} reversed={data.get('reversed', 0)} "
            f"unmatched={data.get('unmatched', 0)}",
        )
    except ErpApiError as exc:
        log("Failed", f"Could not store Tally journals: {exc}")


def _log_parsed_balances(balances: list[dict[str, Any]], *, detailed: bool) -> None:
    nonzero = [
        row
        for row in balances
        if abs(float(row.get("closing_balance") or 0)) > 0
    ]
    log(
        "TallyXML",
        f"Live closing parse  ledgers={len(balances)} non_zero={len(nonzero)}",
    )
    rows = nonzero if detailed else nonzero[:25]
    for row in rows:
        log(
            "TallyXML",
            "raw={raw}  numeric={numeric}  parsed={parsed}  sent={sent} {name}".format(
                raw=repr(row.get("closing_balance_raw")),
                numeric=row.get("closing_balance_numeric"),
                parsed=row.get("closing_balance_type"),
                sent=f"{row.get('closing_balance')} {row.get('closing_balance_type')}",
                name=row.get("tally_ledger_name"),
            ),
        )
    if not detailed and len(nonzero) > 25:
        log("TallyXML", f"... {len(nonzero) - 25} more non-zero ledgers (full log on Sync Live Tally Now)")


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
        collection = (
            claimed_payload.get("payload", {}).get("collection")
            if isinstance(claimed_payload.get("payload"), dict)
            else {}
        )
        if voucher_type == "Receipt" and isinstance(collection, dict):
            debit = receipt_debit_ledger(collection, settings)
            party = str(
                (claimed_payload.get("payload") or {}).get("party", {}).get("tally_ledger_name") or ""
            )
            log(
                "Syncing",
                f"{reference}  account={debit}  debit={debit}  credit={party}",
            )
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
