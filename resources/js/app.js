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

        // Set while stepping back to this page after a back or forward the person chose to stay for.
        returning: false,

        // This page's place in the session history, where the browser tells it (the Navigation API).
        position: window.navigation?.currentEntry?.index ?? null,

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
                event.preventDefault()

                return
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
         * How far the browser has moved from this page's history entry: -1
         * for back, 1 for forward. Without the Navigation API, it takes the
         * move to be back, by far the more common.
         */
        stepsAway() {
            let steps = (window.navigation?.currentEntry?.index ?? NaN) - this.position

            return this.position === null || ! Number.isInteger(steps) || steps === 0 ? -1 : steps
        },
    }))
})
