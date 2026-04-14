# Remote Phone Browser Access

**Status:** LAN Proposal Draft
**Last Updated:** 2026-04-14
**Sources:** User request (2026-04-13), `docs/brief.md`

## Problem Essence

Belimbing needs a practical phone-browser access story for self-hosted businesses that starts with LAN access on the same local network and then extends to WAN access from outside that network. The solution must stay simple enough for non-technical licensees to adopt, while keeping security and environment boundaries clear.

## Desired Outcome

We want a high-level direction for Belimbing where remote access from a phone browser is treated as a first-class product capability rather than an afterthought. The outcome we are aiming to define should satisfy these goals:

**LAN (same trusted local network as the Belimbing host)**

- A Belimbing operator can open the system from a phone browser on that network.
- LAN access uses Belimbing's normal login gate and role rules.

**WAN (away from that network)**

- A Belimbing operator can open the system from a phone browser while away from the local network.
- WAN access is mediated by VPN rather than by exposing Belimbing directly to the open internet.

**Across LAN and WAN**

- The browser surface remains the primary access path; channel-based access may complement it later but is not the main answer.
- HTTPS is mandatory for the phone-browser story because some required browser capabilities, such as camera access, depend on secure origins.
- Local and staging remain `core-admin` surfaces.
- The approach works for the smallest self-hosted setup first, while still scaling cleanly to larger deployments that separate environments across different machines.
- The eventual direction prefers open-source building blocks so the licensee's cost stays low.
- The chosen shape feels easy for non-technical licensees to set up and operate.
- The chosen shape keeps dependency burden low and makes clear which pieces Belimbing installs directly, which pieces are orchestrated by setup scripts, and which pieces Lara may help configure.
- Security is a first-class driver of the access model, not a later hardening pass.
- The eventual design minimizes operational confusion for non-expert operators, especially single-owner businesses and small teams without dedicated IT staff.

## Public Contract

At the current planning level, the access model is easiest to reason about if we separate it into two reachability targets and solve them in order:

- **LAN access:** user is on the same trusted local network as the Belimbing host and uses the normal Belimbing login flow.
- **WAN access:** user is outside that network and must first join the trusted private network through VPN before using the normal Belimbing login flow.

This gives Belimbing a simple contract:

- **First solve LAN reachability well** — stable browser access on the local network.
- **Then extend that to WAN** through VPN, ideally reusing the same browser surface.
- **Application access** is handled by Belimbing login and normal environment role rules.
- **Environment restrictions** still apply after login; local and staging remain `core-admin` only.

## Deployment tiers

### 1. Single computer — development and production instances

Suitable for solo operators and tiny teams.

### 2. Two computers — development and staging on one computer, production on another

Suitable for small companies.

### 3. Three or more computers — dedicated database, load-balanced application tier

Suitable for medium to large companies. Not covered in this plan.

## Top-Level Components

### Browser access surface

The phone browser is the primary user experience Belimbing must support well.

### LAN access path

When a user is on the same trusted local network, Belimbing should behave like a normal internal web application: reach the environment over a stable local HTTPS browser address, then authenticate through Belimbing's usual login gate.

### HTTPS and certificate trust

HTTPS is not optional for Belimbing's phone-browser UX. Features such as camera access require a secure origin, so the LAN solution must include a certificate-trust story that works on phones without asking each user to install and trust a private CA manually.

### LAN naming and discovery

LAN access needs a discoverable, understandable address story for phones. Users should not have to guess IPs or remember fragile port numbers; Belimbing should guide them toward a stable local HTTPS address per environment and an easy way to open it from a phone.

### Domain and DNS layer

The easiest low-cost LAN story is likely to use real domain names for each environment and resolve those names to private LAN IPs. This avoids the trust problems of self-signed certificates and made-up internal-only TLDs while keeping the browser URL stable and recognizable.

### VPN-based WAN access path

When a user is outside the local network, they should first enter the trusted network through VPN. Once connected, Belimbing should feel like the same browser application, ideally reusing the same internal address story rather than presenting a completely different remote-access surface.

### Distinct URLs per environment

Each environment is an independent Belimbing instance with its own URL. There is no separate product requirement for extra mobile-only labeling to distinguish environments: the address bar and normal browsing habits (bookmarks, history) provide the disambiguation.

### Deployment pattern

Belimbing should converge on one reference deployment pattern—same LAN HTTPS and VPN-backed WAN story, documented and packaged—rather than maintaining multiple competing recipes or leaving every licensee to invent network wiring from scratch.

### Setup and automation path

The chosen access story must be judged partly by how easy it is to install and operate for non-technical licensees. We need a clear view of which dependencies Belimbing owns, what can be wired into `scripts/setup.sh` or a companion setup script, and whether Lara should assist with configuration or troubleshooting.

## Design Decisions

### D0: Solve phone access through the browser first

Phone-browser access is the baseline requirement. Telegram or other channel-based entry points may become useful later, but they should not be the primary answer to access.

### D1: Model the solution around two reachability targets, but solve LAN first

The top-level access model should be expressed as `LAN` and `WAN`. This is the cleanest framing because it separates reachability from Belimbing's application-level authentication and role rules, and it gives us a clear sequence: first make LAN access excellent, then extend it to WAN.

### D2: LAN uses Belimbing's normal login gate

On the local network, Belimbing should not invent a second access mechanism. Users who can reach the environment over LAN use the normal Belimbing login flow and then remain subject to the environment's existing role restrictions.

### D3: LAN needs a stable local browser address, not an implicit host-machine assumption

The LAN solution should be designed around how a phone actually opens Belimbing in a browser. That means the user needs a stable local address story for each environment rather than an implementation that only feels obvious from the host machine itself.

### D4: HTTPS is mandatory, including on LAN

Belimbing should not treat HTTPS as optional or production-only. The browser capabilities we want on phones require secure origins, so the chosen LAN story must provide trusted HTTPS from the beginning.

### D5: The default LAN solution should use real domains plus publicly trusted certificates

The easiest and most cost-effective default LAN direction is to use a real domain owned by the licensee, assign stable environment hostnames under it, and serve them with publicly trusted HTTPS certificates. In practice that means names like `prod.<company-domain>` or `local.<company-domain>` rather than private-only names such as `.local` or `.lara`.

This is the best combined tradeoff because:

- the only unavoidable cash cost is a domain name, which is small compared to support friction
- phones already trust public certificate authorities, so camera and other secure-origin features work without manual certificate installation
- the same hostname pattern can scale from one-machine setups to multi-host setups
- the browser UX stays simple: join Wi-Fi, open a normal HTTPS URL, log in

### D6: Raw IP should be bootstrap-only, not the primary LAN UX

Raw IP access may remain useful for first-run troubleshooting, but it should not be Belimbing's primary LAN story because it is weak on memorability, environment clarity, and trusted HTTPS.

### D7: WAN should later be handled through VPN

For access from outside the local network, the leading direction is still to use VPN rather than exposing Belimbing directly to the public internet. VPN becomes the network-level answer to who may reach the environment from afar once the LAN story is solid.

### D8: Belimbing auth handles application access; VPN handles remote network access

The cleaner split of responsibilities is:

- **VPN:** decides who may join the trusted network from WAN
- **Belimbing login and roles:** decide who may enter and use a given environment once they can reach it

This avoids overcomplicating Belimbing with a separate WAN permission matrix inside the application.

### D9: Local and staging remain core-admin surfaces

Local and staging should continue to be treated as `core-admin` environments. VPN does not change that; it only changes whether the user can reach the network from outside.

### D10: Prefer open-source, low-cost infrastructure building blocks

The target solution should prioritize open-source and self-hostable components so the licensee cost stays low and the deployment story remains aligned with Belimbing's framework philosophy.

### D11: The shape is still judged by setup ease, dependency burden, and security

Even with VPN as the WAN direction, the final shape should be evaluated by three drivers:

- how easy it feels for a non-technical licensee
- how many dependencies and setup steps it introduces
- how strong the resulting security posture is

## Proposal 1 — Preferred LAN Direction

The current LAN proposal is to standardize Belimbing around **real domains, a dedicated `blb` subdomain, split-horizon DNS, and publicly trusted HTTPS**.

For a licensee with domain `example.com`, the proposed environment URLs are:

- `prod.blb.example.com`
- `staging.blb.example.com`
- `local.blb.example.com`

The proposed LAN setup is:

- **HTTPS edge:** FrankenPHP with Caddy
- **Certificates:** publicly trusted certificate for `*.blb.example.com` (or equivalent SAN coverage), obtained and renewed through DNS-01
- **LAN resolution:** router or local DNS override so each Belimbing hostname resolves to the correct private IP on the local network
- **Single-machine setup:** all environment hostnames may resolve to the same private IP, with Caddy routing by hostname to the correct instance
- **Multi-host setup:** each hostname resolves to the appropriate private host or the published load-balancer or service VIP address
- **Bootstrap fallback:** raw IP may be shown for first-run troubleshooting only, not as the normal phone-browser URL

This is the current leading proposal because it best satisfies the stated goals:

- **easy phone UX** — users open a normal HTTPS URL
- **trusted HTTPS** — camera and other secure-origin features work without manual certificate installation on phones
- **low cost** — the main unavoidable paid component is the domain name; the runtime stack stays open-source
- **scalable naming** — the same pattern works from one machine to larger topologies
- **clear environment boundaries** — each environment is a distinct origin and bookmarkable destination

This section is intentionally a proposal, not a frozen decision. We still need to validate whether router or local DNS override is simple enough for non-technical licensees, and whether Belimbing should package additional guidance or automation around that step.

## Proposal 2 — Alternative for Licensees Without a Registered Domain

For licensees who do **not** have a registered domain and do not want to buy one yet, Belimbing should also document a **private-network HTTPS path built on Tailscale**. This is especially relevant for small self-hosted deployments on a single machine where the main goal is reliable phone-browser access for known operators rather than public internet reachability.

Under this proposal:

- **Tailscale provides the private network and device identity layer**
- **Tailscale HTTPS / Serve provides the trusted remote browser entry point**
- **Belimbing remains behind the private network rather than being exposed directly to the public internet**
- **Belimbing login and environment role rules still handle application access after the network path is established**

This alternative exists because the registered-domain proposal solves certificate trust elegantly, but it introduces a domain purchase and DNS setup step that some very small licensees may see as unnecessary friction. Tailscale offers a lower-friction path for private phone access without asking each phone user to install and trust a private CA manually, and without requiring the licensee to register a domain first.

### When this alternative fits best

This direction is most suitable when:

- the deployment is private to one owner or a small internal team
- the users who need phone access are known in advance
- the goal is browser access from outside the Wi-Fi network without exposing Belimbing directly to the public internet
- the licensee wants to avoid the operational burden of domain registration and DNS setup in the first iteration

### Proposed shape

For the no-domain path, the deployment pattern is:

- **Belimbing app runtime:** FrankenPHP with Caddy
- **Network reachability:** Tailscale
- **Remote browser path:** Tailscale Serve over HTTPS
- **Trust model:** only approved devices and users on the private tailnet may reach the Belimbing entry URL

For example, a single-computer deployment could run multiple Belimbing instances on separate local ports:

- dev → local port A
- staging → local port B
- production → local port C

Tailscale Serve (or an equivalent Tailscale HTTPS publishing layer) can then present those instances to approved phones over a trusted private HTTPS address space without a separately registered domain. Each environment still keeps its own distinct URL.

### WSL2-specific note

If Belimbing runs inside **WSL2 on Windows**, the safer default is to install **Tailscale on the Windows host**, not inside WSL2, and treat WSL2 as the application runtime behind Windows networking. This avoids the extra complexity of running Tailscale simultaneously on both Windows and WSL2 and fits the fact that external traffic reaches the Windows side first before being forwarded to the Belimbing services inside WSL2.

In that shape:

- **Windows host:** Tailscale node and remote-access edge
- **WSL2:** FrankenPHP/Caddy and the Belimbing instances
- **Windows-to-WSL routing:** host reachability to the published WSL2 ports

This should be documented explicitly because many Belimbing developers and some licensees may run the product on Windows with WSL2 during early deployments.

### Strengths of the no-domain Tailscale path

- **no domain purchase required**
- **trusted HTTPS for approved devices** without private CA hand-installation on each phone
- **works away from the local network** as long as the operator is on the private Tailscale network
- **keeps Belimbing off the open internet by default**
- **fits the WAN-through-private-network direction** already preferred in this draft

### Tradeoffs and limits

This path is strong for private operator access, but it is not the same as a normal public web deployment. The main tradeoffs are:

- every operator device needs Tailscale installed and authenticated
- this is better for private staff access than for customer-facing public access
- it introduces an extra infrastructure dependency outside the core web stack
- the URL shape and identity model are tied to the private network solution rather than to the licensee's own public domain

### Position in the overall decision model

The draft should therefore treat the access story as having **two viable reference patterns** for small deployments:

1. **Registered-domain pattern** — preferred default when the licensee has or is willing to buy a domain and wants the most web-native long-term setup
2. **No-domain private-network pattern** — recommended fallback when the deployment is private, operator-only, and the licensee wants remote phone-browser access without domain registration

This framing keeps the current preferred direction intact while acknowledging that a Tailscale-based approach may be the most adoptable first step for some licensees, especially solo operators and very small teams.

## Phases

### Phase 1 — Define the LAN access model

- [x] Define the LAN access contract in product terms
- [x] Decide what the ideal LAN phone experience is: join the same Wi-Fi, open a stable local browser address, then log in
- [x] Compare LAN address strategies at a high level: raw IP, local hostname discovery, and local DNS/gateway naming
- [x] Decide whether raw IP is only a bootstrap fallback or an acceptable primary UX
- [x] Define the mandatory HTTPS and certificate-trust story for LAN phone access
- [x] Draft the proposed domain and hostname pattern for environments on LAN
- [x] Draft the preferred DNS resolution model for LAN and note why public-DNS-to-private-IP is not the default
- [x] Confirm each environment instance has its own LAN URL (distinct origin); treat bookmarks and browser history as sufficient disambiguation—no separate mobile-only “which environment” UX requirement
- [x] Confirm that Belimbing login and environment role rules remain the only application-level gate on LAN
- [ ] Validate the proposal against real-world router, phone, and DNS edge cases before treating it as the default

### Phase 2 — Turn LAN into an adoptable Belimbing story

- [ ] Describe how the deployment pattern applies for use case 1 (single computer: dev + production instances)
- [ ] Describe how the deployment pattern applies for use case 2 (two computers: dev + staging on one, production on another)
- [ ] Describe how the deployment pattern applies for use case 3 (three+ computers: e.g. dedicated DB, load-balanced app tier; operators use the published HTTPS entry URL)
- [ ] Identify which parts of the LAN story belong to Belimbing itself and which parts belong to surrounding infrastructure guidance
- [ ] Identify the minimum dependency set we expect Belimbing to install or orchestrate for LAN access: Caddy/FrankenPHP, a real domain, certificate automation, and local DNS override support
- [ ] Identify where LAN setup automation belongs: base setup script, companion scripts, Lara-guided steps, or a deliberate combination
- [ ] Decide whether Belimbing should provide phone-friendly discovery aids such as surfaced URLs or QR-based handoff

### Phase 3 — Extend the LAN story to WAN through VPN

- [ ] Define the WAN access contract in product terms
- [ ] Confirm the responsibility split between VPN reachability and Belimbing login/roles
- [ ] Identify the open-source VPN solution family that best fits Belimbing's goals
- [ ] Evaluate the VPN direction explicitly on setup simplicity for non-technical licensees
- [ ] Evaluate the VPN direction explicitly on dependency burden and automation path (`scripts/setup.sh`, companion scripts, Lara assistance, or a combination)
- [ ] Evaluate the VPN direction explicitly on security
- [ ] Decide the default story for solo operators and small LAN-first companies with remote access needs

This draft now has a clearer sequence: first make LAN browser access solid and understandable, then extend that same Belimbing experience to WAN through VPN before the normal login flow.