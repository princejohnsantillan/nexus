/**
 * Where the current session history entry sits, counting the entries this
 * page load has visited. Each entry is stamped with its place in its state
 * the first time it is current (on load, after each wire:navigate, and when
 * a link to a #fragment adds one), so a back or forward between them can
 * tell how far it moved, in either direction.
 */
let lastHistoryPosition = 0

function historyPosition() {
    let position = window.history.state?.nexusHistoryPosition

    if (! Number.isInteger(position)) {
        position = lastHistoryPosition + 1
        window.history.replaceState({ ...window.history.state, nexusHistoryPosition: position }, '')
    }

    lastHistoryPosition = position

    return position
}

historyPosition()
document.addEventListener('livewire:navigated', historyPosition)
window.addEventListener('hashchange', historyPosition)

/**
 * Asks before leaving a page that has unsaved changes. Put it on a Livewire
 * page's root with the name of the Flux modal that asks, and give the
 * component a `hasUnsavedChanges` property that is true while something is
 * unsaved:
 *
 *     <div x-data="unsavedChangesGuard('leave-star')" x-bind="guard">
 *
 * Leaving through wire:navigate (links, redirects, and the browser's back
 * and forward buttons between such pages) opens the modal, whose leave()
 * goes on to where the person was going. Closing or reloading the tab, or a
 * link that loads a whole page, gets the browser's own prompt. Changes not
 * yet sent to the server count too.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('unsavedChangesGuard', (modal) => ({
        // Where the person was going when the modal opened: the URL, and how many history entries away it is (0 for a link).
        destination: null,

        // Set once the person chose to leave, so the navigation goes ahead.
        leaving: false,

        // Set while stepping back to this page's entry after a back or forward the person chose to stay for.
        returning: false,

        // This page's place in the session history (historyPosition()), and its index where the browser has the Navigation API.
        position: null,
        index: null,

        init() {
            this.position = historyPosition()
            this.index = window.navigation?.currentEntry?.index ?? null
        },

        guard: {
            ['x-on:livewire:navigate.document'](event) {
                this.ask(event)
            },

            ['x-on:beforeunload.window'](event) {
                if (! this.leaving && this.unsaved()) {
                    event.preventDefault()
                    event.returnValue = true
                }
            },
        },

        unsaved() {
            return this.$wire.hasUnsavedChanges || this.$wire.$dirty()
        },

        ask(event) {
            if (this.leaving) {
                return
            }

            let steps = event.detail.history ? this.stepsAway() : 0

            if (this.returning) {
                this.returning = false

                if (event.detail.history && steps === 0) {
                    event.preventDefault()

                    return
                }
            }

            // A back or forward by an unknown distance couldn't be undone, so it goes ahead rather than leave this page under another URL.
            if (steps === null || ! this.unsaved()) {
                return
            }

            event.preventDefault()

            // A back or forward has already moved the browser to the other page's entry: return to this one.
            if (steps !== 0) {
                this.returning = true
                window.history.go(-steps)
            }

            this.destination = { url: event.detail.url, steps }
            this.$flux.modal(modal).show()
        },

        leave() {
            if (this.destination === null) {
                return
            }

            this.leaving = true
            this.$flux.modal(modal).close()

            if (this.destination.steps !== 0) {
                window.history.go(this.destination.steps)
            } else {
                window.Livewire.navigate(this.destination.url.href)
            }
        },

        /**
         * How far the current history entry is from this page's: -1 for one
         * back, 2 for two forward, 0 for this page's own. For an entry
         * without a place it asks the Navigation API, and is null where the
         * browser hasn't one.
         */
        stepsAway() {
            let position = window.history.state?.nexusHistoryPosition

            if (Number.isInteger(position)) {
                return position - this.position
            }

            let index = window.navigation?.currentEntry?.index

            return Number.isInteger(index) && this.index !== null ? index - this.index : null
        },
    }))
})
