from __future__ import annotations

import contextlib
import json
import os
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")


def read_payload() -> dict:
    if len(sys.argv) > 1:
        raw = Path(sys.argv[1]).read_text(encoding="utf-8-sig")
    elif os.environ.get("LEGACY_PLAN_PAYLOAD"):
        raw = os.environ["LEGACY_PLAN_PAYLOAD"]
    else:
        raw = sys.stdin.read()

    raw = (raw or "{}").lstrip("\ufeff").strip()
    return json.loads(raw or "{}")


def main() -> int:
    payload = read_payload()
    project_path = Path(payload["project_path"]).resolve()
    if not project_path.exists():
        raise RuntimeError(f"Legacy project path does not exist: {project_path}")

    for key, value in (payload.get("env") or {}).items():
        if value:
            os.environ[str(key)] = str(value)

    sys.path.insert(0, str(project_path))
    os.chdir(project_path)

    from agents.root_agent import RootTravelPlannerAgent
    from services.image_service import build_image_context
    from services.request_parser import parse_user_request

    user_text = payload.get("user_text") or ""
    departure_date = payload.get("departure_date") or ""
    if departure_date:
        user_text = f"{user_text} Departure {departure_date}".strip()

    with contextlib.redirect_stdout(sys.stderr):
        parsed = parse_user_request(user_text, origin=payload.get("origin") or None)
        if payload.get("lang"):
            parsed.lang = payload["lang"]
        result = RootTravelPlannerAgent().run(parsed)
        images = build_image_context(result)

    print(json.dumps(
        {
            "parsed_request": parsed.model_dump(),
            "travel_plan": result.model_dump(),
            "images": images,
        },
        ensure_ascii=False,
    ))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
