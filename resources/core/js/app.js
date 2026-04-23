// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

const blbDateTimeOptions = (format, includeSeconds = false) => {
    const timeOptions = includeSeconds
        ? { hour: '2-digit', minute: '2-digit', second: '2-digit' }
        : { hour: '2-digit', minute: '2-digit' }

    return {
        datetime: {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            ...timeOptions,
        },
        date: {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        },
        time: timeOptions,
    }[format] ?? {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        ...timeOptions,
    }
}

const blbResolveFormatter = ({
    locale,
    format,
    mode = 'local',
    companyTimezone = null,
    includeSeconds = false,
}) => {
    const options = blbDateTimeOptions(format, includeSeconds)

    if (mode === 'company' && companyTimezone) {
        options.timeZone = companyTimezone
    }

    if (mode === 'utc') {
        options.timeZone = 'UTC'
    }

    return new Intl.DateTimeFormat(locale || undefined, options)
}

globalThis.blbFormatDateTimeElement = (element, config = {}) => {
    if (!element) {
        return
    }

    const rawText = config.rawText
        ?? element.dataset.rawText
        ?? element.textContent
        ?? ''

    if (!element.dataset.rawText) {
        element.dataset.rawText = rawText
    }

    const mode = config.mode ?? element.dataset.timezoneMode ?? 'local'

    if (mode === 'utc') {
        element.textContent = rawText

        return
    }

    const iso = config.iso ?? element.getAttribute('datetime')

    if (!iso) {
        element.textContent = rawText

        return
    }

    const date = new Date(iso)

    if (Number.isNaN(date.getTime())) {
        element.textContent = rawText

        return
    }

    const formatter = blbResolveFormatter({
        locale: config.locale ?? element.dataset.locale,
        format: config.format ?? element.dataset.format ?? 'datetime',
        mode,
        companyTimezone: config.companyTimezone ?? element.dataset.companyTimezone ?? null,
        includeSeconds: config.includeSeconds ?? element.dataset.includeSeconds === 'true',
    })

    element.textContent = formatter.format(date)
}

globalThis.blbMountDateTimeElement = (element, getConfig = () => ({})) => {
    if (!element) {
        return
    }

    let observer = null

    const apply = () => {
        observer?.disconnect()
        globalThis.blbFormatDateTimeElement(element, getConfig() || {})
        observer?.observe(element, {
            characterData: true,
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['datetime', 'data-raw-text', 'data-format', 'data-locale', 'data-company-timezone'],
        })
    }

    element._blbDateTimeUnmount?.()

    observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'characterData' || mutation.type === 'childList' || mutation.type === 'attributes') {
                apply()
                break
            }
        }
    })

    element._blbDateTimeUnmount = () => observer.disconnect()

    apply()
}

globalThis.blbFormatDateTimeMatches = (text, config = {}) => {
    const source = typeof text === 'string' ? text : String(text ?? '')
    const mode = config.mode ?? 'local'

    if (mode === 'utc') {
        return source
    }

    const formatter = blbResolveFormatter({
        locale: config.locale,
        format: config.format ?? 'datetime',
        mode,
        companyTimezone: config.companyTimezone ?? null,
        includeSeconds: config.includeSeconds ?? false,
    })

    return source.replaceAll(/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(?:Z|[+-]\d{2}:\d{2})?/g, (match) => {
        try {
            const hasTimezone = /(?:Z|[+-]\d{2}:\d{2})$/.test(match)
            let iso = match
            if (!hasTimezone) {
                if (match.includes('T')) {
                    iso = `${match}Z`
                } else {
                    iso = `${match.replace(' ', 'T')}Z`
                }
            }
            const date = new Date(iso)

            if (Number.isNaN(date.getTime())) {
                return match
            }

            return formatter.format(date)
        } catch {
            return match
        }
    })
}

// Alpine.js - only initialize if not already loaded by Livewire
if (!globalThis.Alpine) {
    try {
        const module = await import('alpinejs')

        globalThis.Alpine = module.default
        globalThis.Alpine.start()
    } catch (error) {
        console.error('Failed to load Alpine.js.', error)
    }
}
