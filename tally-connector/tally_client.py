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
        try:
            response = self.session.post(
                self.url,
                data=xml.encode("utf-8"),
                headers={"Content-Type": "application/xml"},
                timeout=min(self.timeout, 15),
            )
        except requests.RequestException as exc:
            raise TallyError(
                f"Tally Prime is not reachable at {self.url}. Open Tally and enable the HTTP server. ({exc})"
            ) from exc

        if response.status_code >= 400:
            raise TallyError(
                f"Tally Prime HTTP {response.status_code} at {self.url}: {response.text[:500]}"
            )

    def import_voucher(self, xml: str) -> TallyResult:
        try:
            response = self.session.post(
                self.url,
                data=xml.encode("utf-8"),
                headers={"Content-Type": "application/xml"},
                timeout=self.timeout,
            )
        except requests.RequestException as exc:
            raise TallyError(
                f"Tally Prime is not reachable at {self.url}. Open Tally and enable the HTTP server. ({exc})"
            ) from exc

        if response.status_code >= 400:
            raise TallyError(f"Tally HTTP {response.status_code}: {response.text[:1500]}")

        raw = response.text or ""
        return TallyResult(
            created=_int_tag(raw, "CREATED"),
            altered=_int_tag(raw, "ALTERED"),
            errors=_int_tag(raw, "ERRORS"),
            exceptions=_int_tag(raw, "EXCEPTIONS"),
            last_vch_id=_text_tag(raw, "LASTVCHID"),
            raw=raw,
        )


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
