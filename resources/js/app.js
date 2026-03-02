// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

import './echo'
import htmx from 'htmx.org'
import Alpine from 'alpinejs'

window.htmx = htmx
window.Alpine = Alpine

// Pass CSRF token with every HTMX request
document.addEventListener('htmx:configRequest', (event) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content
    if (token) {
        event.detail.headers['X-CSRF-TOKEN'] = token
    }
})

Alpine.start()
