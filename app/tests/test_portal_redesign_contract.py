from __future__ import annotations

import json
from pathlib import Path

import pytest

from app.config import load_settings
from app.services import (
    decision_display_ref_service,
    plan_service,
    portal_product_service,
    portal_service,
    public_checker_service,
)
from app.web import public_site


ROOT = Path(__file__).resolve().parents[1]
SETTINGS = load_settings(ROOT / "config/supernode.yaml")


def test_canonical_plan_catalog_and_recommendation() -> None:
    catalog = {row["code"]: row for row in plan_service.portal_plan_catalog(SETTINGS)}

    assert catalog["free"]["limits"] == {
        "monthly_checks": 2_000,
        "daily_automatic_blocks": 25,
        "forums": 1,
    }
    assert catalog["core"]["limits"] == {
        "monthly_checks": 20_000,
        "daily_automatic_blocks": 250,
        "forums": 2,
    }
    assert catalog["pro"]["limits"] == {
        "monthly_checks": 100_000,
        "daily_automatic_blocks": None,
        "forums": 5,
    }
    assert [row["recommended"] for row in catalog.values()] == [False, True, False]
    assert plan_service.core_is_recommended(SETTINGS) is True
    assert {code: row["price"] for code, row in catalog.items()} == {
        "free": "$0/year",
        "core": "$10/year",
        "pro": "$40/year",
    }
    assert {row["protection_engine"] for row in catalog.values()} == {"Same protection engine on every plan"}
    assert all("Insights included" in row["features"] for row in catalog.values())
    assert catalog["free"]["positioning"] == "Perfect for trying the service out or protecting smaller communities."
    assert catalog["core"]["support"].endswith("same or next business day ticket responses.")
    assert catalog["pro"]["positioning"].startswith("For busy forums or multiple forum networks")
    assert catalog["pro"]["support"].endswith("priority same-day ticket responses, and human telephone support.")


def test_canonical_platforms_and_customer_event_names() -> None:
    assert portal_product_service.supported_platforms() == [
        {"key": "xenforo", "label": "XenForo", "status": "Available"},
        {"key": "phpbb", "label": "phpBB", "status": "Available"},
        {"key": "invision", "label": "Invision Community", "status": "Available"},
        {"key": "smf", "label": "SMF", "status": "Beta"},
        {"key": "mybb", "label": "MyBB", "status": "Beta"},
        {"key": "flarum", "label": "Flarum 2", "status": "Available"},
    ]
    assert portal_product_service.platform_info("Flarum") == {
        "key": "flarum",
        "label": "Flarum 2",
        "status": "Available",
    }
    assert portal_product_service.event_label("register") == "Registration"
    assert portal_product_service.event_label("topic_edit") == "Post or thread"
    assert portal_product_service.event_label("profile_edit") == "Profile"
    assert portal_product_service.event_label("signature_edit") == "Signature"
    assert portal_product_service.event_label("contact_page") == "Contact form"


def test_available_connection_versions_follow_release_manifest() -> None:
    platforms = {row["key"]: row for row in portal_product_service.supported_platforms(SETTINGS)}
    manifest = json.loads((ROOT / "app" / "releases" / "ff-plugin-releases.json").read_text(encoding="utf-8"))

    # Binary integrations take their displayed versions from the release manifest.
    for platform, release in manifest.items():
        if platform == "xenforo_purge":
            continue
        assert platforms[platform]["version"] == release["version"]
    assert platforms["flarum"]["version"] == "1.0.1"


def test_customer_decision_references_include_the_account_component() -> None:
    reference = decision_display_ref_service.format_reference(
        decision_date=decision_display_ref_service.decision_date_for_timestamp(1_785_499_200),
        account_id=17,
        sequence=12_345,
    )
    assert decision_display_ref_service.is_valid_reference(reference)
    assert reference == "6212-0017-12345"
    assert [len(group) for group in reference.split("-")] == [4, 4, 5]
    assert decision_display_ref_service.is_valid_customer_input("9218-0123-0456")
    assert not decision_display_ref_service.is_valid_customer_input("9218-0123-456")
    assert decision_display_ref_service.is_valid_customer_input("9218-0123-04567")


@pytest.mark.parametrize(
    ("value", "field", "label"),
    [
        ("203.0.113.7", "ip", "IP address"),
        ("person@example.test", "email", "Email address"),
        ("https://forum.example.test/path", "url", "URL or domain"),
        ("forum.example.test", "url", "URL or domain"),
        ("member_name", "username", "Username"),
        ("arbitrary words here", "", "Type needed"),
    ],
)
def test_checker_strict_classification(value: str, field: str, label: str) -> None:
    assert public_checker_service.detect_checker_input(value) == {
        "field": field,
        "label": label,
        "value": value,
    }


def test_checker_override_still_validates_the_value() -> None:
    fields, detected = public_checker_service.resolve_single_checker_input(
        q="not an ip",
        input_type="ip",
    )
    assert detected["field"] == ""
    assert detected["label"] == "Invalid ip address"
    assert all(value is None for value in fields.values())


def test_portal_date_range_validation_is_specific() -> None:
    start, end = portal_service.validate_portal_date_range("2026-08-01", "2026-08-01")
    assert start is not None and end is not None and end - start == 86_399
    with pytest.raises(ValueError, match="From date must be on or before"):
        portal_service.validate_portal_date_range("2026-08-02", "2026-08-01")
    with pytest.raises(ValueError, match="valid From date"):
        portal_service.validate_portal_date_range("2026-02-30", "")


def test_primary_and_subordinate_routes_are_registered() -> None:
    paths = {route.path for route in public_site.router.routes}
    assert {
        "/",
        "/decision-log",
        "/moderation",
        "/insights",
        "/checker",
        "/connected-forums",
        "/plan",
        "/support",
        "/reports",
        "/support/chat",
        "/support/timeline",
        "/full-registration",
    } <= paths


def test_portal_template_encodes_navigation_dialog_and_reference_safety() -> None:
    portal = (ROOT / "app/web/templates/portal.html").read_text(encoding="utf-8")
    heatmap = (ROOT / "app/web/templates/partials/portal_graphs_attack_insights.html").read_text(encoding="utf-8")

    for label in (
        "Overview",
        "Decision history",
        "Moderation",
        "Insights",
        "Blocklist checker",
        "Connected forums",
        "Plan and billing",
        "Support",
    ):
        assert f"<span>{label}</span>" in portal
    assert 'href="/connected-forums"' in portal
    assert 'aria-current="page"' in portal
    assert 'aria-controls="portalSidebar" aria-expanded="false"' in portal
    assert "sidebar.setAttribute('role', 'dialog')" in portal
    assert "sidebar.setAttribute('aria-modal', 'true')" in portal
    assert "event.key === 'Escape'" in portal
    assert "node.setAttribute('inert', '')" in portal
    assert "const modalOpeners = new WeakMap();" in portal
    assert "const modalFocusables = (m)" in portal
    assert "requestAnimationFrame(() => opener.focus())" in portal
    assert 'role="dialog" aria-modal="true" aria-labelledby="ffCancelSubTitle"' in portal
    assert "window.confirm" not in portal
    assert "window.alert" not in portal
    assert "Support chat" in portal and "Account connected" in portal
    assert "Billing System" in portal
    assert "Forum Fortress is provided by <strong>Marscastle Ltd</strong>" in portal
    assert "Stripe securely processes your payment" in portal
    assert "Your full card details are never visible" in portal
    assert "support ticket service" in portal
    assert "usually the same or next business day" in portal
    assert "We aim to respond the same day." in portal
    assert "text-overflow: clip" in portal
    assert "white-space: nowrap" in portal
    assert "font-variant-numeric: tabular-nums" in portal
    assert 'aria-label="View decision {{ row.decision_reference }}"' in portal
    assert 'aria-label="Report decision {{ row.decision_reference }} as incorrect"' in portal
    assert 'role="img" aria-label="Spam attack frequency heatmap.' in heatmap
    assert '<button type="button" class="heatmap-cell"' not in heatmap
    assert "Exact hourly heatmap values" in heatmap
