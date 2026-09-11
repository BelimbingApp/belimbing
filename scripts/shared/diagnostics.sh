#!/usr/bin/env bash
# scripts/shared/diagnostics.sh
# Reachability probes used to tell "the app booted" apart from "the URL we
# printed actually answers". Sourced after shared/validation.sh, which provides
# command_exists and is_wsl2.

# WSL2 networking mode: mirrored | nat | unknown.
# In mirrored mode the distro shares the Windows network namespace, so
# 127.0.0.1 on the Windows side reaches a listener inside WSL. Under NAT it
# does not, and the Windows hosts file needs the WSL2 IP instead.
wsl2_networking_mode() {
    if ! is_wsl2; then
        printf '%s\n' 'unknown'
        return 0
    fi

    if command_exists wslinfo; then
        local mode
        mode=$(wslinfo --networking-mode 2>/dev/null || true)
        case "$mode" in
            mirrored|nat)
                printf '%s\n' "$mode"
                return 0
                ;;
        esac
    fi

    # Older WSL builds have no wslinfo. NAT is identifiable by the private /20
    # address WSL assigns eth0; mirrored mode reuses the host's own addresses.
    if ip -4 addr show eth0 2>/dev/null | grep -qE 'inet 172\.(1[6-9]|2[0-9]|3[01])\.'; then
        printf '%s\n' 'nat'
        return 0
    fi

    printf '%s\n' 'unknown'
    return 0
}

# systemd is opt-in on WSL2 (/etc/wsl.conf), and without it the caddy.service
# that setup installs never starts.
systemd_is_available() {
    [[ -d /run/systemd/system ]]
}

# True when a GUI browser could open the app from inside Linux, which makes the
# Windows hosts file irrelevant. xdg-open and sensible-browser do NOT count:
# both ship on stock Ubuntu WSL images and resolve to the Windows browser (or
# to nothing), so treating them as a local browser hides the hosts-file check
# from exactly the WSL users who need it.
linux_gui_browser_available() {
    [[ -n "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ]] || return 1

    command_exists chromium-browser ||
        command_exists chromium ||
        command_exists google-chrome ||
        command_exists firefox
}

url_is_reachable() {
    local url=$1
    local connect_timeout=${2:-3}

    curl -s -f -k \
        --connect-timeout "$connect_timeout" \
        --max-time "$((connect_timeout * 2))" \
        "$url" >/dev/null 2>&1
}

port_has_listener() {
    local port=$1

    if command_exists ss; then
        ss -ltn 2>/dev/null | grep -qE "[:.]${port}[[:space:]]"
        return $?
    fi

    if command_exists lsof; then
        lsof -Pi :"$port" -sTCP:LISTEN -t >/dev/null 2>&1
        return $?
    fi

    return 1
}

# Whether the FrankenPHP binary may bind privileged ports without root.
frankenphp_can_bind_privileged_ports() {
    local binary=$1

    [[ -n "$binary" ]] && [[ -x "$binary" ]] || return 1
    command_exists getcap || return 1

    getcap "$binary" 2>/dev/null | grep -q 'cap_net_bind_service'
}
