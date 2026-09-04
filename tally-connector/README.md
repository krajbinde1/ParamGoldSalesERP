# ParamGold Tally Prime Connector

Windows agent that polls ParamGold ERP and posts pending Sales / Receipt vouchers into **Tally Prime** on this PC (`http://localhost:9000`).

ERP never talks to Tally. This program must keep running on the office PC while Tally Prime is open.

## Before you start

1. Tally Prime is open with the correct company loaded.
2. In Tally: **F12 (Configure) → Advanced Configuration**  
   - TallyPrime is acting as **HTTP Server**  
   - Port **9000**
3. These Tally ledgers already exist (the connector will **not** create dealer ledgers):
   - Party ledgers for dealers that are mapped in ERP
   - Sales, GST, Round Off, Cash, Bank (names must match `.env`)
4. ERP Phase 1 is live (`/api/tally-connector/...`).

## One-time setup

On the office PC, in this folder:

```bat
copy .env.example .env
```

Edit `.env` (see values below). Then issue a connector token **on the ERP server**:

```bash
cd backend
php artisan tally-connector:issue-token --user-id=YOUR_ADMIN_USER_ID
```

Paste the printed token into `ERP_CONNECTOR_TOKEN`. Never commit `.env`.

Install Python 3.10+ from [python.org](https://www.python.org/downloads/) and tick **Add python.exe to PATH**.

## Run (keeps running)

Double-click `run_connector.bat`, or:

```bat
cd C:\Projects\ParamGoldSalesERP\tally-connector
python -m pip install -r requirements.txt
python connector.py
```

One poll then exit (for a quick test):

```bat
python connector.py --once
```

Press **Ctrl+C** to stop the continuous run.

## Console logs

| Log | Meaning |
|---|---|
| Connected | ERP API and Tally HTTP server both responded |
| Pending | How many vouchers ERP has ready this poll |
| Syncing | Connector claimed a voucher and is posting XML to Tally |
| Synced | Tally accepted it; ERP outbox marked synced |
| Failed | Mapping/XML/Tally/ERP error (Tally rejection is sent back to ERP) |

## How vouchers are posted

- **Sales** from a billed ERP order: party **Dr** `grand_total`, Sales **Cr**, GST **Cr**, Round Off if needed.
- **Receipt** from a received collection: Cash/Bank **Dr**, party **Cr**.
- Party ledger name is the **exact** ERP mapping (`tally_dealer_mappings`). If it is missing, the voucher is failed. No ledger is created or guessed.
- `REMOTEID` = ERP unique reference (`ERP-SO-{order_id}` / `ERP-COL-{collection_id}`) so Tally will not create a second voucher.

## Live closing balances

On every poll the connector also exports current ledger **ClosingBalance** values from Tally and posts them to ERP. ERP matches Tally ledger names to dealers (`tally_dealer_mappings`, then unique firm name) and stores the snapshot only. It does **not** change dealer ledger transactions.

If Tally is closed, the connector reports offline so Dealer Ledger shows **Tally Offline / Last synced at …** instead of a false mismatch.

Admin **Sync Live Tally Now** asks the connector to push balances on the next poll. ERP never calls Tally from the browser.

Keep this program running with Tally Prime open for live match status.
