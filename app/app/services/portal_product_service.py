"""Customer-facing Forum Fortress product vocabulary.

This module is deliberately presentation-only. Plan enforcement remains in
``plan_service`` and the configured ``PlanDefinition`` values; route handlers
and templates use these helpers so internal enum values never leak into the
portal.
"""

from __future__ import annotations

from types import MappingProxyType
from typing import Any

from app.services import plugin_release_service


_PLATFORMS = {
    "xenforo": {"label": "XenForo", "status": "Available"},
    "phpbb": {"label": "phpBB", "status": "Available"},
    "invision": {"label": "Invision Community", "status": "Available"},
    "smf": {"label": "SMF", "status": "Beta"},
    "mybb": {"label": "MyBB", "status": "Beta"},
    "flarum": {"label": "Flarum 2", "status": "Available", "version": "1.0.1"},
}
PLATFORMS = MappingProxyType({key: MappingProxyType(value) for key, value in _PLATFORMS.items()})

_EVENT_GROUPS = {
    "registration": {
        "label": "Registration",
        "values": ("register", "registration"),
    },
    "post_thread": {
        "label": "Post or thread",
        "values": ("topic", "reply", "thread", "post", "topic_edit", "reply_edit", "thread_edit", "post_edit"),
    },
    "profile": {
        "label": "Profile",
        "values": ("profile", "profile_edit"),
    },
    "signature": {
        "label": "Signature",
        "values": ("signature", "signature_edit"),
    },
    "contact_form": {
        "label": "Contact form",
        "values": ("contact", "contact_page", "contact_form"),
    },
    "other": {"label": "Other", "values": ()},
}
EVENT_GROUPS = MappingProxyType({key: MappingProxyType(value) for key, value in _EVENT_GROUPS.items()})


def platform_info(platform: str | None) -> dict[str, str]:
    key = str(platform or "").strip().lower()
    row = PLATFORMS.get(key)
    if row is not None:
        return {"key": key, "label": str(row["label"]), "status": str(row["status"])}
    return {"key": key or "unknown", "label": "Unknown platform", "status": "Unavailable"}


def supported_platforms(settings: Any | None = None) -> list[dict[str, str]]:
    """Return the customer-facing platform catalog and current plugin versions.

    Downloadable plugin versions come from the same release manifest used by
    the marketing homepage. Flarum remains a Composer-only entry, so its
    version is kept in this catalog until it has a published artifact manifest.
    """
    platforms: list[dict[str, str]] = []
    for key in ("xenforo", "phpbb", "invision", "smf", "mybb", "flarum"):
        row = platform_info(key)
        if settings is not None and key != "flarum":
            release = plugin_release_service.latest_release(settings, key)
            if release:
                row["version"] = str(release["version"])
        elif settings is not None and key == "flarum":
            version = str(PLATFORMS[key].get("version") or "").strip()
            if version:
                row["version"] = version
        platforms.append(row)
    return platforms


def event_group_for_value(value: str | None) -> str:
    raw = str(value or "").strip().lower()
    for key, row in EVENT_GROUPS.items():
        if raw == key or raw in row["values"]:
            return key
    return "other"


def event_label(value: str | None) -> str:
    return str(EVENT_GROUPS[event_group_for_value(value)]["label"])


def event_options(*, include_other: bool = True) -> list[dict[str, Any]]:
    return [
        {"key": key, "label": str(row["label"]), "values": tuple(row["values"])}
        for key, row in EVENT_GROUPS.items()
        if include_other or key != "other"
    ]


_FRIENDLY_STATUSES = {
    "queued": ("Pending", "Waiting for the forum's next sync."),
    "pending": ("Pending", "Waiting for the forum's next sync."),
    "dispatched": ("Sent to forum", "The action was sent and is awaiting acknowledgement."),
    "applied": ("Applied", "The forum confirmed that the action was applied."),
    "failed": ("Failed", "The forum could not apply this action."),
    "retry": ("Ready to retry", "The action can be safely sent again."),
    "timed_out": ("Ended after no response", "The conversation ended after a period without activity."),
    "interaction_auto_closed": ("Closed automatically", "The conversation was closed after it became inactive."),
    "open": ("Open", "This conversation is still open."),
    "closed": ("Closed", "This conversation has ended."),
    "answered": ("Answered", "The support team has replied."),
    "cust-reply": ("Your reply received", "Your latest reply is waiting for the support team."),
    "human-review": ("With the support team", "A human support-team member will review this ticket."),
    "ai-awaiting-confirmation": ("Waiting for your confirmation", "Please confirm whether the automated response resolved the issue."),
    "spam-review": ("Under review", "The support request is being checked before processing."),
}


def friendly_status(value: str | None) -> dict[str, str]:
    key = str(value or "").strip().lower()
    label, explanation = _FRIENDLY_STATUSES.get(
        key,
        (key.replace("_", " ").strip().title() or "Unknown", "No further status detail is available."),
    )
    return {"key": key or "unknown", "label": label, "explanation": explanation}
