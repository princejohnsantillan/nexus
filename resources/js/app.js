/**
 * Where the current session history entry sits, counting the entries this
 * page load has visited. Each entry is stamped with its place in its state
 * the first time it is current, so a back or forward between pages
 * reached with wire:navigate can tell how far it moved, in either direction.
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

document.addEventListener('livewire:navigated', historyPosition)

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

        // This page's place in the session history (historyPosition()).
        position: null,

        init() {
            this.position = historyPosition()
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

            if (this.returning) {
                this.returning = false

                if (event.detail.history && this.stepsAway() === 0) {
                    event.preventDefault()

                    return
                }
            }

            if (! this.unsaved()) {
                return
            }

            event.preventDefault()

            // A back or forward has already moved the browser to the other page's entry: return to this one.
            let steps = event.detail.history ? this.stepsAway() : 0

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
         * back, 1 for one forward, 0 for this page's own. An entry without a
         * place, which this page load never made current, is taken to be one
         * back.
         */
        stepsAway() {
            let position = window.history.state?.nexusHistoryPosition

            return Number.isInteger(position) ? position - this.position : -1
        },
    }))
})
