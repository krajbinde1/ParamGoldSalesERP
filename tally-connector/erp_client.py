from __future__ import annotations

from typing import Any

import requests


class ErpApiError(Exception):
    def __init__(self, message: str, status_code: int | None = None) -> None:
        super().__init__(message)
        self.status_code = status_code


class ErpClient:
    def __init__(self, base_url: str, token: str, connector_id: str, timeout: int = 30) -> None:
        self.base_url = base_url.rstrip("/")
        self.connector_id = connector_id
        self.timeout = timeout
        self.session = requests.Session()
        self.session.headers.update(
            {
                "Authorization": f"Bearer {token}",
                "Accept": "application/json",
                "X-Tally-Connector-Id": connector_id,
            }
        )

    def _url(self, path: str) -> str:
        return f"{self.base_url}/api/tally-connector/{path.lstrip('/')}"

    def _json(self, response: requests.Response) -> dict[str, Any]:
        try:
            payload = response.json()
        except ValueError:
            return {}
        return payload if isinstance(payload, dict) else {"data": payload}

    def heartbeat(self) -> dict[str, Any]:
        try:
            response = self.session.get(self._url("heartbeat"), timeout=self.timeout)
        except requests.RequestException as exc:
            raise ErpApiError(f"ERP heartbeat request failed: {exc}") from exc

        if response.status_code != 200:
            raise ErpApiError(self._error_message(response), response.status_code)

        return self._json(response)

    def pending(self, limit: int) -> list[dict[str, Any]]:
        try:
            response = self.session.get(
                self._url("pending"),
                params={"limit": limit},
                timeout=self.timeout,
            )
        except requests.RequestException as exc:
            raise ErpApiError(f"ERP pending request failed: {exc}") from exc

        if response.status_code != 200:
            raise ErpApiError(self._error_message(response), response.status_code)

        data = self._json(response).get("data", [])
        return data if isinstance(data, list) else []

    def claim(self, voucher_id: int) -> dict[str, Any]:
        return self._post(f"vouchers/{voucher_id}/claim", {"connector_id": self.connector_id})

    def synced(
        self,
        voucher_id: int,
        tally_voucher_no: str | None,
        tally_master_id: str | None,
    ) -> dict[str, Any]:
        body: dict[str, str] = {}
        if tally_voucher_no:
            body["tally_voucher_no"] = tally_voucher_no[:100]
        if tally_master_id:
            body["tally_master_id"] = tally_master_id[:100]
        return self._post(f"vouchers/{voucher_id}/synced", body)

    def failed(self, voucher_id: int, error: str) -> dict[str, Any]:
        message = error.strip() or "Tally sync failed."
        if len(message) < 3:
            message = f"{message} (see Tally response)"
        return self._post(f"vouchers/{voucher_id}/failed", {"error": message[:2000]})

    def live_balance_poll(self) -> dict[str, Any]:
        try:
            response = self.session.get(self._url("live-balances"), timeout=self.timeout)
        except requests.RequestException as exc:
            raise ErpApiError(f"ERP live-balance poll failed: {exc}") from exc

        if response.status_code != 200:
            raise ErpApiError(self._error_message(response), response.status_code)

        return self._json(response)

    def post_live_balances(self, tally_online: bool, balances: list[dict[str, Any]]) -> dict[str, Any]:
        return self._post(
            "live-balances",
            {
                "connector_id": self.connector_id,
                "tally_online": tally_online,
                "balances": balances,
            },
            timeout=max(self.timeout, 120),
        )

    def post_journal_vouchers(
        self,
        tally_online: bool,
        entries: list[dict[str, Any]],
        *,
        sync_complete: bool,
        seen_voucher_guids: list[str],
    ) -> dict[str, Any]:
        return self._post(
            "journal-vouchers",
            {
                "connector_id": self.connector_id,
                "tally_online": tally_online,
                "sync_complete": sync_complete,
                "seen_voucher_guids": seen_voucher_guids,
                "entries": entries,
            },
            timeout=max(self.timeout, 120),
        )

    def _post(self, path: str, body: dict[str, Any], timeout: int | None = None) -> dict[str, Any]:
        try:
            response = self.session.post(
                self._url(path),
                json=body,
                timeout=timeout or self.timeout,
            )
        except requests.RequestException as exc:
            raise ErpApiError(f"ERP request failed: {exc}") from exc

        if response.status_code >= 400:
            raise ErpApiError(self._error_message(response), response.status_code)

        return self._json(response)

    def _error_message(self, response: requests.Response) -> str:
        url = response.url or self._url("")
        payload = self._json(response) if response.content else {}
        errors = payload.get("errors")
        details: list[str] = []
        if isinstance(errors, dict):
            for value in errors.values():
                if isinstance(value, list):
                    details.extend(str(item) for item in value)
                else:
                    details.append(str(value))
        message = payload.get("message")
        if isinstance(message, str) and message.strip():
            details.append(message.strip())
        if not details:
            text = (response.text or "").strip()
            if text:
                details.append(text[:800])
        body = " ".join(details) if details else "no response body"
        return f"HTTP {response.status_code} {url} — {body}"
