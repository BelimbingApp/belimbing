import { expect, test } from "bun:test";
import {
    DOMAIN_PIN_STALE_ISSUE_MARKER,
    DOMAIN_PIN_STALE_ISSUE_TITLE,
    domainPinsStaleIssueBody,
    staleDomainPins,
} from "../../scripts/ci/domain-pin-stale-report";

test("stale-pin parsing keeps only WARN rows and preserves both domains and counts", () => {
    const pins = staleDomainPins([
        "WARN people: pin is 51 commits behind main (BelimbingApp/blb-people@1111111111111111111111111111111111111111)",
        "OK people-connector: pinned commit exists, 3 commits behind main",
        "WARN people-connector: pin is 73 commits behind main (BelimbingApp/blb-people-connector@2222222222222222222222222222222222222222)",
    ].join("\n"));

    expect(pins).toHaveLength(2);
    const body = domainPinsStaleIssueBody(pins, "https://example.test/run/10");
    expect(body).toContain(DOMAIN_PIN_STALE_ISSUE_MARKER);
    expect(DOMAIN_PIN_STALE_ISSUE_TITLE).toBe("Domain pins stale");
    expect(body).toContain("`people`");
    expect(body).toContain("`people-connector`");
    expect(body).toContain("| 51 |");
    expect(body).toContain("| 73 |");
    expect(body).not.toContain("3 commits behind");
});

test("a malformed warning fails closed instead of creating an incomplete issue", () => {
    expect(() => staleDomainPins("WARN people: malformed")).toThrow("invalid stale Domain pin warning");
});
