import json
import os
import time
from datetime import datetime, timedelta, timezone
from hashlib import sha256
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Lock
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen
from zoneinfo import ZoneInfo

GRAPHQL_QUERY = """
query($organization: String!, $number: Int!, $after: String) {
  organization(login: $organization) {
    projectV2(number: $number) {
      title
      url
      items(first: 100, after: $after) {
        totalCount
        pageInfo {
          hasNextPage
          endCursor
        }
        nodes {
          id
          isArchived
          content {
            __typename
            ... on Issue {
              title
              number
              url
              repository { name }
              assignees(first: 20) { nodes { login } }
            }
            ... on PullRequest {
              title
              number
              url
              repository { name }
              assignees(first: 20) { nodes { login } }
            }
            ... on DraftIssue {
              title
              assignees(first: 20) { nodes { login } }
            }
          }
          status: fieldValueByName(name: "Status") {
            ... on ProjectV2ItemFieldSingleSelectValue {
              name
              updatedAt
            }
          }
        }
      }
    }
  }
}
"""

STATUS_ORDER = ("In progress", "In review", "Ready", "Backlog")
DONE_STATUSES = {"done", "completed"}
CACHE_SECONDS = 60
MAX_PROJECT_ITEMS = 1000
MAX_DISPLAY_ITEMS = 10
RESERVED_DONE_ITEMS = 4
INDICATOR_HOURS = 2
STATE_PATH = Path("/app/state/tasks.json")
cache_lock = Lock()
cached_feed = None
cached_at = 0.0


class FeedError(Exception):
    pass


def config_value(name):
    value = os.environ.get(name)
    if not value:
        raise FeedError(f"{name} is required")
    return value


def github_token():
    token_file = Path(config_value("GITHUB_TOKEN_FILE"))
    if not token_file.is_file():
        raise FeedError("GitHub token file is missing")

    for line in token_file.read_text().splitlines():
        if line.startswith("GITHUB_TOKEN="):
            token = line.partition("=")[2].strip()
            if token:
                return token

    raise FeedError("GITHUB_TOKEN is missing")


def graphql_page(after):
    body = json.dumps(
        {
            "query": GRAPHQL_QUERY,
            "variables": {
                "organization": config_value("PROJECT_ORGANIZATION"),
                "number": int(config_value("PROJECT_NUMBER")),
                "after": after,
            },
        }
    ).encode()
    request = Request(
        "https://api.github.com/graphql",
        data=body,
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {github_token()}",
            "Content-Type": "application/json",
            "User-Agent": "larapaper-github-project",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    try:
        with urlopen(request, timeout=20) as response:
            payload = json.load(response)
    except HTTPError as error:
        raise FeedError(f"GitHub returned HTTP {error.code}") from error
    except URLError as error:
        raise FeedError("Could not reach GitHub") from error

    if payload.get("errors"):
        raise FeedError(str(payload["errors"][0].get("message", "GitHub query failed")))

    project = (payload.get("data") or {}).get("organization") or {}
    project = project.get("projectV2")
    if project is None:
        raise FeedError("Project was not found or the token cannot read it")

    return project


def project_items():
    items = []
    after = None
    project_title = None
    project_url = None

    while True:
        project = graphql_page(after)
        project_title = project["title"]
        project_url = project["url"]
        connection = project["items"]
        if connection["totalCount"] > MAX_PROJECT_ITEMS:
            raise FeedError(f"Project has more than {MAX_PROJECT_ITEMS} items")

        items.extend(connection["nodes"])
        page_info = connection["pageInfo"]
        if not page_info["hasNextPage"]:
            return project_title, project_url, items
        after = page_info["endCursor"]
        if not after:
            raise FeedError("GitHub returned a page without a cursor")


def parse_timestamp(value):
    if not value:
        return None
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def mark_changes(tasks, now):
    previous = None
    if STATE_PATH.exists():
        previous = json.loads(STATE_PATH.read_text())

    current = {}
    for task in tasks:
        task_id = task["id"]
        signature = sha256(
            json.dumps([task["title"], task["status"]], ensure_ascii=False).encode()
        ).hexdigest()
        old = (previous or {}).get(task_id)
        indicator = None
        marked_at = None
        if previous is not None:
            if old is None:
                indicator = "NEW"
            elif old["signature"] != signature:
                indicator = "UPDATED"
            elif old.get("indicator"):
                changed_at = parse_timestamp(old.get("marked_at"))
                if changed_at and now - changed_at < timedelta(hours=INDICATOR_HOURS):
                    indicator = old["indicator"]
                    marked_at = old["marked_at"]

        if indicator and marked_at is None:
            marked_at = now.isoformat()
        task["change_indicator"] = (
            indicator if task["status"].casefold() not in DONE_STATUSES else None
        )
        current[task_id] = {
            "signature": signature,
            "indicator": indicator,
            "marked_at": marked_at,
        }

    STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
    temporary = STATE_PATH.with_suffix(".tmp")
    temporary.write_text(json.dumps(current, ensure_ascii=False))
    temporary.chmod(0o600)
    os.replace(temporary, STATE_PATH)


def normalize_item(item, assignee):
    if item["isArchived"]:
        return None

    content = item["content"]
    if content is None:
        raise FeedError("Some Project items are inaccessible to the GitHub token")

    logins = [
        node["login"].casefold()
        for node in (content.get("assignees") or {}).get("nodes", [])
    ]
    if assignee.casefold() not in logins:
        return None

    status_value = item.get("status") or {}
    status = status_value.get("name") or "No status"
    repository = content.get("repository") or {}
    number = content.get("number")
    reference = repository.get("name") or "Draft"
    if number is not None:
        reference = f"{reference} #{number}"

    return {
        "id": item["id"],
        "title": content.get("title") or "Untitled item",
        "url": content.get("url") or "",
        "reference": reference,
        "status": status,
        "status_changed_at": status_value.get("updatedAt"),
    }


def build_feed(now=None):
    now = now or datetime.now(timezone.utc)
    assignee = config_value("PROJECT_ASSIGNEE")
    project_title, project_url, raw_items = project_items()
    tasks = []
    for raw_item in raw_items:
        task = normalize_item(raw_item, assignee)
        if task is not None:
            tasks.append(task)
    mark_changes(tasks, now)

    active = [task for task in tasks if task["status"].casefold() not in DONE_STATUSES]
    done = [task for task in tasks if task["status"].casefold() in DONE_STATUSES]
    recent_cutoff = now - timedelta(hours=24)
    recent_done = [
        task
        for task in done
        if (changed := parse_timestamp(task["status_changed_at"]))
        and changed >= recent_cutoff
    ]
    recent_done.sort(key=lambda task: task["status_changed_at"], reverse=True)

    sections = []
    for status in sorted(
        {task["status"] for task in active},
        key=lambda name: (
            STATUS_ORDER.index(name) if name in STATUS_ORDER else len(STATUS_ORDER),
            name,
        ),
    ):
        section_tasks = sorted(
            [task for task in active if task["status"] == status],
            key=lambda task: 0 if task["change_indicator"] else 1,
        )
        sections.append(
            {"name": status, "count": len(section_tasks), "tasks": section_tasks}
        )

    for task in recent_done:
        completed = parse_timestamp(task["status_changed_at"]).astimezone(
            ZoneInfo(config_value("DISPLAY_TIME_ZONE"))
        )
        task["completed_time"] = f"{completed:%b} {completed.day}, {completed:%H:%M}"

    done_slots = min(len(recent_done), RESERVED_DONE_ITEMS)
    open_slots = min(len(active), MAX_DISPLAY_ITEMS - done_slots)
    done_slots = min(len(recent_done), MAX_DISPLAY_ITEMS - open_slots)
    marked_first = sorted(active, key=lambda task: 0 if task["change_indicator"] else 1)
    visible_ids = {task["id"] for task in marked_first[:open_slots]}
    display_sections = []
    for section in sections:
        visible_tasks = [task for task in section["tasks"] if task["id"] in visible_ids]
        if visible_tasks:
            display_sections.append({**section, "tasks": visible_tasks})

    return {
        "project": {"title": project_title, "url": project_url},
        "assignee": assignee,
        "sections": display_sections,
        "recent_done": recent_done[:done_slots],
        "stats": {
            "open": len(active),
            "done_last_24h": len(recent_done),
            "done_total": len(done),
            "hidden_open": len(active) - open_slots,
            "hidden_done": len(recent_done) - done_slots,
        },
        "generated_at": now.isoformat(),
    }


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/health":
            self.respond({"ok": True})
            return
        if self.path != "/tasks":
            self.respond({"error": "Not found"}, 404)
            return

        global cached_feed, cached_at
        try:
            with cache_lock:
                if cached_feed is None or time.monotonic() - cached_at > CACHE_SECONDS:
                    cached_feed = build_feed()
                    cached_at = time.monotonic()
                feed = cached_feed
            self.respond(feed)
        except FeedError as error:
            self.respond({"error": str(error)}, 502)

    def respond(self, payload, status=200):
        body = json.dumps(payload, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
