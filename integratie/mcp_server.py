"""
Frontend MCP Server — queries the Drupal 10 JSON:API for all platform data.

Covers: sessions, users, registrations, enrollments, wallet balances, companies.

Run standalone:
    python mcp_server.py
or via fastmcp:
    fastmcp run mcp_server.py:mcp --transport streamable-http --port 8006

Environment variables:
    FRONTEND_BASE_URL       Drupal base URL (default: http://localhost:30020)
    DRUPAL_ADMIN_USER       Basic-auth username for protected endpoints (optional)
    DRUPAL_ADMIN_PASS       Basic-auth password for protected endpoints (optional)
"""
import os
from datetime import datetime, timezone
from typing import Annotated, Any

import httpx
from fastmcp import FastMCP
from pydantic import Field

mcp = FastMCP("frontend")

_BASE_URL    = os.getenv("FRONTEND_BASE_URL", "http://localhost:30020")

_DATE_FIELDS = ("field_start_datetime", "field_date", "field_session_date")
_JSONAPI     = f"{_BASE_URL}/jsonapi"
_ADMIN_USER  = os.getenv("DRUPAL_ADMIN_USER", "")
_ADMIN_PASS  = os.getenv("DRUPAL_ADMIN_PASS", "")

_auth = (_ADMIN_USER, _ADMIN_PASS) if _ADMIN_USER else None
_http = httpx.AsyncClient(timeout=15.0, auth=_auth)


# ─────────────────────────────────────────────
#  Helpers
# ─────────────────────────────────────────────

def _err(msg: str, **extra) -> dict:
    return {"error": f"Frontend service unavailable: {msg}", **extra}


def _session_from_node(node: dict) -> dict:
    attr = node.get("attributes", {})
    rel  = node.get("relationships", {})

    def _field(*keys):
        for k in keys:
            v = attr.get(k)
            if v is not None:
                return v
        return None

    registered_count = None
    reg_data = rel.get("field_registered_users", {}).get("data")
    if isinstance(reg_data, list):
        registered_count = len(reg_data)

    return {
        "session_id":        node.get("id"),
        "drupal_nid":        attr.get("drupal_internal__nid"),
        "title":             _field("title"),
        "start_datetime":    _field("field_start_datetime", "field_date", "field_session_date"),
        "end_datetime":      _field("field_end_datetime"),
        "location":          _field("field_location"),
        "session_type":      _field("field_session_type"),
        "status":            _field("field_status") or (attr.get("status") and ("published" if attr["status"] else "unpublished")),
        "max_attendees":     _field("field_max_attendees", "field_capacity"),
        "current_attendees": _field("field_current_attendees"),
        "price":             _field("field_price"),
        "description":       (attr.get("field_description") or {}).get("value") if isinstance(attr.get("field_description"), dict) else _field("field_description"),
        "registered_count":  registered_count,
    }


def _user_from_node(node: dict, included: list | None = None) -> dict:
    attr  = node.get("attributes", {})
    rel   = node.get("relationships", {})

    roles = []
    for r in rel.get("roles", {}).get("data", []):
        role_id = r.get("id", "") or r.get("meta", {}).get("drupal_internal__target_id", "")
        if role_id:
            roles.append(role_id)

    return {
        "user_id":       node.get("id"),
        "drupal_uid":    attr.get("drupal_internal__uid"),
        "email":         attr.get("mail"),
        "username":      attr.get("name"),
        "first_name":    attr.get("field_first_name"),
        "last_name":     attr.get("field_last_name"),
        "date_of_birth": attr.get("field_date_of_birth"),
        "status":        "active" if attr.get("status") else "blocked",
        "created":       attr.get("created"),
        "changed":       attr.get("changed"),
        "roles":         roles,
    }


async def _jsonapi_get(path: str, params: dict | None = None) -> dict:
    resp = await _http.get(f"{_JSONAPI}/{path}", params=params or {})
    resp.raise_for_status()
    return resp.json()


async def _fetch_all_pages(path: str, params: dict, max_pages: int = 10) -> list[dict]:
    """Follow JSON:API pagination and collect all items up to max_pages."""
    items: list[dict] = []
    url = f"{_JSONAPI}/{path}"
    p   = dict(params)
    for _ in range(max_pages):
        resp = await _http.get(url, params=p)
        resp.raise_for_status()
        body = resp.json()
        items.extend(body.get("data", []))
        next_url = body.get("links", {}).get("next", {})
        if not next_url:
            break
        # next link already has all params encoded
        url = next_url.get("href") if isinstance(next_url, dict) else next_url
        p   = {}
    return items


# ─────────────────────────────────────────────
#  SESSION TOOLS
# ─────────────────────────────────────────────

@mcp.tool()
async def list_sessions(
    status: Annotated[str | None, Field(description="Filter by session status: 'active', 'published', 'cancelled', 'draft', 'full'. Omit for all sessions.")] = None,
    limit: Annotated[int, Field(description="Max sessions to return (default 100, max 200).")] = 100,
) -> dict[str, Any]:
    """
    List all event sessions. Optionally filter by status.
    Authoritative for session definitions, schedule, capacity, and enrollment.
    Returns: session_id (UUID for other tools), title, start_datetime, location, status, capacity.
    """
    params: dict[str, Any] = {"page[limit]": min(limit, 200)}
    if status:
        params["filter[field_status]"] = status
    try:
        nodes = await _fetch_all_pages("node/session", params)
        sessions = [_session_from_node(n) for n in nodes]
        return {"sessions": sessions, "count": len(sessions)}
    except Exception as exc:
        return _err(str(exc), sessions=[], count=0)


@mcp.tool()
async def get_session(
    session_id: Annotated[str, Field(description="The Drupal session UUID (from list_sessions or search_sessions_by_title). Format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx. Never guess this value.")],
) -> dict[str, Any]:
    """
    Get full detail for a single session by its UUID.

    Authoritative for session definitions, schedule, capacity, enrollment.
    """
    try:
        body = await _jsonapi_get(f"node/session/{session_id}")
        return _session_from_node(body.get("data", {}))
    except Exception as exc:
        return _err(str(exc), session_id=session_id)


@mcp.tool()
async def search_sessions_by_title(
    title: Annotated[str, Field(description="Title text to search for (partial, case-insensitive). Example: 'workshop' or 'keynote'.")],
) -> dict[str, Any]:
    """Search sessions whose title contains the given text (case-insensitive partial match). Returns matching sessions with capacity, enrollment count, date, and location. Use when an admin asks for a session by name or keyword."""
    params = {
        "filter[title][value]": title,
        "filter[title][operator]": "CONTAINS",
        "page[limit]": "100",
    }
    try:
        body = await _jsonapi_get("node/session", params)
        sessions = [_session_from_node(n) for n in body.get("data", [])]
        return {"sessions": sessions, "count": len(sessions)}
    except Exception as exc:
        return _err(str(exc), sessions=[], count=0)


@mcp.tool()
async def get_sessions_by_status(
    status: Annotated[str, Field(description="Session status to filter by: 'active', 'published', 'cancelled', 'draft', 'full'.")],
) -> dict[str, Any]:
    """Get all sessions with a specific publication/lifecycle status. Valid statuses: 'active', 'published', 'cancelled', 'draft', 'full'. Use to audit which sessions are still open for enrollment or which have been cancelled."""
    params = {
        "filter[field_status]": status,
        "page[limit]": "200",
    }
    try:
        nodes = await _fetch_all_pages("node/session", params)
        sessions = [_session_from_node(n) for n in nodes]
        return {"sessions": sessions, "count": len(sessions)}
    except Exception as exc:
        return _err(str(exc), sessions=[], count=0)


@mcp.tool()
async def get_sessions_by_type(
    session_type: Annotated[str, Field(description="Session type to filter by. Get valid values from get_all_session_types() first. Examples: 'workshop', 'keynote', 'networking'.")],
) -> dict[str, Any]:
    """Get all sessions of a specific type (e.g. 'workshop', 'keynote', 'networking'). Call get_all_session_types() first to discover valid values for this event. Includes capacity, enrollment count, and schedule."""
    params = {
        "filter[field_session_type]": session_type,
        "page[limit]": "200",
    }
    try:
        nodes = await _fetch_all_pages("node/session", params)
        sessions = [_session_from_node(n) for n in nodes]
        return {"sessions": sessions, "count": len(sessions)}
    except Exception as exc:
        return _err(str(exc), sessions=[], count=0)


@mcp.tool()
async def get_sessions_by_location(
    location: Annotated[str, Field(description="Location text to search for (partial match). Get valid values from get_all_session_locations() first.")],
) -> dict[str, Any]:
    """Get all sessions at a specific venue or room (partial match). Call get_all_session_locations() first to discover valid location strings. Useful when an admin asks 'what sessions are in room A?' or 'what's happening at the main stage?'."""
    params = {
        "filter[field_location][value]": location,
        "filter[field_location][operator]": "CONTAINS",
        "page[limit]": "200",
    }
    try:
        nodes = await _fetch_all_pages("node/session", params)
        sessions = [_session_from_node(n) for n in nodes]
        return {"sessions": sessions, "count": len(sessions)}
    except Exception as exc:
        return _err(str(exc), sessions=[], count=0)


@mcp.tool()
async def get_sessions_by_date_range(
    start_date: Annotated[str, Field(description="Range start in ISO 8601 format: '2026-05-01T00:00:00' or '2026-05-01'.")],
    end_date: Annotated[str, Field(description="Range end in ISO 8601 format: '2026-05-31T23:59:59' or '2026-05-31'.")],
) -> dict[str, Any]:
    """
    Get sessions that start within a date range.
    Use this for 'sessions this week', 'sessions in May', etc.
    """
    for field in ("field_start_datetime", "field_date", "field_session_date"):
        params = {
            f"filter[start][condition][path]": field,
            f"filter[start][condition][operator]": ">=",
            f"filter[start][condition][value]": start_date,
            f"filter[end][condition][path]": field,
            f"filter[end][condition][operator]": "<=",
            f"filter[end][condition][value]": end_date,
            "page[limit]": "200",
        }
        try:
            nodes = await _fetch_all_pages("node/session", params)
            if nodes:
                sessions = [_session_from_node(n) for n in nodes]
                return {"sessions": sessions, "count": len(sessions), "date_field_used": field}
        except Exception:
            continue
    # fallback: return all and let the agent filter
    try:
        nodes = await _fetch_all_pages("node/session", {"page[limit]": "200"})
        sessions = [_session_from_node(n) for n in nodes]
        return {"sessions": sessions, "count": len(sessions), "note": "date filter not applied — filter manually"}
    except Exception as exc:
        return _err(str(exc), sessions=[], count=0)


@mcp.tool()
async def get_upcoming_sessions(
    limit: Annotated[int, Field(description="Max upcoming sessions to return (default 50, max 200).")] = 50,
) -> dict[str, Any]:
    """Get sessions that start in the future, ordered by start date ascending."""
    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")
    for field in ("field_start_datetime", "field_date", "field_session_date"):
        params = {
            f"filter[start][condition][path]": field,
            f"filter[start][condition][operator]": ">=",
            f"filter[start][condition][value]": now,
            f"sort": field,
            "page[limit]": str(min(limit, 200)),
        }
        try:
            body = await _jsonapi_get("node/session", params)
            nodes = body.get("data", [])
            if nodes or "errors" not in body:
                sessions = [_session_from_node(n) for n in nodes]
                return {"sessions": sessions, "count": len(sessions)}
        except Exception:
            continue
    return _err("could not determine date field", sessions=[], count=0)


@mcp.tool()
async def get_sessions_today() -> dict[str, Any]:
    """Get all sessions scheduled for today."""
    today = datetime.now(timezone.utc)
    start = today.strftime("%Y-%m-%dT00:00:00")
    end   = today.strftime("%Y-%m-%dT23:59:59")
    return await get_sessions_by_date_range(start, end)


@mcp.tool()
async def get_session_attendees(
    session_id: Annotated[str, Field(description="Drupal session UUID. Get it via list_sessions or search_sessions_by_title first — never guess.")],
) -> dict[str, Any]:
    """
    Get the list of registered attendees for a specific session.

    Returns Drupal website accounts (email, name, drupal_uid).
    IMPORTANT: user_id here is a Drupal UUID — NOT the same as CRM master_uuid.
    For full member profile use crm__get_member_by_email per attendee.
    """
    try:
        body = await _jsonapi_get(
            f"node/session/{session_id}",
            {"include": "field_registered_users"},
        )
        node_attr = body.get("data", {}).get("attributes", {})
        included  = body.get("included", [])

        attendees = []
        for item in included:
            if item.get("type", "").startswith("user--"):
                a = item.get("attributes", {})
                attendees.append({
                    "uuid":       item.get("id"),
                    "drupal_uid": a.get("drupal_internal__uid"),
                    "email":      a.get("mail"),
                    "username":   a.get("name"),
                    "first_name": a.get("field_first_name"),
                    "last_name":  a.get("field_last_name"),
                })

        max_att = node_attr.get("field_max_attendees") or node_attr.get("field_capacity")
        cur_att = node_attr.get("field_current_attendees")

        return {
            "session_id":        session_id,
            "session_title":     node_attr.get("title"),
            "max_attendees":     max_att,
            "current_attendees": cur_att if cur_att is not None else len(attendees),
            "registered":        len(attendees),
            "attendees":         attendees,
        }
    except Exception as exc:
        return _err(str(exc), session_id=session_id, attendees=[])


@mcp.tool()
async def get_session_capacity_overview() -> dict[str, Any]:
    """
    Get a capacity summary for all sessions:
    how many spots are available vs taken, and which sessions are full.
    """
    try:
        nodes = await _fetch_all_pages("node/session", {"page[limit]": "200"})
        result = []
        full   = []
        for n in nodes:
            s   = _session_from_node(n)
            mx  = s.get("max_attendees")
            cur = s.get("current_attendees") or s.get("registered_count") or 0
            pct = round(cur / mx * 100, 1) if mx else None
            is_full = mx is not None and cur >= mx
            entry = {
                "session_id":    s["session_id"],
                "title":         s["title"],
                "max_attendees": mx,
                "current":       cur,
                "available":     (mx - cur) if mx is not None else None,
                "fill_pct":      pct,
                "is_full":       is_full,
            }
            result.append(entry)
            if is_full:
                full.append(s["title"])

        return {
            "sessions":    result,
            "total":       len(result),
            "full_count":  len(full),
            "full_titles": full,
        }
    except Exception as exc:
        return _err(str(exc), sessions=[])


@mcp.tool()
async def get_full_sessions() -> dict[str, Any]:
    """Get sessions that have reached their maximum attendee capacity."""
    overview = await get_session_capacity_overview()
    if "error" in overview:
        return overview
    full = [s for s in overview["sessions"] if s.get("is_full")]
    return {"sessions": full, "count": len(full)}


@mcp.tool()
async def get_sessions_with_available_spots(
    min_spots: Annotated[int, Field(description="Minimum available spots required (default 1).")] = 1,
) -> dict[str, Any]:
    """Get sessions that still have at least min_spots available."""
    overview = await get_session_capacity_overview()
    if "error" in overview:
        return overview
    available = [
        s for s in overview["sessions"]
        if s.get("available") is not None and s["available"] >= min_spots
    ]
    return {"sessions": available, "count": len(available)}


@mcp.tool()
async def get_all_session_types() -> dict[str, Any]:
    """Get a list of all distinct session types used across all sessions."""
    try:
        nodes    = await _fetch_all_pages("node/session", {"page[limit]": "200"})
        sessions = [_session_from_node(n) for n in nodes]
        types    = sorted({s.get("session_type") for s in sessions if s.get("session_type")})
        return {"session_types": types, "count": len(types)}
    except Exception as exc:
        return _err(str(exc), session_types=[])


@mcp.tool()
async def get_all_session_locations() -> dict[str, Any]:
    """Get a list of all distinct locations used across all sessions."""
    try:
        nodes     = await _fetch_all_pages("node/session", {"page[limit]": "200"})
        sessions  = [_session_from_node(n) for n in nodes]
        locations = sorted({s.get("location") for s in sessions if s.get("location")})
        return {"locations": locations, "count": len(locations)}
    except Exception as exc:
        return _err(str(exc), locations=[])


@mcp.tool()
async def get_sessions_summary() -> dict[str, Any]:
    """
    Get aggregate statistics for all sessions:
    total count, breakdown by status and type, total/average capacity.
    """
    try:
        nodes    = await _fetch_all_pages("node/session", {"page[limit]": "200"})
        sessions = [_session_from_node(n) for n in nodes]

        by_status: dict[str, int] = {}
        by_type:   dict[str, int] = {}
        total_capacity   = 0
        total_registered = 0
        capacity_count   = 0

        for s in sessions:
            st = s.get("status") or "unknown"
            tp = s.get("session_type") or "unknown"
            by_status[st] = by_status.get(st, 0) + 1
            by_type[tp]   = by_type.get(tp, 0) + 1
            mx = s.get("max_attendees")
            if mx:
                total_capacity += int(mx)
                capacity_count += 1
            cur = s.get("current_attendees") or 0
            total_registered += int(cur)

        return {
            "total_sessions":        len(sessions),
            "by_status":             by_status,
            "by_type":               by_type,
            "total_capacity":        total_capacity,
            "total_registered":      total_registered,
            "avg_capacity":          round(total_capacity / capacity_count, 1) if capacity_count else None,
            "overall_fill_pct":      round(total_registered / total_capacity * 100, 1) if total_capacity else None,
        }
    except Exception as exc:
        return _err(str(exc))


# ─────────────────────────────────────────────
#  DRUPAL ACCOUNT MANAGEMENT TOOLS
#  For person identity queries use CRM (crm__get_member_by_email etc.)
# ─────────────────────────────────────────────

async def get_user_by_email(email: str) -> dict[str, Any]:
    """Internal helper — resolves a Drupal user UUID from email for enrollment lookups."""
    params = {
        "filter[mail]": email,
        "include": "roles",
        "page[limit]": "1",
    }
    try:
        body  = await _jsonapi_get("user/user", params)
        data  = body.get("data", [])
        if not data:
            return {"error": f"No user found with email '{email}'"}
        return _user_from_node(data[0])
    except Exception as exc:
        return _err(str(exc), email=email)




@mcp.tool()
async def get_users_by_role(
    role: Annotated[str, Field(description="Drupal role name. Common values: 'company_admin', 'authenticated', 'administrator'.")],
) -> dict[str, Any]:
    """
    Get all Drupal users that have a specific Drupal role.

    Drupal website roles (people). NOT company billing entities — for those
    use `facturatie__get_company_billing_accounts`.
    NOTE: Returns Drupal user_id (UUID), NOT CRM master_uuid — these are different systems.
    """
    params = {
        "filter[roles.meta.drupal_internal__target_id]": role,
        "include": "roles",
        "page[limit]": "200",
    }
    try:
        nodes = await _fetch_all_pages("user/user", params)
        users = [_user_from_node(n) for n in nodes]
        return {"users": users, "count": len(users), "role": role}
    except Exception as exc:
        return _err(str(exc), users=[], role=role)


@mcp.tool()
async def get_company_admin_users() -> dict[str, Any]:
    """
    Get all Drupal users with the company_admin role (website admins for companies).

    These are PEOPLE with company-admin privileges on the website. NOT company
    billing entities — for those use `facturatie__get_company_billing_accounts`.
    A company may have multiple company_admin users; one user may admin
    multiple companies.
    """
    return await get_users_by_role("company_admin")


@mcp.tool()
async def get_recent_registrations(
    limit: Annotated[int, Field(description="Max users to return (default 20, max 100).")] = 20,
) -> dict[str, Any]:
    """Get the most recently registered Drupal website accounts, newest first. Useful for onboarding checks — shows who signed up recently and whether their accounts are active. Returns name, email, registration date, and status."""
    params = {
        "sort": "-created",
        "filter[status]": "1",
        "include": "roles",
        "page[limit]": str(min(limit, 100)),
    }
    try:
        body  = await _jsonapi_get("user/user", params)
        users = [_user_from_node(n) for n in body.get("data", [])]
        return {"users": users, "count": len(users)}
    except Exception as exc:
        return _err(str(exc), users=[], count=0)


@mcp.tool()
async def get_blocked_users() -> dict[str, Any]:
    """Get all Drupal user accounts that have been blocked (status = 0 / deactivated). Returns name, email, and block date. Use when an admin asks who is blocked or to audit access restrictions. To re-activate, use set_user_blocked(email, blocked=False)."""
    params = {
        "filter[status]": "0",
        "include": "roles",
        "page[limit]": "200",
    }
    try:
        nodes = await _fetch_all_pages("user/user", params)
        users = [_user_from_node(n) for n in nodes]
        return {"users": users, "count": len(users)}
    except Exception as exc:
        return _err(str(exc), users=[], count=0)


@mcp.tool()
async def get_users_registered_after(
    date: Annotated[str, Field(description="ISO 8601 datetime string: '2026-01-01T00:00:00' or '2026-01-01'.")],
) -> dict[str, Any]:
    """
    Get users who registered after a given date.
    Returns Drupal website accounts (NOT CRM members — different systems).
    """
    params = {
        "filter[created][condition][path]": "created",
        "filter[created][condition][operator]": ">=",
        "filter[created][condition][value]": date,
        "sort": "-created",
        "include": "roles",
        "page[limit]": "200",
    }
    try:
        nodes = await _fetch_all_pages("user/user", params)
        users = [_user_from_node(n) for n in nodes]
        return {"users": users, "count": len(users), "since": date}
    except Exception as exc:
        return _err(str(exc), users=[], count=0)




# ─────────────────────────────────────────────
#  ENROLLMENT TOOLS
# ─────────────────────────────────────────────

@mcp.tool()
async def get_user_enrolled_sessions(
    user_uuid: Annotated[str, Field(description="The Drupal user UUID (user_id from get_recent_registrations or get_user_by_email). NOT the CRM master_uuid — these are different systems.")],
) -> dict[str, Any]:
    """
    Get all sessions a specific Drupal user is enrolled in.
    Prefer get_user_enrolled_sessions_by_email if you only have an email address.
    """
    params = {
        "filter[field_registered_users.id]": user_uuid,
        "page[limit]": "200",
    }
    try:
        nodes    = await _fetch_all_pages("node/session", params)
        sessions = [_session_from_node(n) for n in nodes]
        return {"user_uuid": user_uuid, "sessions": sessions, "count": len(sessions)}
    except Exception as exc:
        return _err(str(exc), user_uuid=user_uuid, sessions=[], count=0)


@mcp.tool()
async def get_user_enrolled_sessions_by_email(
    email: Annotated[str, Field(description="The user's email address (must include @). Looks up the Drupal account internally.")],
) -> dict[str, Any]:
    """
    Get all sessions a user is enrolled in, looked up by email.
    Primary tool for 'what sessions is X enrolled in?' — resolves the Drupal user internally.
    """
    user = await get_user_by_email(email)
    if "error" in user:
        return user
    return await get_user_enrolled_sessions(user["user_id"])


@mcp.tool()
async def get_enrollment_overview() -> dict[str, Any]:
    """
    Overview of enrollment numbers across all sessions: total enrollments platform-wide,
    and all sessions ranked by number of enrolled attendees. Use for at-a-glance popularity stats
    or to find which sessions are close to capacity.
    """
    try:
        nodes    = await _fetch_all_pages(
            "node/session",
            {"page[limit]": "200", "include": "field_registered_users"},
        )
        rows = []
        for n in nodes:
            s   = _session_from_node(n)
            cnt = s.get("current_attendees") or s.get("registered_count") or 0
            mx  = s.get("max_attendees")
            rows.append({
                "session_id":    s["session_id"],
                "title":         s["title"],
                "enrolled":      cnt,
                "max_attendees": mx,
                "fill_pct":      round(cnt / mx * 100, 1) if mx else None,
            })
        rows.sort(key=lambda r: r["enrolled"], reverse=True)
        total = sum(r["enrolled"] for r in rows)
        return {
            "sessions":       rows,
            "total_enrollments": total,
            "session_count":  len(rows),
        }
    except Exception as exc:
        return _err(str(exc), sessions=[])


@mcp.tool()
async def get_most_popular_sessions(
    limit: Annotated[int, Field(description="Number of top sessions to return (default 10).")] = 10,
) -> dict[str, Any]:
    """Get the sessions with the most enrollments, sorted by popularity."""
    overview = await get_enrollment_overview()
    if "error" in overview:
        return overview
    top = overview["sessions"][:limit]
    return {"sessions": top, "count": len(top)}


# ─────────────────────────────────────────────
#  PLATFORM STATS TOOLS
# ─────────────────────────────────────────────

@mcp.tool()
async def get_platform_stats() -> dict[str, Any]:
    """
    Get a high-level overview of the entire platform:
    total users, sessions, enrollments, capacity fill rate.
    """
    try:
        users_body    = await _jsonapi_get("user/user", {"filter[status]": "1", "page[limit]": "1"})
        sessions_body = await _jsonapi_get("node/session", {"page[limit]": "1"})

        total_users    = users_body.get("meta", {}).get("count")
        total_sessions = sessions_body.get("meta", {}).get("count")

        sessions_data = await get_sessions_summary()

        return {
            "total_active_users":  total_users,
            "total_sessions":      total_sessions or sessions_data.get("total_sessions"),
            "total_enrolled":      sessions_data.get("total_registered"),
            "total_capacity":      sessions_data.get("total_capacity"),
            "overall_fill_pct":    sessions_data.get("overall_fill_pct"),
            "sessions_by_status":  sessions_data.get("by_status"),
            "sessions_by_type":    sessions_data.get("by_type"),
        }
    except Exception as exc:
        return _err(str(exc))


@mcp.tool()
async def get_registration_stats() -> dict[str, Any]:
    """
    Get user registration statistics:
    total users, company vs private accounts, registrations per day (last 30 days).
    """
    try:
        all_nodes = await _fetch_all_pages("user/user", {"filter[status]": "1", "include": "roles", "page[limit]": "200"})
        users     = [_user_from_node(n) for n in all_nodes]

        company_count  = sum(1 for u in users if "company_admin" in u.get("roles", []))
        private_count  = len(users) - company_count

        daily: dict[str, int] = {}
        for u in users:
            created = u.get("created")
            if created:
                try:
                    day = created[:10]
                    daily[day] = daily.get(day, 0) + 1
                except Exception:
                    pass

        sorted_days = sorted(daily.items(), reverse=True)[:30]

        return {
            "total_users":       len(users),
            "company_accounts":  company_count,
            "private_accounts":  private_count,
            "registrations_per_day": dict(sorted_days),
        }
    except Exception as exc:
        return _err(str(exc))


@mcp.tool()
async def get_users_count_by_role() -> dict[str, Any]:
    """Get the total number of active Drupal users grouped by their assigned role. Useful for a quick access-control overview — how many admins, editors, regular attendees, etc. are registered on the platform."""
    try:
        nodes  = await _fetch_all_pages("user/user", {"include": "roles", "page[limit]": "200"})
        users  = [_user_from_node(n) for n in nodes]
        counts: dict[str, int] = {}
        for u in users:
            for role in u.get("roles", []) or ["authenticated"]:
                counts[role] = counts.get(role, 0) + 1
        return {"by_role": counts, "total_users": len(users)}
    except Exception as exc:
        return _err(str(exc))


# ─────────────────────────────────────────────
#  WRITE OPERATIONS
# ─────────────────────────────────────────────

_JSONAPI_CT = "application/vnd.api+json"


@mcp.tool()
async def enroll_user_in_session(
    session_id: Annotated[str, Field(description="Drupal session UUID. Get it via list_sessions — never guess.")],
    email: Annotated[str, Field(description="Email address of the user to enroll.")],
) -> dict[str, Any]:
    """
    Enroll a user in a session by adding them to field_registered_users.
    WRITE OPERATION — confirm with admin before calling.
    """
    user = await get_user_by_email(email)
    if "error" in user:
        return user
    try:
        resp = await _http.post(
            f"{_JSONAPI}/node/session/{session_id}/relationships/field_registered_users",
            json={"data": [{"type": "user--user", "id": user["user_id"]}]},
            headers={"Content-Type": _JSONAPI_CT},
        )
        if resp.status_code in (200, 204):
            return {"success": True, "session_id": session_id, "email": email, "message": f"{email} enrolled in session."}
        return {"error": f"Drupal returned HTTP {resp.status_code}", "body": resp.text[:300]}
    except Exception as exc:
        return _err(str(exc))


@mcp.tool()
async def unenroll_user_from_session(
    session_id: Annotated[str, Field(description="Drupal session UUID. Get it via list_sessions — never guess.")],
    email: Annotated[str, Field(description="Email address of the user to unenroll.")],
) -> dict[str, Any]:
    """
    Remove a user from a session. WRITE OPERATION — confirm with admin before calling.
    """
    user = await get_user_by_email(email)
    if "error" in user:
        return user
    try:
        resp = await _http.request(
            "DELETE",
            f"{_JSONAPI}/node/session/{session_id}/relationships/field_registered_users",
            json={"data": [{"type": "user--user", "id": user["user_id"]}]},
            headers={"Content-Type": _JSONAPI_CT},
        )
        if resp.status_code in (200, 204):
            return {"success": True, "session_id": session_id, "email": email, "message": f"{email} removed from session."}
        return {"error": f"Drupal returned HTTP {resp.status_code}", "body": resp.text[:300]}
    except Exception as exc:
        return _err(str(exc))


@mcp.tool()
async def update_session_status(
    session_id: Annotated[str, Field(description="Drupal session UUID. Get it via list_sessions — never guess.")],
    status: Annotated[str, Field(description="New session status. Common values: 'active', 'cancelled', 'full', 'draft'.")],
) -> dict[str, Any]:
    """
    Update a session's status field. WRITE OPERATION — confirm with admin before calling.

    Use list_sessions to confirm the session_id and current status first.
    """
    try:
        resp = await _http.patch(
            f"{_JSONAPI}/node/session/{session_id}",
            json={"data": {"type": "node--session", "id": session_id, "attributes": {"field_status": status}}},
            headers={"Content-Type": _JSONAPI_CT},
        )
        if resp.status_code in (200, 204):
            return {"success": True, "session_id": session_id, "status": status, "message": f"Session status updated to '{status}'."}
        return {"error": f"Drupal returned HTTP {resp.status_code}", "body": resp.text[:300]}
    except Exception as exc:
        return _err(str(exc))


@mcp.tool()
async def update_session_capacity(
    session_id: Annotated[str, Field(description="Drupal session UUID. Get it via list_sessions — never guess.")],
    max_attendees: Annotated[int, Field(description="New maximum attendee count. Must be ≥ current enrollment.", ge=1)],
) -> dict[str, Any]:
    """
    Update a session's maximum capacity. WRITE OPERATION — confirm with admin before calling.

    Check current enrollment via get_session_attendees before reducing capacity.
    """
    try:
        resp = await _http.patch(
            f"{_JSONAPI}/node/session/{session_id}",
            json={"data": {"type": "node--session", "id": session_id, "attributes": {"field_max_attendees": max_attendees}}},
            headers={"Content-Type": _JSONAPI_CT},
        )
        if resp.status_code in (200, 204):
            return {"success": True, "session_id": session_id, "max_attendees": max_attendees, "message": f"Session capacity updated to {max_attendees}."}
        return {"error": f"Drupal returned HTTP {resp.status_code}", "body": resp.text[:300]}
    except Exception as exc:
        return _err(str(exc))


@mcp.tool()
async def set_user_blocked(
    email: Annotated[str, Field(description="Email address of the Drupal user to block or unblock.")],
    blocked: Annotated[bool, Field(description="True to block the account, False to unblock/reactivate it.")],
) -> dict[str, Any]:
    """
    Block or unblock a Drupal website account. WRITE OPERATION — confirm with admin before calling.

    Use get_blocked_users to see currently blocked accounts.
    """
    user = await get_user_by_email(email)
    if "error" in user:
        return user
    try:
        resp = await _http.patch(
            f"{_JSONAPI}/user/user/{user['user_id']}",
            json={"data": {"type": "user--user", "id": user["user_id"], "attributes": {"status": not blocked}}},
            headers={"Content-Type": _JSONAPI_CT},
        )
        action = "blocked" if blocked else "unblocked"
        if resp.status_code in (200, 204):
            return {"success": True, "email": email, "blocked": blocked, "message": f"User {email} has been {action}."}
        return {"error": f"Drupal returned HTTP {resp.status_code}", "body": resp.text[:300]}
    except Exception as exc:
        return _err(str(exc))


if __name__ == "__main__":
    mcp.run(
        transport="streamable-http",
        host="0.0.0.0",
        port=int(os.getenv("PORT", "8006")),
    )
