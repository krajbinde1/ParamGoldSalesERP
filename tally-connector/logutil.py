from __future__ import annotations

from datetime import datetime


def log(event: str, message: str = "") -> None:
    stamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    extra = f"  {message}" if message else ""
    print(f"[{stamp}] {event:<9}{extra}", flush=True)
